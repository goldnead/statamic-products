<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicProducts\Models\Access;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Meldet alle Zugaenge mit Namen im Katalog von statamic-entitlements an.
 *
 * entitlements kennt keine Produkte. Sein Vergabeformular, die Limits und der
 * Abschnitt „Zugaenge" auf der User-Seite fragten deshalb nach einem Slug zum
 * Eintippen. Mit dieser Quelle bieten sie eine Auswahl mit Klarnamen an
 * (Adrian, 03.10.2026: „dafuer muss ich ja die ganzen slugs und IDs auswendig
 * kennen").
 *
 * **Alle Zugaenge, auch inaktive.** Der Katalog benennt auch bestehende
 * Vergaben, und ein ausgemusterter Zugang gilt fuer Altkaeufer weiter
 * (`AccessGraph`). Fehlte er hier, stuende jede seiner Vergaben als „nicht im
 * Katalog" da. Inaktive tragen den Zusatz im Namen, damit niemand sie aus
 * Versehen neu vergibt.
 *
 * **Ueber alle Marken.** Eine Vergabe kennt keine Marke, und Slugs sind ueber
 * alle Marken eindeutig. Mit mehreren Marken steht der Markenname als Gruppe
 * dabei.
 *
 * Kennt entitlements nicht als Klasse: angemeldet wird per Container-Tag
 * (`ServiceProvider::registerGrantableAccesses()`), und entitlements fragt
 * jedes markierte Objekt nach `grantableProducts()`. Ohne entitlements fragt
 * niemand.
 */
final class GrantableAccesses
{
    /**
     * @return array<string, array{label: string, group: string|null}>
     */
    public function grantableProducts(): array
    {
        // Vor `php artisan migrate`: kein Zugang, kein Eintrag im Log bei
        // jedem Formular. Die Tabelle fehlt dort nicht aus Versehen.
        if (! Schema::hasTable('product_accesses')) {
            return [];
        }

        $brands = $this->brandNames();
        $entries = [];

        foreach (Access::query()->orderBy('name')->orderBy('handle')->get(['handle', 'name', 'active', 'brand_id']) as $access) {
            $name = (string) ($access->name ?: $access->handle);

            $entries[(string) $access->handle] = [
                'label' => $access->active
                    ? $name
                    : __('statamic-products::messages.access_catalog_inactive', ['name' => $name]),
                'group' => $brands[$access->brand_id] ?? null,
            ];
        }

        return $entries;
    }

    /**
     * Markennamen nach ID, nur wenn es mehrere Marken gibt. Mit einer Marke
     * waere der Name an jeder Zeile nur Rauschen.
     *
     * @return array<int, string>
     */
    private function brandNames(): array
    {
        try {
            if (! Brands::multiBrand() || ! class_exists('\Goldnead\BrandContext\Models\Brand')) {
                return [];
            }

            return Brand::query()->pluck('name', 'id')
                ->map(fn ($name) => (string) $name)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
