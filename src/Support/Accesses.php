<?php

namespace Goldnead\StatamicProducts\Support;

/**
 * Lese-API fuer die Website: was ein Slug freischaltet.
 *
 * ```php
 * $access = Accesses::find('choiraccelerator');
 *
 * $access?->expand();              // jeder Slug, den eine Vergabe abdeckt, transitiv
 * $access?->creditLines();         // Guthabenzeilen, nur dieses Zugangs, ohne beendete
 * $access?->contentsOf('course');  // Inhalte einer Art, ueber alle Ebenen
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
}
