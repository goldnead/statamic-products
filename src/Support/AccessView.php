<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\StatamicProducts\Models\Access;

/**
 * Ein Zugang, wie die Website ihn liest. Von `Accesses::find()`.
 *
 * Nur lesend. Wer schreiben will, nimmt `model()`.
 */
final class AccessView
{
    public function __construct(
        private readonly Access $access,
        private readonly AccessGraph $graph,
    ) {}

    public function handle(): string
    {
        return (string) $this->access->handle;
    }

    public function name(): string
    {
        return (string) $this->access->name;
    }

    public function active(): bool
    {
        return (bool) $this->access->active;
    }

    public function opensMembersArea(): bool
    {
        return (bool) $this->access->opens_members_area;
    }

    public function model(): Access
    {
        return $this->access;
    }

    /**
     * Jeder Slug, den eine Vergabe auf diesen Zugang abdeckt, ohne den eigenen.
     *
     * Verschachtelte Zugaenge und der `ref` jedes Inhalts, ueber alle Ebenen;
     * bei Kursen dazu der Produkt-Slug, nach dem statamic-courses fragt. Genau
     * die Umkehrung des PackageResolvers: fuer jeden Slug hier nennt der
     * Resolver diesen Zugang. Inaktiv: leer. Regeln: `AccessGraph`.
     *
     * @return list<string>
     */
    public function expand(): array
    {
        return $this->graph->covered($this->handle());
    }

    /**
     * Die Guthabenzeilen **dieses** Zugangs, nie die verschachtelter.
     *
     * Guthaben gibt es nur fuer den direkt vergebenen Zugang: wer ein Paket
     * kauft, das einen Coaching-Zugang enthaelt, bekommt die Zeilen des Pakets,
     * nicht zusaetzlich die des Coachings. Sonst schriebe eine Verschachtelung
     * still doppeltes Guthaben.
     *
     * Ohne `$includeEnded` nur die, die fuer **neue** Vergaben gelten. Wer eine
     * alte Vergabe nachspielt (Replay, Idempotenzschluessel
     * `<ref>:<line>`), nimmt `includeEnded: true`: eine Zeile, die nach dem
     * Kauf beendet wurde, gehoerte zu diesem Kauf.
     *
     * @return list<array<string, mixed>>
     */
    public function creditLines(bool $includeEnded = false): array
    {
        $lines = $this->access->creditLines();

        if ($includeEnded) {
            return $lines;
        }

        return array_values(array_filter($lines, static fn (array $line): bool => ($line['ended_at'] ?? null) === null));
    }

    /**
     * Die Inhalte einer Art, auch aus verschachtelten aktiven Zugaengen.
     *
     * In Reihenfolge, tiefe zuerst (ein verschachtelter Zugang steht an der
     * Stelle, an der er eingetragen ist), jeder `ref` einmal, an seiner ersten
     * Stelle. Nur die eigenen, ohne Verschachtelung: `model()->contentItems()`.
     *
     * @return list<array{kind: string, ref: string, label: string|null}>
     */
    public function contentsOf(string $kind): array
    {
        return $this->graph->contentsOf($this->handle(), $kind);
    }
}
