<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\Entitlements\EntitlementManager;

/**
 * Lese-API fuer die Website: was ein Slug freischaltet.
 *
 * ```php
 * $access = Accesses::find('choiraccelerator');
 *
 * $access?->expand();              // jeder Slug, den eine Vergabe abdeckt, transitiv
 * $access?->creditLines();         // Guthabenzeilen, nur dieses Zugangs, ohne beendete
 * $access?->contentsOf('course');  // Inhalte einer Art, ueber alle Ebenen
 *
 * Accesses::buyerLinksFor($user);   // private Termin-Links, nur aus aktiven Vergaben
 * ```
 *
 * Alle Zugaenge werden einmal je Request gelesen (`AccessGraph`), egal wie oft
 * gefragt wird. Ueber alle Marken: eine Vergabe kennt keine Marke.
 */
final class Accesses
{
    /**
     * Der Zugang mit diesem Slug, auch ein inaktiver, sonst null.
     *
     * Auch inaktive: `active` sagt nur, ob er neu vergeben wird. Bestehende
     * Vergaben gelten weiter, also liefern `expand()` und `contentsOf()` auch
     * bei einem ausgemusterten Zugang seinen Inhalt.
     *
     * Das Ergebnis ist ein Schnappschuss: nach einem Speichern `find()` neu
     * aufrufen, ein gehaltenes Objekt sieht die Aenderung nicht. Gelesen wird
     * einmal je Request; ein langlebiger Konsolenprozess (eigener Daemon,
     * Schleife in einem Command) sieht, was andere Prozesse schreiben, erst
     * nach `AccessGraph::forget()`. Queue-Worker und Octane verwerfen ihn
     * zwischen Jobs und Requests selbst.
     *
     * Vor `php artisan migrate` wirft `find()` (fehlende Tabelle); nur der
     * PackageResolver faengt das ab und antwortet „keine Buendel".
     */
    public static function find(string $slug): ?AccessView
    {
        if ($slug === '') {
            return null;
        }

        $graph = app(AccessGraph::class);
        $access = $graph->find($slug);

        return $access === null ? null : new AccessView($access, $graph);
    }

    /**
     * Die Links fuer Kaeufer an Terminen, fuer jemanden, der genau diese Slugs haelt.
     *
     * **Nur gehaltene Slugs uebergeben**, also die aktiven Vergaben der
     * Person, die die Seite sieht. Diese Methode weiss nicht, wer fragt; mit
     * statamic-entitlements nimmt man {@see buyerLinksFor()}, das die Vergaben
     * selbst liest.
     *
     * Verschachtelte Zugaenge zaehlen, `active` nicht (wie ueberall: es steuert
     * nur die Neuvergabe). Je Termin ein Eintrag; fuehren mehrere gehaltene
     * Zugaenge ihn, gewinnt der alphabetisch erste Slug. Die URL ist geprueft
     * (http(s), keine Zeichen, die aus einem `href` ausbrechen), beim Ausgeben
     * trotzdem escapen.
     *
     * @param  iterable<mixed>  $heldSlugs
     * @return list<array{access: string, ref: string, label: string|null, url: string}>
     */
    public static function buyerLinks(iterable $heldSlugs): array
    {
        $graph = app(AccessGraph::class);
        $links = [];
        $slugs = [];

        foreach ($heldSlugs as $slug) {
            if (is_string($slug) && $slug !== '') {
                $slugs[$slug] = true;
            }
        }

        // Feste Reihenfolge: fuehren zwei gehaltene Zugaenge denselben Termin mit
        // verschiedenen Links, gewinnt nicht die zufaellige Reihenfolge der Vergaben.
        $slugs = array_keys($slugs);
        sort($slugs, SORT_STRING);

        foreach ($slugs as $slug) {
            foreach ($graph->buyerLinks($slug) as $link) {
                $links[$link['ref']] ??= $link;
            }
        }

        return array_values($links);
    }

    /**
     * Dasselbe fuer eine Person, mit ihren aktiven Vergaben aus statamic-entitlements.
     *
     * Ohne entitlements, ohne Person oder ohne aktive Vergabe: nichts. Eine
     * entzogene, abgelaufene oder noch unbestaetigte Vergabe zaehlt nicht
     * (`activeProductSlugsFor()`).
     *
     * @return list<array{access: string, ref: string, label: string|null, url: string}>
     */
    public static function buyerLinksFor(mixed $subject): array
    {
        if ($subject === null || ! class_exists(EntitlementManager::class) || ! app()->bound(EntitlementManager::class)) {
            return [];
        }

        return self::buyerLinks(app(EntitlementManager::class)->activeProductSlugsFor($subject));
    }
}
