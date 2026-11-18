<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

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

    /**
     * With the provider booted the guard stands ahead of `can:`, which is what an
     * install has. Up to brand-context 1.27.1 that showed the setup screen (and the
     * table names) to a signed-in user the route refuses; the fix
     * (`routeAuthorization`) is not in older versions, so the test skips there.
     */
    #[Test]
    public function with_the_provider_booted_a_user_without_the_permission_does_not_see_the_setup_screen(): void
    {
        if (! class_exists(self::BRAND_GUARD)) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        if (! (new \ReflectionClass(self::BRAND_GUARD))->hasMethod('routeAuthorization')) {
            $this->markTestSkipped('goldnead/statamic-brand-context has no route authorization in its setup guard yet (> 1.27.1).');
        }

        $product = Product::create([
            'handle' => 'atemkurs',
            'name' => 'Atemkurs',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 4900,
            'digital' => true,
            'active' => true,
        ]);

        Schema::rename('products', 'hidden_products');

        try {
            $role = tap(Role::make('nur-cp-provider')->addPermission('access cp'))->save();
            $user = tap(User::make()->email('ohne-recht-provider@example.com')->assignRole($role))->save();

            $this->actingAs($user)->get('/cp/utilities/products/'.$product->id)->assertRedirect();
            $this->actingAs($user)->getJson('/cp/utilities/products/'.$product->id)->assertForbidden();
        } finally {
            Schema::rename('hidden_products', 'products');
        }
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
