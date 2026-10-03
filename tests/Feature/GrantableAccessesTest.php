<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\Entitlements\Support\ProductCatalog;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\GrantableAccesses;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zugaenge mit Namen im Katalog von statamic-entitlements.
 *
 * Damit bietet das Vergabeformular unter Benutzer > Berechtigungen, das
 * Limits-Formular und der Abschnitt „Zugaenge" auf der User-Seite eine Auswahl
 * statt eines Slugs zum Eintippen.
 */
class GrantableAccessesTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists(ProductCatalog::class)) {
            $providers[] = ServiceProvider::class;
            $providers[] = \Goldnead\IdentityContracts\ServiceProvider::class;
            $providers[] = \Goldnead\Entitlements\ServiceProvider::class;
        }

        return $providers;
    }

    protected function access(string $handle, string $name, bool $active = true): Access
    {
        return Access::query()->create([
            'handle' => $handle,
            'name' => $name,
            'active' => $active,
            'contents' => [],
        ]);
    }

    #[Test]
    public function it_names_every_access_by_its_name_inactive_ones_marked(): void
    {
        $this->access('choiraccelerator', 'Choir Accelerator');
        $this->access('alt-kurs', 'Alter Kurs', active: false);

        $entries = (new GrantableAccesses)->grantableProducts();

        $this->assertSame('Choir Accelerator', $entries['choiraccelerator']['label']);
        // Altkaeufer behalten ihn, also muss er benannt bleiben, aber niemand
        // soll ihn aus Versehen neu vergeben.
        $this->assertArrayHasKey('alt-kurs', $entries);
        $this->assertNotSame('Alter Kurs', $entries['alt-kurs']['label']);
        $this->assertStringContainsString('Alter Kurs', $entries['alt-kurs']['label']);
    }

    /** A stand-in for brand-context's manager, as in BrandScopeTest. */
    protected function marke(bool $multi = true, ?int $current = 1): void
    {
        $this->app->instance('brand-context', new class($multi, $current)
        {
            public function __construct(protected bool $multi, protected ?int $current) {}

            public function multiBrandEnabled(): bool
            {
                return $this->multi;
            }

            public function hasCurrent(): bool
            {
                return $this->current !== null;
            }

            public function currentId(): ?int
            {
                return $this->current;
            }
        });
    }

    protected function brandAccess(string $handle, int $brand): Access
    {
        return Access::query()->create([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'active' => true,
            'contents' => [],
            'brand_id' => $brand,
        ]);
    }

    protected function brandsTable(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        // The migration may already seed a default brand under id 1.
        DB::table('brands')->updateOrInsert(['id' => 1], ['handle' => 'nordlicht', 'name' => 'Nordlicht Studio']);
        DB::table('brands')->updateOrInsert(['id' => 2], ['handle' => 'halbmond', 'name' => 'Halbmond']);
    }

    #[Test]
    public function with_a_brand_chosen_it_offers_only_that_brands_accesses(): void
    {
        $this->marke(current: 1);
        $this->brandAccess('nordlicht-kurs', 1);
        $this->brandAccess('halbmond-kurs', 2);

        $entries = (new GrantableAccesses)->grantableProducts();

        $this->assertSame(['nordlicht-kurs'], array_keys($entries));
    }

    #[Test]
    public function without_a_chosen_brand_it_offers_all_with_the_brand_as_group(): void
    {
        $this->brandsTable();
        $this->marke(current: null);
        $this->brandAccess('nordlicht-kurs', 1);
        $this->brandAccess('halbmond-kurs', 2);

        $entries = (new GrantableAccesses)->grantableProducts();

        $this->assertSame('Nordlicht Studio', $entries['nordlicht-kurs']['group']);
        $this->assertSame('Halbmond', $entries['halbmond-kurs']['group']);
    }

    #[Test]
    public function a_single_brand_install_gets_every_access_without_a_group(): void
    {
        $this->marke(multi: false, current: null);
        $this->brandAccess('kurs-a', 0);
        $this->brandAccess('kurs-b', 0);

        $entries = (new GrantableAccesses)->grantableProducts();

        $this->assertSame(['kurs-a', 'kurs-b'], array_keys($entries));
        $this->assertNull($entries['kurs-a']['group']);
    }

    #[Test]
    public function it_answers_nothing_before_the_migration_ran(): void
    {
        Schema::drop('product_accesses');

        $this->assertSame([], (new GrantableAccesses)->grantableProducts());
    }

    #[Test]
    public function entitlements_offers_the_accesses_in_its_pickers(): void
    {
        if (! class_exists(ProductCatalog::class)) {
            $this->markTestSkipped('statamic-entitlements without the product catalogue (before 1.6.0).');
        }

        $this->access('choiraccelerator', 'Choir Accelerator');

        $products = app(ProductCatalog::class)->all();

        $this->assertSame('Choir Accelerator', $products['choiraccelerator']['label'] ?? null);
        $this->assertSame(['choiraccelerator' => 'Choir Accelerator'], app(ProductCatalog::class)->options());
    }

    #[Test]
    public function a_saved_access_shows_up_without_a_restart(): void
    {
        if (! class_exists(ProductCatalog::class)) {
            $this->markTestSkipped('statamic-entitlements without the product catalogue (before 1.6.0).');
        }

        // Read when a form asks, never cached at boot: a new access is in the
        // picker on the next page load.
        $this->assertSame([], app(ProductCatalog::class)->all());

        $this->access('neu', 'Neuer Zugang');

        $this->assertArrayHasKey('neu', app(ProductCatalog::class)->all());
    }
}
