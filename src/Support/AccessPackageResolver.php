<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\Entitlements\Contracts\PackageResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bringt statamic-entitlements bei, was ein Zugang enthaelt.
 *
 * entitlements fragt fuer jeden Slug, nach dem jemand Zugriff will: welche
 * anderen Slugs decken ihn ab? Die Antwort sind die aktiven Zugaenge, die ihn
 * enthalten, auch ueber verschachtelte Zugaenge (Regeln: `AccessGraph`).
 *
 * Nur gebunden, wenn entitlements installiert ist, und nur anstelle von dessen
 * `NullPackageResolver`: bindet die Website einen eigenen, gewinnt der
 * (`ServiceProvider::bindPackageResolver()`).
 *
 * **Ohne Zustand.** Der `EntitlementManager` ist ein Singleton und haelt diesen
 * Resolver so lange wie der Prozess; das Gemerkte liegt deshalb im
 * `AccessGraph`, der je Request neu entsteht.
 */
class AccessPackageResolver implements PackageResolver
{
    /** @return list<string> */
    public function packagesContaining(string $productSlug): array
    {
        if ($productSlug === '') {
            return [];
        }

        try {
            return app(AccessGraph::class)->containing($productSlug);
        } catch (Throwable $e) {
            // Vor `php artisan migrate` oder bei einer Stoerung der Datenbank.
            // Kein 500 auf jeder geschuetzten Seite: geantwortet wird, als gaebe
            // es keine Zugaenge, und das sperrt eher, als dass es oeffnet. Der
            // Eintrag im Log sagt, dass es eine Stoerung war und keine Antwort.
            Log::error('statamic-products: could not read accesses for statamic-entitlements; answering as if there were none.', [
                'slug' => $productSlug,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
