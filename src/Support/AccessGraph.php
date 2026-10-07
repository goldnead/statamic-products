<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\StatamicProducts\Models\Access;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry as CoreEntry;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Alle Zugaenge, einmal je Request gelesen, und was jeder abdeckt.
 *
 * Gebunden als `scoped`: Octane und Queue-Worker werfen es zwischen zwei
 * Requests oder Jobs weg, und das Modell wirft es nach jedem Speichern und
 * Loeschen weg (`Access::booted()`), damit ein Request, der einen Zugang
 * aendert, danach nicht mit dem alten Stand antwortet.
 *
 * **Die Regeln, an einer Stelle**, fuer `Accesses` und den PackageResolver:
 *
 * - Ein Zugang deckt seine Inhalte ab und, ueber Inhalte der Art `access`,
 *   die Inhalte der verschachtelten Zugaenge, beliebig tief.
 * - Was abgedeckt wird, ist ein **Schluessel**: der `ref` jedes Inhalts, bei
 *   `access` also der Slug des inneren Zugangs. Bei `course` zusaetzlich der
 *   Produkt-Slug des Kurses, so wie statamic-courses ihn fragt (Feld
 *   `product`, sonst der Slug des Eintrags).
 * - **`active` zaehlt nicht.** Es steuert nur, ob ein Zugang neu vergeben oder
 *   angeboten wird (Picker, neue Verkaeufe). Ein ausgemusterter Zugang wird
 *   aufgeloest wie ein aktiver, direkt und verschachtelt: „Bestehende
 *   Vergaben gelten weiter", sagt das CP am Schalter, und Altkaeufer eines
 *   ausgemusterten Angebots behalten, was sie gekauft haben. Entzogen wird mit
 *   `revoke()` in statamic-entitlements, nicht hier.
 * - Ein Verweis auf einen Zugang ohne Datensatz deckt dessen Slug ab und endet.
 * - Kreise enden: jeder Zugang wird einmal betreten.
 * - Ueber alle Marken. Slugs sind ueber alle Marken eindeutig, und eine
 *   Vergabe kennt keine Marke; `cycleThrough()` liest genauso.
 */
final class AccessGraph
{
    /** @var array<string, Access> */
    private array $accesses = [];

    /** @var array<string, list<string>> Kurs-Eintrags-ID => weitere Schluessel */
    private array $courseKeys = [];

    /** @var array<string, list<string>> */
    private array $covered = [];

    /** @var array<string, list<string>>|null */
    private ?array $containing = null;

    public function __construct()
    {
        foreach (Access::query()->orderBy('id')->get() as $access) {
            $this->accesses[(string) $access->handle] = $access;
        }

        $this->courseKeys = $this->loadCourseKeys();
    }

    /** Das Gemerkte wegwerfen; der naechste Zugriff liest neu. */
    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }

    public function find(string $handle): ?Access
    {
        return $this->accesses[$handle] ?? null;
    }

    /**
     * Jeder Schluessel, den eine Vergabe auf `$handle` abdeckt, ohne `$handle`.
     *
     * @return list<string>
     */
    public function covered(string $handle): array
    {
        if (array_key_exists($handle, $this->covered)) {
            return $this->covered[$handle];
        }

        $keys = [];

        foreach ($this->walk($handle) as $item) {
            foreach ($this->keysOf($item) as $key) {
                $keys[$key] = true;
            }
        }

        unset($keys[$handle]);

        return $this->covered[$handle] = array_keys($keys);
    }

    /**
     * Die Zugaenge, deren Vergabe `$key` abdeckt, ohne `$key`; auch inaktive.
     *
     * @return list<string>
     */
    public function containing(string $key): array
    {
        if ($this->containing === null) {
            $this->containing = [];

            foreach (array_keys($this->accesses) as $handle) {
                foreach ($this->covered((string) $handle) as $covered) {
                    $this->containing[$covered][] = (string) $handle;
                }
            }
        }

        return $this->containing[$key] ?? [];
    }

    /**
     * Die Inhalte einer Art, ueber alle Ebenen, in Reihenfolge, je `ref` einmal.
     *
     * @return list<array{kind: string, ref: string, label: string|null}>
     */
    public function contentsOf(string $handle, string $kind): array
    {
        $items = [];

        foreach ($this->walk($handle) as $item) {
            if ($item['kind'] === $kind && ! isset($items[$item['ref']])) {
                $items[$item['ref']] = $item;
            }
        }

        return array_values($items);
    }

    /**
     * Die Links fuer Kaeufer an den Terminen, die eine Vergabe auf `$handle`
     * erreicht, in Reihenfolge, je Termin einmal (der erste Link gewinnt).
     *
     * Nur fuer jemanden, der `$handle` haelt. Diese Klasse weiss nicht, wer
     * fragt; das prueft `Accesses::buyerLinksFor()` oder die Website.
     *
     * @return list<array{access: string, ref: string, label: string|null, url: string}>
     */
    public function buyerLinks(string $handle): array
    {
        $links = [];
        $urls = [];

        foreach ($this->walkWithOwner($handle) as [$owner, $item]) {
            if ($item['kind'] !== ContentKinds::EVENT || isset($links[$item['ref']])) {
                continue;
            }

            $url = ($urls[$owner->handle] ??= $owner->buyerLinks())[$item['ref']] ?? null;

            if ($url !== null) {
                $links[$item['ref']] = [
                    'access' => (string) $owner->handle,
                    'ref' => $item['ref'],
                    'label' => $item['label'],
                    'url' => $url,
                ];
            }
        }

        return array_values($links);
    }

    /**
     * Alle Inhalte, die eine Vergabe auf `$handle` erreicht, tiefe zuerst.
     *
     * @return list<array{kind: string, ref: string, label: string|null}>
     */
    private function walk(string $handle): array
    {
        return array_map(static fn (array $pair): array => $pair[1], $this->walkWithOwner($handle));
    }

    /**
     * Wie `walk()`, jeder Inhalt mit dem Zugang, an dem er steht.
     *
     * @return list<array{0: Access, 1: array{kind: string, ref: string, label: string|null}}>
     */
    private function walkWithOwner(string $handle): array
    {
        $items = [];
        $visited = [];

        $visit = function (string $node) use (&$visit, &$items, &$visited): void {
            $access = $this->accesses[$node] ?? null;

            // `active` zaehlt hier nicht, siehe Klassenkommentar.
            if ($access === null || isset($visited[$node])) {
                return;
            }

            $visited[$node] = true;

            foreach ($access->contentItems() as $item) {
                $items[] = [$access, $item];

                if ($item['kind'] === ContentKinds::ACCESS) {
                    $visit($item['ref']);
                }
            }
        };

        $visit($handle);

        return $items;
    }

    /**
     * @param  array{kind: string, ref: string, label: string|null}  $item
     * @return list<string>
     */
    private function keysOf(array $item): array
    {
        if ($item['kind'] === ContentKinds::COURSE) {
            return [$item['ref'], ...($this->courseKeys[$item['ref']] ?? [])];
        }

        return [$item['ref']];
    }

    /**
     * Die Produkt-Slugs der verwiesenen Kurse, einmal je Kurs und Request.
     *
     * statamic-courses fragt nach `product`, und nur wenn das leer ist, nach
     * dem Slug des Eintrags (`CourseRepository`). Ein Kurs, der sich nicht
     * lesen laesst, deckt nur seine ID ab: das sperrt im Zweifel, es oeffnet
     * nichts.
     *
     * `Entry::find()` je ID und kein `whereIn`: Eintraege liegen im Stache,
     * also im Speicher, und der Query-Builder-Vertrag von Statamic ist leer
     * (siehe `RefTarget::prime()`).
     *
     * @return array<string, list<string>>
     */
    private function loadCourseKeys(): array
    {
        $ids = [];

        foreach ($this->accesses as $access) {
            foreach ($access->contentItems() as $item) {
                if ($item['kind'] === ContentKinds::COURSE) {
                    $ids[$item['ref']] = true;
                }
            }
        }

        $collection = (string) config('courses.collections.courses', 'courses');
        $keys = [];

        foreach (array_keys($ids) as $id) {
            try {
                $entry = Entry::find((string) $id);
            } catch (Throwable $e) {
                Log::warning('statamic-products: could not read a course an access points at; it is covered by its entry id only.', [
                    'ref' => $id,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $entry instanceof CoreEntry || $entry->collectionHandle() !== $collection) {
                continue;
            }

            $product = trim((string) ($entry->get('product') ?? ''));
            $slug = $product !== '' ? $product : (string) $entry->slug();

            if ($slug !== '') {
                $keys[(string) $id] = [$slug];
            }
        }

        return $keys;
    }
}
