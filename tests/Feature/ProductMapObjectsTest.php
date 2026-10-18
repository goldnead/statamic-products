<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\StatamicProducts\Integrations\Map\ProductMapObjects;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Products as objects for the funnels map (brand-context 1.17).
 *
 * The map holds `type` and `id` and asks for everything else here. No metric:
 * orders are counted in statamic-payments, and this addon does not repeat
 * that count.
 */
class ProductMapObjectsTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        // The registry is a singleton of brand-context's provider, so it has
        // to be loaded for the registration test to see what `boot()` did.
        return array_merge(parent::getPackageProviders($app), [
            BrandContextServiceProvider::class,
        ]);
    }

    protected function produkt(string $handle, string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'handle' => $handle,
            'name' => $name,
            'type' => Product::TYPE_ACCESS,
            'amount_cent' => 2900,
            'digital' => true,
            'active' => true,
        ], $overrides));
    }

    protected function provider(): ProductMapObjects
    {
        return new ProductMapObjects;
    }

    protected function period(): MapPeriod
    {
        return MapPeriod::lastDays(30);
    }

    #[Test]
    public function it_names_its_type_and_its_label(): void
    {
        $this->assertSame('product', $this->provider()->type());
        $this->assertSame('Product', $this->provider()->typeLabel());
    }

    #[Test]
    public function it_lists_products_by_name_and_limits_them(): void
    {
        $this->produkt('kurs-vokale-aussprache', 'Vokale Aussprache');
        $this->produkt('atemkurs', 'Atemkurs');
        $this->produkt('chorstimme', 'Chorstimme');

        $names = array_map(fn ($object) => $object->label, $this->provider()->objects());
        $this->assertSame(['Atemkurs', 'Chorstimme', 'Vokale Aussprache'], $names);

        $limited = $this->provider()->objects(null, 2);
        $this->assertCount(2, $limited);
        $this->assertSame('Atemkurs', $limited[0]->label);
    }

    #[Test]
    public function it_filters_by_name_and_by_handle(): void
    {
        $this->produkt('kurs-vokale-aussprache', 'Vokale Aussprache');
        $this->produkt('atemkurs', 'Atemkurs');

        $byName = $this->provider()->objects('vokal');
        $this->assertCount(1, $byName);
        $this->assertSame('kurs-vokale-aussprache', $byName[0]->id);

        $byHandle = $this->provider()->objects('atemk');
        $this->assertCount(1, $byHandle);
        $this->assertSame('atemkurs', $byHandle[0]->id);

        $this->assertSame([], $this->provider()->objects('gibt-es-nicht'));
    }

    #[Test]
    public function an_object_carries_the_handle_label_link_switch_and_price(): void
    {
        $this->produkt('atemkurs', 'Atemkurs', ['amount_cent' => 4900]);

        $object = $this->provider()->find('atemkurs');

        $this->assertNotNull($object);
        $this->assertSame('product', $object->type);
        $this->assertSame('atemkurs', $object->id);
        $this->assertSame('Atemkurs', $object->label);
        $this->assertSame(true, $object->enabled);
        $this->assertSame('49.00 EUR', $object->detail);
        $this->assertStringEndsWith('/utilities/products/'.Product::query()->where('handle', 'atemkurs')->value('id'), $object->cpUrl);
    }

    #[Test]
    public function an_inactive_product_is_shown_as_off(): void
    {
        $this->produkt('altkurs', 'Alter Kurs', ['active' => false]);

        $this->assertFalse($this->provider()->find('altkurs')->enabled);
        $this->assertFalse($this->provider()->objects()[0]->enabled);
    }

    #[Test]
    public function find_returns_null_for_a_missing_product(): void
    {
        $this->assertNull($this->provider()->find('nicht-da'));
    }

    #[Test]
    public function it_gives_no_metric_for_any_product(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');

        $this->assertNull($this->provider()->metric('atemkurs', $this->period()));
        $this->assertNull($this->provider()->metric('nicht-da', $this->period()));
    }

    #[Test]
    public function the_provider_is_registered_once_the_addon_has_booted(): void
    {
        $registry = $this->app->make(MapObjectRegistry::class);

        $this->assertTrue($registry->has('product'));
        $this->assertSame(['product' => 'Product'], $registry->types());

        $this->produkt('atemkurs', 'Atemkurs');

        $objects = $registry->objects('product');
        $this->assertCount(1, $objects);
        $this->assertSame('atemkurs', $objects[0]->id);

        $this->assertSame('found', $registry->lookup('product', 'atemkurs')->status);
        $this->assertSame('missing', $registry->lookup('product', 'nicht-da')->status);
        $this->assertNull($registry->metric('product', 'atemkurs', $this->period()));
    }
}
