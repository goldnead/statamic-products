<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The guard as an install gets it: brand-context's own provider boots (package
 * discovery) and registers the `brand.setup-guard` alias, and this addon's
 * routes, which are built while the application boots, must still find it.
 * The rest of the suite registers only the alias (see TestCase).
 */
class BrandContextProviderTest extends TestCase
{
    protected bool $registerGuardAlias = false;

    protected function getPackageProviders($app)
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists('Goldnead\BrandContext\ServiceProvider')) {
            $providers[] = 'Goldnead\BrandContext\ServiceProvider';
        }

        return $providers;
    }

    #[Test]
    public function the_detail_routes_find_the_alias_the_packages_provider_registers(): void
    {
        if (! class_exists(self::BRAND_GUARD)) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        foreach (['statamic.cp.utilities.products.show', 'statamic.cp.utilities.product-accesses.show'] as $name) {
            $guards = array_filter(
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'brand.setup-guard:')
            );

            $this->assertCount(1, $guards, $name);
        }
    }
}
