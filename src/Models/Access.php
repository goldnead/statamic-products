<?php

namespace Goldnead\StatamicProducts\Models;

use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Was ein Kauf freischaltet.
 *
 * Ein Produkt traegt in `grants` Slugs; ein Zugang ist der Datensatz mit genau
 * diesem Slug als `handle` und fuehrt, was er enthaelt: Inhalte (Kurse,
 * Dateien, Termine, andere Zugaenge, Arten der Website), Guthabenzeilen fuer
 * Sitzungen und ob er den Mitgliederbereich oeffnet.
 *
 * **Er fuehrt, er liefert nicht aus.** Kein Kursplayer, kein Download, keine
 * Gutschrift entsteht hier. Das bleibt bei den Schwester-Addons und der Website;
 * die lesen hier nach.
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string|null $description
 * @property string|null $cover
 * @property bool $active
 * @property int $brand_id
 * @property bool $opens_members_area
 * @property array<array-key, mixed>|null $contents — eine JSON-Spalte, also
 *                                                  steht darin, was hineingeschrieben wurde. `contentItems()` ist die bereinigte Liste.
 * @property array<array-key, mixed>|null $credits — dito, `creditLines()`.
 * @property int $credit_lines_issued
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Access extends Model
{
    public const CREDIT_ONE_TIME = 'one_time';

    public const CREDIT_SUBSCRIPTION = 'subscription';

    protected $table = 'product_accesses';

    protected $guarded = [];

    /** @return list<string> */
    public static function creditKinds(): array
    {
        return [self::CREDIT_ONE_TIME, self::CREDIT_SUBSCRIPTION];
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'brand_id' => 'integer',
            'opens_members_area' => 'boolean',
            'contents' => 'array',
            'credits' => 'array',
            'credit_lines_issued' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $access): void {
            // Dieselbe Regel wie beim Produkt: ohne Marke wird gestempelt, was
            // `Brands` fuer die aktuelle Lage sagt (null ohne Mandanten).
            if ($access->getAttribute('brand_id') === null) {
                $access->setAttribute('brand_id', Brands::stampId());
            }
        });

        // Jede neue Guthabenzeile bekommt hier ihre Nummer, auf jedem Weg: CP,
        // Import, Tinker. Im Modell und nicht im Controller, weil eine Nummer,
        // die nur das Formular vergibt, beim ersten Import fehlt.
        static::saving(function (self $access): void {
            $access->numberCreditLines();
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBrand(Builder $query, ?int $brandId = null): Builder
    {
        return Brands::only($query, $brandId ?? Brands::readerId());
    }

    /**
     * Vergibt `line` an neue Zeilen und haelt den Zaehler.
     *
     * **Eine Nummer wird nie zweimal vergeben.** Sie ist der `<index>` im
     * Idempotenzschluessel (`adg-provision:<ref>:<line>`), mit dem die Website
     * Guthaben gutschreibt. Bekaeme eine neue Zeile die Nummer einer
     * geloeschten, hielte die Website eine spaetere Gutschrift fuer schon
     * passiert, und das Guthaben fehlte, ohne dass es jemand merkt.
     *
     * Eine Zeile, die ihre Nummer schon mitbringt (die Uebernahme setzt den
     * alten Index), behaelt sie; der Zaehler rueckt dann hinter die hoechste.
     */
    protected function numberCreditLines(): void
    {
        $credits = $this->credits;

        if (! is_array($credits)) {
            return;
        }

        $issued = (int) ($this->credit_lines_issued ?? 0);

        foreach ($credits as $line) {
            if (is_array($line) && is_int($line['line'] ?? null)) {
                $issued = max($issued, $line['line'] + 1);
            }
        }

        foreach ($credits as $i => $line) {
            if (! is_array($line)) {
                unset($credits[$i]);

                continue;
            }

            if (! is_int($line['line'] ?? null)) {
                $credits[$i] = ['line' => $issued++] + $line;
            }
        }

        $this->credits = array_values($credits);
        $this->credit_lines_issued = $issued;
    }

    /**
     * Die Inhalte, bereinigt: nur Eintraege mit Art und Verweis, in Reihenfolge.
     *
     * @return list<array{kind: string, ref: string, label: string|null}>
     */
    public function contentItems(): array
    {
        $items = [];

        foreach ((array) ($this->contents ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $kind = is_string($item['kind'] ?? null) ? $item['kind'] : '';
            $ref = is_string($item['ref'] ?? null) ? trim($item['ref']) : '';

            if ($kind === '' || $ref === '') {
                continue;
            }

            $label = $item['label'] ?? null;

            $items[] = ['kind' => $kind, 'ref' => $ref, 'label' => is_string($label) && $label !== '' ? $label : null];
        }

        return $items;
    }

    /**
     * Die Guthabenzeilen, wie sie gespeichert sind, mit fester Nummer.
     *
     * Beendete Zeilen sind dabei: wer eine alte Vergabe nachspielt, braucht
     * auch sie. Ob eine Zeile fuer neue Vergaben gilt, sagt `ended_at`.
     *
     * @return list<array<string, mixed>>
     */
    public function creditLines(): array
    {
        return array_values(array_filter(
            (array) ($this->credits ?? []),
            static fn (mixed $line): bool => is_array($line) && is_int($line['line'] ?? null),
        ));
    }

    /**
     * Ob eine Vergabe in `statamic-entitlements` diesen Slug traegt.
     *
     * Dann ist der Slug auf Datensaetzen, die dieses Addon nicht umschreiben
     * kann, und er bleibt, wie er ist; ebenso jede Guthabenzeile.
     *
     * Ohne die Tabelle gibt es keine Vergaben, also nichts einzufrieren. Ist sie
     * da und laesst sich nicht lesen, gilt der Slug als vergeben: eine
     * voruebergehende Stoerung darf nicht die eine Aenderung freigeben, die
     * sich nicht zuruecknehmen laesst.
     */
    public function hasBeenGranted(): bool
    {
        $handle = (string) $this->getOriginal('handle', $this->handle);

        if ($handle === '') {
            return false;
        }

        try {
            if (! Schema::hasTable('entitlements')) {
                return false;
            }

            return DB::table('entitlements')->where('product_slug', $handle)->exists();
        } catch (Throwable $e) {
            Log::warning('statamic-products: could not check whether an access has been granted; treating it as granted so its handle and credit lines stay put.', [
                'handle' => $handle,
                'exception' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Der Weg im Kreis, falls diese Inhalte einen schlössen, sonst null.
     *
     * Ueber alle Marken: Slugs sind global eindeutig, und eine Vergabe kennt
     * keine Marke. Verweise auf Slugs ohne Datensatz enden einfach dort.
     *
     * @param  list<array{kind: string, ref: string, label?: string|null}>  $contents
     * @return list<string>|null
     */
    public static function cycleThrough(string $handle, array $contents): ?array
    {
        $graph = [];

        foreach (self::query()->get(['handle', 'contents']) as $access) {
            $graph[$access->handle] = self::nestedHandles($access->contentItems());
        }

        $graph[$handle] = self::nestedHandles($contents);

        $walk = function (string $node, array $path) use (&$walk, $graph, $handle): ?array {
            foreach ($graph[$node] ?? [] as $next) {
                if ($next === $handle) {
                    return [...$path, $next];
                }

                if (in_array($next, $path, true)) {
                    continue;
                }

                if ($found = $walk($next, [...$path, $next])) {
                    return $found;
                }
            }

            return null;
        };

        return $walk($handle, [$handle]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return list<string>
     */
    protected static function nestedHandles(array $contents): array
    {
        $handles = [];

        foreach ($contents as $item) {
            if (($item['kind'] ?? null) === ContentKinds::ACCESS && is_string($item['ref'] ?? null) && $item['ref'] !== '') {
                $handles[] = $item['ref'];
            }
        }

        return array_values(array_unique($handles));
    }
}
