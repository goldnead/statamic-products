<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\Setup;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** A guard class whose alias nobody registered: brand-context in vendor, its provider not booted. */
class AliasLessGuard
{
    public const ALIAS = 'brand.never-registered-guard';

    public static function using(string $setup, string $title, string ...$tables): string
    {
        return self::ALIAS.':'.$setup;
    }
}

/**
 * The two CP detail pages that read their table by id (`products/{product}`,
 * `product-accesses/{access}`) take the setup guard of
 * `goldnead/statamic-brand-context` (>= 1.27) as route middleware, so an
 * unmigrated database shows the setup screen instead of an HTTP 500.
 *
 * `brand-context` is a suggest. The suite cannot run in CI without the package
 * in vendor (the sibling addons it tests against require it themselves), so the
 * "package absent" half is proved at the seam: `detailGuardFrom()` with a class
 * that is not there, or one whose alias is not registered, yields no middleware.
 * Tests that need the package skip themselves where it is missing; the
 * migrated-install tests run either way.
 */
class BrandContextGuardTest extends TestCase
{
    private const PRODUCT_SHOW = 'statamic.cp.utilities.products.show';

    private const ACCESS_SHOW = 'statamic.cp.utilities.product-accesses.show';

    /** @var list<string> */
    private array $hidden = [];

    protected $superuser = null;

    protected function tearDown(): void
    {
        // The rename is what `Schema::hasTable()` asks about, and it is put back
        // here: a dropped table would take the migration rollback down with it.
        foreach ($this->hidden as $table) {
            if (Schema::hasTable('hidden_'.$table)) {
                Schema::rename('hidden_'.$table, $table);
            }
        }

        parent::tearDown();
    }

    private function installed(): bool
    {
        return class_exists(self::BRAND_GUARD);
    }

    private function hide(string $table): void
    {
        if (Schema::hasTable($table)) {
            Schema::rename($table, 'hidden_'.$table);
            $this->hidden[] = $table;
        }
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('guard@example.com')->makeSuper())->save();
    }

    private function middlewareOf(string $route): array
    {
        return Route::getRoutes()->getByName($route)->gatherMiddleware();
    }

    private function product(): Product
    {
        return Product::create([
            'handle' => 'atemkurs',
            'name' => 'Atemkurs',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 4900,
            'digital' => true,
            'active' => true,
        ]);
    }

    private function access(): Access
    {
        return Access::create([
            'handle' => 'choiraccelerator',
            'name' => 'ChoirAccelerator',
            'active' => true,
            'opens_members_area' => false,
            'contents' => [],
            'credits' => [],
        ]);
    }

    #[Test]
    public function a_missing_middleware_class_gives_no_middleware(): void
    {
        $this->assertSame([], Setup::detailGuardFrom('Goldnead\NotInstalled\GuardSetup', 'Title', 'products'));
    }

    /** A class that is there but has no `using()` is not the guard either. */
    #[Test]
    public function a_class_without_using_gives_no_middleware(): void
    {
        $this->assertSame([], Setup::detailGuardFrom(Setup::class, 'Title', 'products'));
    }

    #[Test]
    public function the_installed_middleware_is_asked_for_this_addons_setup_class(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $middleware = Setup::detailGuard('statamic-products::messages.utility_nav', 'products');

        $this->assertCount(1, $middleware);
        $this->assertStringStartsWith('brand.setup-guard:'.Setup::class.',', $middleware[0]);
        $this->assertStringEndsWith(',products', $middleware[0]);
    }

    #[Test]
    public function without_the_package_the_detail_routes_carry_no_guard(): void
    {
        if ($this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is installed.');
        }

        foreach ([self::PRODUCT_SHOW, self::ACCESS_SHOW] as $route) {
            $guards = array_filter(
                $this->middlewareOf($route),
                fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'brand.setup-guard')
            );

            $this->assertSame([], array_values($guards), $route);
        }
    }

    #[Test]
    public function with_the_package_the_detail_routes_carry_the_guard(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        foreach ([self::PRODUCT_SHOW, self::ACCESS_SHOW] as $route) {
            $guards = array_filter(
                $this->middlewareOf($route),
                fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'brand.setup-guard:')
            );

            $this->assertCount(1, $guards, $route);
        }
    }

    #[Test]
    public function with_the_package_a_product_page_shows_the_setup_screen_when_its_table_is_missing(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $product = $this->product();
        $this->hide('products');

        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::SetupRequired', $page['component']);
        $this->assertContains('products', $page['props']['tables']);
    }

    #[Test]
    public function with_the_package_an_access_page_shows_the_setup_screen_when_its_table_is_missing(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $access = $this->access();
        $this->hide('product_accesses');

        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::SetupRequired', $page['component']);
        $this->assertContains('product_accesses', $page['props']['tables']);
    }

    /**
     * The access page also lists the products that grant it, so a missing
     * `products` table is a 500 there too, and the guard has to name it.
     */
    #[Test]
    public function with_the_package_an_access_page_shows_the_setup_screen_when_the_products_table_is_missing(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $access = $this->access();
        $this->hide('products');

        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::SetupRequired', $page['component']);
        $this->assertContains('products', $page['props']['tables']);
    }

    /** A guard class whose alias nobody registered (provider not booted) gives no middleware either. */
    #[Test]
    public function a_guard_class_without_its_alias_in_the_router_gives_no_middleware(): void
    {
        $this->assertSame([], Setup::detailGuardFrom(AliasLessGuard::class, 'Title', 'products'));
    }

    #[Test]
    public function with_the_package_a_user_without_the_permission_does_not_see_the_setup_screen(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $product = $this->product();
        $this->hide('products');

        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email('ohne-recht@example.com')->assignRole($role))->save();

        $this->actingAs($user)->get('/cp/utilities/products/'.$product->id)->assertRedirect();
        $this->actingAs($user)->getJson('/cp/utilities/products/'.$product->id)->assertForbidden();
    }

    /** Both legs: a migrated install renders the pages it always did. */
    #[Test]
    public function a_migrated_install_still_renders_the_product_page(): void
    {
        $product = $this->product();

        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::Products/Show', $page['component']);
    }

    #[Test]
    public function a_migrated_install_still_renders_the_access_page(): void
    {
        $access = $this->access();

        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::Accesses/Show', $page['component']);
    }
}
