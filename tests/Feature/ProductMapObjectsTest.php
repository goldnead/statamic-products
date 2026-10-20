<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicProducts\Integrations\Map\ProductMapObjects;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Products as objects for the funnels map (brand-context 1.17).
 *
 * The map holds `type` and `id` and asks for everything else here. The metric
 * is the number of paid orders in the period, not the revenue figure of
 * statamic-payments' "Orders" insight; see ProductMapObjects.
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

    /**
     * A payment with lines for the given products. The first handle is the
     * primary line. `$payment` overrides columns, e.g. `product`, the legacy
     * single-product column on the payment.
     *
     * @param  list<string>  $lines
     */
    protected function order(array $lines, array $payment = []): Payment
    {
        $order = Payment::create(array_merge([
            'product' => $lines[0] ?? 'etwas-anderes',
            'provider_id' => 'tr_'.uniqid(),
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'provider' => 'fake',
            'paid_at' => Carbon::now()->subDay(),
        ], $payment));

        foreach ($lines as $index => $handle) {
            PaymentItem::create([
                'payment_id' => $order->id,
                'product' => $handle,
                'name' => $handle,
                'amount_cent' => 4900,
                'kind' => $index === 0 ? PaymentItem::KIND_PRIMARY : PaymentItem::KIND_BUMP,
            ]);
        }

        return $order;
    }

    #[Test]
    public function the_metric_counts_paid_orders_of_the_product_in_the_period(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');
        $this->produkt('chorstimme', 'Chorstimme');

        // Counted: paid, in the period, with a line for the product.
        $this->order(['atemkurs']);
        $this->order(['chorstimme', 'atemkurs']);

        // Counted: the legacy column, no lines at all.
        $this->order([], ['product' => 'atemkurs']);

        // Counted once: a line and the legacy column on the same payment.
        $this->order(['atemkurs'], ['product' => 'atemkurs']);

        // Not counted: not paid.
        $this->order(['atemkurs'], ['status' => Payment::STATUS_OPEN]);

        // Not counted: paid outside the period.
        $this->order(['atemkurs'], ['paid_at' => Carbon::now()->subDays(60)]);

        // Not counted: paid in the future.
        $this->order(['atemkurs'], ['paid_at' => Carbon::now()->addDay()]);

        // Not counted: a payment with lines names another product in the column.
        $this->order(['chorstimme'], ['product' => 'atemkurs']);

        $metric = $this->provider()->metric('atemkurs', $this->period());

        $this->assertNotNull($metric);
        $this->assertSame(4, $metric->value);
        $this->assertSame('Paid orders', $metric->what);
        $this->assertSame('last 30 days', $metric->period->label());
    }

    #[Test]
    public function a_product_without_orders_has_a_metric_of_zero(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');

        $metric = $this->provider()->metric('atemkurs', $this->period());

        $this->assertNotNull($metric);
        $this->assertSame(0, $metric->value);
    }

    #[Test]
    public function an_unknown_product_has_no_metric(): void
    {
        $this->assertNull($this->provider()->metric('nicht-da', $this->period()));
    }

    #[Test]
    public function details_show_the_price_and_the_paid_orders_of_the_period(): void
    {
        $this->produkt('atemkurs', 'Atemkurs', ['amount_cent' => 4900]);
        $this->produkt('chorstimme', 'Chorstimme');

        $this->order(['atemkurs']);
        $this->order(['chorstimme', 'atemkurs']);
        $this->order([], ['product' => 'atemkurs']);
        $this->order(['atemkurs'], ['status' => Payment::STATUS_OPEN]);
        $this->order(['atemkurs'], ['paid_at' => Carbon::now()->subDays(60)]);
        // Another currency is still one order: the count never adds money.
        $this->order(['atemkurs'], ['currency' => 'USD']);

        $details = $this->provider()->details('atemkurs', $this->period());

        $this->assertCount(1, $details);
        $this->assertSame('facts', $details[0]['kind']);
        $this->assertSame([
            ['label' => 'Price', 'value' => '49.00 EUR', 'href' => null],
            ['label' => 'Paid orders (last 30 days)', 'value' => '4', 'href' => null],
        ], $details[0]['rows']);

        // The same rule as the card.
        $this->assertSame(
            $this->provider()->metric('atemkurs', $this->period())->value,
            (int) $details[0]['rows'][1]['value'],
        );
    }

    #[Test]
    public function details_name_the_interval_of_a_recurring_price(): void
    {
        $this->produkt('abo', 'Abo', ['interval' => '1 month', 'amount_cent' => 1900]);

        $rows = $this->provider()->details('abo', $this->period())[0]['rows'];

        $this->assertSame('19.00 EUR / 1 month', $rows[0]['value']);
        $this->assertSame('0', $rows[1]['value']);
    }

    #[Test]
    public function details_show_only_the_price_when_the_payments_table_is_missing(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');
        // Renamed, not dropped, so the migrations can still roll back in teardown.
        Schema::rename('payments', 'payments_away');

        try {
            $rows = $this->provider()->details('atemkurs', $this->period())[0]['rows'];
        } finally {
            Schema::rename('payments_away', 'payments');
        }

        $this->assertSame([['label' => 'Price', 'value' => '29.00 EUR', 'href' => null]], $rows);
    }

    #[Test]
    public function details_speak_german_in_a_german_cp(): void
    {
        app()->setLocale('de');
        $this->produkt('atemkurs', 'Atemkurs', ['amount_cent' => 119900]);

        $rows = $this->provider()->details('atemkurs', $this->period())[0]['rows'];

        $this->assertSame('Preis', $rows[0]['label']);
        $this->assertSame('1.199,00 EUR', $rows[0]['value']);
        $this->assertSame('Bezahlte Bestellungen (letzte 30 Tage)', $rows[1]['label']);
    }

    #[Test]
    public function details_of_an_unknown_product_are_null(): void
    {
        $this->assertNull($this->provider()->details('nicht-da', $this->period()));
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
        $this->assertSame(0, $registry->metric('product', 'atemkurs', $this->period())->value);
    }

    #[Test]
    public function it_names_the_products_that_grant_a_slug(): void
    {
        $this->produkt('masterclass-vokale-aussprache', 'Masterclass', ['grants' => ['kurs-vokale-aussprache', 'bonus']]);
        $this->produkt('bundle', 'Bundle', ['grants' => ['kurs-vokale-aussprache']]);
        $this->produkt('anderes', 'Anderes', ['grants' => ['etwas-anderes']]);
        $this->produkt('ohne', 'Ohne Gewaehr');

        $this->assertSame(
            ['bundle', 'masterclass-vokale-aussprache'],
            $this->provider()->productsGranting('kurs-vokale-aussprache'),
        );
        $this->assertSame([], $this->provider()->productsGranting('nichts'));
        $this->assertSame([], $this->provider()->productsGranting(''));
    }
}
