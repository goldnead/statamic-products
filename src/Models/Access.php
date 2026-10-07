<?php

namespace Goldnead\StatamicProducts\Models;

use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicProducts\Support\AccessGraph;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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

    /** Der Schluessel des privaten Links an einem Termin-Inhalt, {@see buyerLinks()}. */
    public const BUYER_URL = 'buyer_url';

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

        // Wie Kennung und Zeilen: ein vergebener Slug hat Datensaetze
        // ausserhalb dieses Addons, die weiter gelten. Auch ein Import oder
        // Tinker loescht ihn nicht.
        static::deleting(function (self $access): void {
            if ($access->hasBeenGranted()) {
                throw ValidationException::withMessages([
                    'handle' => __('statamic-products::messages.access_delete_refused'),
                ]);
            }
        });

        // Die Lese-API und der PackageResolver merken sich alle Zugaenge fuer
        // den Request. Wer im selben Request speichert, liest danach den neuen
        // Stand, nicht den gemerkten.
        static::saved(fn () => AccessGraph::forget());
        static::deleted(fn () => AccessGraph::forget());
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
        // Der Stand in der Datenbank, nicht `getOriginal()`. Zwei Prozesse, die
        // denselben Datensatz halten, haben beide ein Original, und das des
        // langsameren ist veraltet: mit ihm fiele der Zaehler zurueck, und eine
        // neue Zeile bekaeme die Nummer, die der schnellere gerade vergeben hat.
        $stored = $this->exists
            ? DB::table($this->getTable())->where($this->getKeyName(), $this->getKey())->first(['handle', 'credits', 'credit_lines_issued'])
            : null;

        $storedIssued = (int) ($stored->credit_lines_issued ?? 0);
        $storedLines = [];

        foreach ((array) json_decode((string) ($stored->credits ?? '[]'), true) as $line) {
            if (is_array($line) && is_int($line['line'] ?? null)) {
                $storedLines[$line['line']] = $line;
            }
        }

        $this->guardGranted($stored, $storedLines);

        $credits = $this->credits;

        if (! is_array($credits)) {
            $this->credit_lines_issued = max($storedIssued, (int) ($this->credit_lines_issued ?? 0));

            return;
        }

        $issued = max($storedIssued, (int) ($this->credit_lines_issued ?? 0));
        $seen = [];

        foreach ($credits as $line) {
            if (! is_array($line) || ! is_int($line['line'] ?? null)) {
                continue;
            }

            $number = $line['line'];

            if (in_array($number, $seen, true)) {
                throw ValidationException::withMessages([
                    'credits' => __('statamic-products::messages.credit_line_duplicate', ['line' => $number]),
                ]);
            }

            // Eine Nummer unter dem Zaehler, die heute an keiner Zeile steht,
            // wurde schon einmal vergeben und wieder geloescht. Sie gehoerte zu
            // einer anderen Zeile, und ihr Schluessel kann schon unterwegs sein.
            if ($stored !== null && $number < $storedIssued && ! array_key_exists($number, $storedLines)) {
                throw ValidationException::withMessages([
                    'credits' => __('statamic-products::messages.credit_line_unknown'),
                ]);
            }

            $seen[] = $number;
            $issued = max($issued, $number + 1);
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
     * Was nach einer Vergabe nicht mehr geht, gleich auf welchem Weg.
     *
     * Der Controller sagt es dem Formular mit besseren Worten. Hier steht es
     * ein zweites Mal, weil Uebernahme und Import am Formular vorbei schreiben.
     *
     * @param  array<int, array<string, mixed>>  $storedLines
     */
    protected function guardGranted(?object $stored, array $storedLines): void
    {
        if ($stored === null || ! $this->isDirty(['handle', 'credits'])) {
            return;
        }

        if (! self::slugGranted((string) $stored->handle)) {
            return;
        }

        if ($this->handle !== $stored->handle) {
            throw ValidationException::withMessages([
                'handle' => __('statamic-products::messages.access_handle_frozen'),
            ]);
        }

        $now = [];

        foreach ((array) ($this->credits ?? []) as $line) {
            if (is_array($line) && is_int($line['line'] ?? null)) {
                $now[$line['line']] = $line;
            }
        }

        $removed = array_values(array_diff(array_keys($storedLines), array_keys($now)));

        if ($removed !== []) {
            throw ValidationException::withMessages([
                'credits' => trans_choice('statamic-products::messages.credit_line_not_deletable', count($removed), [
                    'lines' => implode(', ', array_map(fn (int $n) => $n + 1, $removed)),
                ]),
            ]);
        }

        foreach ($storedLines as $number => $line) {
            if (($line['ended_at'] ?? null) !== null && ($now[$number]['ended_at'] ?? null) === null) {
                throw ValidationException::withMessages([
                    'credits' => __('statamic-products::messages.credit_line_reopen'),
                ]);
            }
        }
    }

    /**
     * Ein Fingerabdruck des gespeicherten Stands.
     *
     * Das Formular schickt ihn beim Speichern zurueck. Passt er nicht mehr, hat
     * jemand anderes inzwischen gespeichert, und dessen Stand wird nicht still
     * ueberschrieben. Ueber den Inhalt und nicht ueber `updated_at`, weil zwei
     * Speichervorgaenge in derselben Sekunde denselben Zeitstempel tragen.
     */
    public function version(): string
    {
        return sha1((string) json_encode($this->getRawOriginal()));
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
     * Die Links fuer Kaeufer an den eigenen Terminen, nach Termin-`ref`.
     *
     * Ein Termin (`event`) kann einen privaten Link tragen, den nur sieht, wer
     * den Zugang haelt, etwa den Teilnahme-Link eines Webinars. statamic-events
     * gated nichts, `online_url` am Termin ist oeffentlich; deshalb steht er hier
     * und **nie** in `contentItems()`. Gelesen wird er nur ueber
     * `Accesses::buyerLinks()` / `buyerLinksFor()`.
     *
     * Nur http(s)-Adressen; alles andere wird hier verworfen, auch wenn es am
     * Formular vorbei in die Spalte kam.
     *
     * @return array<string, string>
     */
    public function buyerLinks(): array
    {
        $links = [];

        foreach ((array) ($this->contents ?? []) as $item) {
            if (! is_array($item) || ($item['kind'] ?? null) !== ContentKinds::EVENT) {
                continue;
            }

            $ref = is_string($item['ref'] ?? null) ? trim($item['ref']) : '';
            $url = self::safeBuyerUrl($item[self::BUYER_URL] ?? null);

            if ($ref !== '' && $url !== null && ! isset($links[$ref])) {
                $links[$ref] = $url;
            }
        }

        return $links;
    }

    /** Eine http(s)-Adresse, getrimmt, sonst null. */
    public static function safeBuyerUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || ! preg_match('#^https?://[^\s/?\#]+#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
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
        return self::slugGranted((string) $this->getOriginal('handle', $this->handle));
    }

    /** Dasselbe fuer einen Slug, ohne Datensatz. */
    public static function slugGranted(string $handle): bool
    {
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

        // Jeder Knoten wird einmal betreten. Ohne Merkliste kostet eine Kette aus
        // Rauten (A zeigt auf B und C, beide auf D, …) zwei hoch Tiefe Wege.
        $visited = [$handle => true];

        $walk = function (string $node, array $path) use (&$walk, &$visited, $graph, $handle): ?array {
            foreach ($graph[$node] ?? [] as $next) {
                if ($next === $handle) {
                    return [...$path, $next];
                }

                if (isset($visited[$next])) {
                    continue;
                }

                $visited[$next] = true;

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
