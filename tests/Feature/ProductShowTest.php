<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\Siblings;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The product screen's two sections: who sells it, who bought it.
 *
 * `statamic-offers` is not in this suite's vendor directory, so its table is
 * built by hand with the columns the screen reads and the sibling declared
 * present through {@see Siblings::pretend()}. The "addon missing" case is the
 * default state of the suite, which is the honest way to test it.
 */
class ProductShowTest extends TestCase
{
    protected function tearDown(): void
    {
        Siblings::forget();

        parent::tearDown();
    }

    /**
     * The siblings' listings, by name only. Their utilities are not registered
     * here — payments boots as a plain package and offers is not installed —
     * and the links on this screen are built against the route names.
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/cp/utilities/offers', fn () => '')->name('statamic.cp.utilities.offers');
        $router->get('/cp/utilities/payments', fn () => '')->name('statamic.cp.utilities.payments');
    }

    protected function user()
    {
        return tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    /**
     * A stand-in for brand-context's manager, answering exactly what `Brands`
     * asks — the same one `BrandScopeTest` uses.
     */
    protected function marke(bool $multi = true, ?int $current = 1): void
    {
        $this->app->instance('brand-context', new class($multi, $current)
        {
            public function __construct(
                protected bool $multi,
                protected ?int $current,
            ) {}

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

    protected function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'handle' => 'atemkurs',
            'name' => 'Atemkurs',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 4900,
            'digital' => true,
            'active' => true,
        ], $overrides));
    }

    protected function offersTable(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('handle', 191);
            $table->string('name', 191);
            $table->string('product', 191);
            $table->json('products')->nullable();
            $table->unsignedInteger('amount_cent')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('slot', 32)->default('standalone');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Siblings::pretend(Siblings::OFFERS);
    }

    protected function offer(array $attributes): void
    {
        DB::table('offers')->insert(array_merge([
            'handle' => 'angebot-'.uniqid(),
            'name' => 'Angebot',
            'product' => 'atemkurs',
            'slot' => 'standalone',
            'active' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    protected function purchase(string $handle, array $payment = [], array $item = []): Payment
    {
        $paid = Payment::create(array_merge([
            'product' => $handle,
            'provider_id' => 'tr_'.uniqid(),
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'provider' => 'fake',
            'email' => 'kauf@example.com',
            'paid_at' => Carbon::now()->subDay(),
        ], $payment));

        PaymentItem::create(array_merge([
            'payment_id' => $paid->id,
            'product' => $handle,
            'name' => 'Atemkurs',
            'amount_cent' => 4900,
            'kind' => PaymentItem::KIND_PRIMARY,
        ], $item));

        return $paid;
    }

    #[Test]
    public function the_screen_needs_the_permission(): void
    {
        $product = $this->product();
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email('ohne@example.com')->assignRole($role))->save();

        $this->actingAs($user)->getJson('/cp/utilities/products/'.$product->id)->assertForbidden();
    }

    #[Test]
    public function it_shows_the_product_and_lists_its_offers_as_lead_and_in_bundles(): void
    {
        $product = $this->product();
        $this->offersTable();

        $this->offer(['handle' => 'fruehling', 'name' => 'Frühlingsaktion', 'product' => 'atemkurs', 'amount_cent' => 3900, 'currency' => 'EUR']);
        $this->offer(['handle' => 'paket', 'name' => 'Paket', 'product' => 'anderes', 'products' => json_encode(['atemkurs', 'workbook']), 'slot' => 'bump', 'active' => false]);
        $this->offer(['handle' => 'fremd', 'name' => 'Fremd', 'product' => 'anderes']);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-products::Products/Show')
                ->where('product.handle', 'atemkurs')
                ->has('offers', 2)
                // Active first, then by name.
                ->where('offers.0.handle', 'fruehling')
                ->where('offers.0.lead', true)
                ->where('offers.0.own_price', true)
                ->where('offers.0.amount', '39.00')
                ->where('offers.1.handle', 'paket')
                ->where('offers.1.lead', false)
                // No price of its own: the list price is what a buyer pays.
                ->where('offers.1.own_price', false)
                ->where('offers.1.amount', '49.00')
                ->where('offers.1.active', false)
                ->where('offers.1.url', fn ($url) => str_contains((string) $url, 'utilities/offers') && str_contains((string) $url, 'search=paket'))
            );
    }

    #[Test]
    public function it_lists_the_last_paid_buyers_newest_first_and_only_paid_ones(): void
    {
        $product = $this->product();

        $alt = $this->purchase('atemkurs', ['email' => 'alt@example.com', 'paid_at' => Carbon::now()->subDays(10)]);
        $neu = $this->purchase('atemkurs', ['email' => 'neu@example.com', 'paid_at' => Carbon::now()->subDay(), 'refunded_cent' => 4900], ['kind' => PaymentItem::KIND_BUMP, 'amount_cent' => 900, 'quantity' => 2]);
        // Never paid: not a buyer.
        $this->purchase('atemkurs', ['email' => 'nie@example.com', 'status' => Payment::STATUS_OPEN, 'paid_at' => null]);
        // Bought something else.
        $this->purchase('workbook', ['email' => 'anderes@example.com']);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('buyers', 2)
                ->where('buyers.0.email', 'neu@example.com')
                ->where('buyers.0.payment_id', $neu->id)
                ->where('buyers.0.kind', 'bump')
                // Two of them at 9,00: the line, not the whole payment.
                ->where('buyers.0.amount', '18.00')
                ->where('buyers.0.refunded', true)
                ->where('buyers.0.url', fn ($url) => str_contains((string) $url, 'utilities/payments') && str_contains((string) $url, 'search=neu'))
                ->where('buyers.1.email', 'alt@example.com')
                ->where('buyers.1.payment_id', $alt->id)
                ->where('buyers.1.refunded', false)
            );
    }

    #[Test]
    public function it_shows_at_most_fifty_buyers(): void
    {
        $product = $this->product();

        foreach (range(1, 53) as $i) {
            $this->purchase('atemkurs', ['email' => "k{$i}@example.com", 'paid_at' => Carbon::now()->subMinutes($i)]);
        }

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertInertia(fn ($page) => $page->has('buyers', 50)->where('buyers.0.email', 'k1@example.com'));
    }

    /** No offers addon: no section, rather than an empty one claiming "no offers". */
    #[Test]
    public function the_offers_section_is_absent_when_the_offers_addon_is_missing(): void
    {
        $product = $this->product();

        $this->assertFalse(Siblings::installed(Siblings::OFFERS));

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('offers', null)
                ->where('buyers', [])
            );
    }

    /** Payments not migrated yet: the buyers section is absent too. */
    #[Test]
    public function the_buyers_section_is_absent_when_the_payments_tables_are_missing(): void
    {
        $product = $this->product();
        Siblings::pretend(Siblings::PAYMENTS, false);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('buyers', null));
    }

    /**
     * Another brand's product is not this brand's to see.
     *
     * A 404 and not a 403: the id is guessable, and "forbidden" would confirm
     * that something is there. Same answer the listing gives.
     */
    #[Test]
    public function another_brands_product_is_not_found_in_multi_brand_mode(): void
    {
        $this->marke(current: 1);
        $fremd = $this->product(['brand_id' => 2]);
        $eigen = $this->product(['handle' => 'eigen', 'brand_id' => 1]);

        $user = $this->user();

        $this->actingAs($user)->getJson('/cp/utilities/products/'.$fremd->id)->assertNotFound();
        $this->actingAs($user)->get('/cp/utilities/products/'.$eigen->id)->assertOk();
    }

    /** With no brand current, the screen fails closed like the listing does. */
    #[Test]
    public function no_current_brand_means_no_product_screen(): void
    {
        $this->marke(current: null);
        $product = $this->product(['brand_id' => 1]);

        $this->actingAs($this->user())->getJson('/cp/utilities/products/'.$product->id)->assertNotFound();
    }

    /** The buyer list is narrowed to the product's brand, even for a re-stamped row. */
    #[Test]
    public function buyers_of_another_brand_are_not_listed_in_multi_brand_mode(): void
    {
        $this->marke(current: 1);
        $product = $this->product(['brand_id' => 1]);

        $this->purchase('atemkurs', ['email' => 'eigen@example.com', 'brand_id' => 1]);
        $this->purchase('atemkurs', ['email' => 'fremd@example.com', 'brand_id' => 2]);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertInertia(fn ($page) => $page
                ->has('buyers', 1)
                ->where('buyers.0.email', 'eigen@example.com')
            );
    }

    #[Test]
    public function every_listed_row_carries_the_way_to_its_screen(): void
    {
        $product = $this->product();

        $this->actingAs($this->user())
            ->getJson('/cp/utilities/products')
            ->assertOk()
            ->assertJsonPath('data.0.show_url', fn ($url) => str_ends_with((string) $url, '/cp/utilities/products/'.$product->id));
    }
}
