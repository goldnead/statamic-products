<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the payment catalogue learns from this addon.
 *
 * Two seams with two jobs: `contribute()` fills a picker, `extend()` prices a
 * sale. Everything here is about keeping those apart and keeping both honest.
 */
class CatalogueTest extends TestCase
{
    protected function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'handle' => 'atemkurs',
            'name' => 'Atemkurs',
            'amount_cent' => 4900,
            'digital' => true,
            'active' => true,
        ], $attributes));
    }

    #[Test]
    public function a_product_can_be_bought(): void
    {
        $this->product();

        $payment = app(Checkout::class)->start('atemkurs')->payment;

        $this->assertSame(4900, $payment->amount_cent);
        $this->assertSame('atemkurs', $payment->product);
    }

    #[Test]
    public function a_product_appears_in_the_list_a_picker_is_built_from(): void
    {
        $this->product();

        $all = app(Catalogue::class)->all();

        $this->assertArrayHasKey('atemkurs', $all);
        $this->assertSame('Atemkurs', $all['atemkurs']['name']);
        // The configured catalogue is not replaced, it is joined.
        $this->assertArrayHasKey('noten-paket', $all);
    }

    #[Test]
    public function an_inactive_product_can_neither_be_listed_nor_bought(): void
    {
        // Retiring a product is the only way to stop selling one that has been
        // sold, so "inactive" has to mean it everywhere. Listed but unbuyable
        // would put a line in the picker that ends in a 422; buyable but
        // unlisted would keep taking money for something withdrawn.
        $this->product(['active' => false]);

        $this->assertArrayNotHasKey('atemkurs', app(Catalogue::class)->all());
        $this->assertNull(app(Catalogue::class)->find('atemkurs'));
        $this->assertNull(app(Checkout::class)->start('atemkurs'));
    }

    #[Test]
    public function the_configured_catalogue_wins_over_a_product_row(): void
    {
        // A price in a file is in version control and was written on purpose.
        // A row must not overrule a deploy without anyone seeing it happen.
        $this->product(['handle' => 'noten-paket', 'name' => 'Untergeschoben', 'amount_cent' => 100]);

        $this->assertSame(2900, app(Catalogue::class)->find('noten-paket')['amount_cent']);
        $this->assertSame(2900, app(Catalogue::class)->all()['noten-paket']['amount_cent']);
        $this->assertSame(2900, app(Checkout::class)->start('noten-paket')->payment->amount_cent);
    }

    #[Test]
    public function a_shadowed_product_says_so(): void
    {
        // Config winning is right and silent. Silence is right for the answer
        // and wrong for the screen, so the row carries the collision itself.
        $shadowed = $this->product(['handle' => 'noten-paket']);
        $free = $this->product(['handle' => 'atemkurs']);

        $this->assertTrue($shadowed->isShadowedByConfig());
        $this->assertFalse($free->isShadowedByConfig());
    }

    #[Test]
    public function a_free_product_is_a_real_product(): void
    {
        // Zero is somebody saying "this one is free" — the lead magnet, the
        // sample chapter. Refusing it meant every free thing lived outside the
        // addon, which is why the catalogue started allowing it.
        $this->product(['handle' => 'probekapitel', 'amount_cent' => 0]);

        $this->assertArrayHasKey('probekapitel', app(Catalogue::class)->all());
        $this->assertSame(0, app(Catalogue::class)->find('probekapitel')['amount_cent']);
    }

    #[Test]
    public function what_a_product_opens_reaches_the_catalogue(): void
    {
        $this->product(['grants' => ['atemkurs-zugang', 'community']]);

        $this->assertSame(
            ['atemkurs-zugang', 'community'],
            app(Catalogue::class)->find('atemkurs')['grants'],
        );
    }

    #[Test]
    public function rubbish_in_the_grants_column_never_reaches_the_bridge(): void
    {
        // The column is JSON and a half-filled form writes what it likes. A
        // null or an integer handed to the entitlements bridge is how a
        // purchase ends with a payment, an invoice and no access — which has
        // happened twice in this family and made no noise either time.
        $product = $this->product();
        DB::table('products')->where('handle', 'atemkurs')
            ->update(['grants' => json_encode(['zugang', null, '', 42, 'zugang', ' '])]);

        $this->assertSame(['zugang'], $product->fresh()->grantSlugs());
    }

    #[Test]
    public function a_product_that_opens_nothing_sends_no_grants_key_at_all(): void
    {
        // Not an empty list. The bridge logs the difference, and a printed
        // score that was never meant to open anything should not appear in
        // that log every time it is sold.
        $this->product(['grants' => null]);

        $this->assertArrayNotHasKey('grants', app(Catalogue::class)->find('atemkurs'));
    }

    #[Test]
    public function resolving_a_price_does_not_depend_on_a_brand_being_current(): void
    {
        // The one context that never knows whose request it is, is the one that
        // takes the money: a provider webhook arriving hours after the sale. A
        // price that needed a brand would be a price that could not be resolved
        // there at all.
        $this->product(['brand_id' => 7]);

        $this->assertSame(4900, app(Catalogue::class)->find('atemkurs')['amount_cent']);
    }

    #[Test]
    public function a_product_names_the_brand_it_belongs_to(): void
    {
        // Der Katalogeintrag ist die einzige Naht zu `statamic-payments`, und
        // seit dessen 1.24.1 stempelt `FollowUp::brandFor()` eine Folgezahlung
        // mit **dieser** Angabe statt mit der geerbten Marke der
        // Vorgaengerzahlung. Ohne den Schluessel ist der Fix drueben stiller
        // toter Code und ein Upsell wird weiter unter der falschen Marke
        // verkauft — mit Rechnungsserie und Absender daran.
        $this->product(['brand_id' => 7]);

        // Streng auf `int`: drueben nimmt die Lesart eine Ziffernfolge im Text
        // zwar an, aber ein Array verwirft sie. Was hier herausgeht, soll die
        // eindeutige Form haben.
        $this->assertSame(7, app(Catalogue::class)->find('atemkurs')['brand_id']);
    }

    #[Test]
    public function a_product_on_a_single_brand_install_names_zero_rather_than_nothing(): void
    {
        // Null ist auf einem Betrieb ohne Mandanten jede Zeile. Der Schluessel
        // fehlt trotzdem nicht: drueben ist `0` die ausdrueckliche Aussage
        // „nennt keine Marke", die zum Erbe der Vorgaengerzahlung fuehrt und
        // eine `info`-Zeile schreibt. Ein fehlender Schluessel saehe genauso
        // aus wie eine aeltere Fassung dieses Addons.
        $this->product();

        $this->assertSame(0, app(Catalogue::class)->find('atemkurs')['brand_id']);
    }

    #[Test]
    public function without_products_nothing_changes(): void
    {
        $catalogue = app(Catalogue::class);

        $this->assertSame($catalogue->configured(), $catalogue->all());
        $this->assertNull($catalogue->find('atemkurs'));
    }

    #[Test]
    public function a_missing_table_answers_empty_instead_of_taking_the_checkout_down(): void
    {
        // The window between `composer require` and `php artisan migrate` is
        // minutes on a real host and months on a forgotten staging box. An
        // uncaught throw here is a 500 on the checkout of a site that was
        // selling fine an hour earlier.
        $this->product();
        DB::statement('DROP TABLE products');

        $this->assertNull(app(Catalogue::class)->find('atemkurs'));
        $this->assertSame(app(Catalogue::class)->configured(), app(Catalogue::class)->all());
        // And the configured catalogue still sells.
        $this->assertSame(2900, app(Checkout::class)->start('noten-paket')->payment->amount_cent);
    }
}
