<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\SoldHandles;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The product screen.
 *
 * Every row here is a price the site will actually charge, so the tests are the
 * ways somebody could change one by accident: writing without the permission,
 * posting a price the form does not offer, renaming a handle that invoices
 * already carry, or deleting a product somebody has paid for.
 */
class ProductScreenTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('ohne@example.com')->assignRole($role))->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function valid(array $overrides = []): array
    {
        return array_merge([
            'handle' => 'atemkurs',
            'name' => 'Atemkurs',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 4900,
            'digital' => true,
            'active' => true,
        ], $overrides);
    }

    protected function product(array $overrides = []): Product
    {
        return Product::create($this->valid(array_merge(['handle' => 'bestand', 'name' => 'Bestand'], $overrides)));
    }

    /**
     * A checkout somebody started and never finished.
     *
     * `Checkout::start()` writes both tables before it calls the provider, at
     * status `initiated`, and nothing clears those rows by default
     * (`prune_unpaid_after_days` ships at 0).
     */
    protected function abandon(string $handle): void
    {
        $payment = Payment::create([
            'product' => $handle,
            'provider_id' => 'tr_abgebrochen_'.$handle,
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_INITIATED,
            'provider' => 'fake',
        ]);

        PaymentItem::create([
            'payment_id' => $payment->id,
            'product' => $handle,
            'name' => 'Nie bezahlt',
            'amount_cent' => 4900,
            'kind' => PaymentItem::KIND_PRIMARY,
        ]);
    }

    protected function sell(string $handle): void
    {
        $payment = Payment::create([
            'product' => 'etwas-anderes',
            'provider_id' => 'tr_'.$handle,
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'provider' => 'fake',
        ]);

        PaymentItem::create([
            'payment_id' => $payment->id,
            'product' => $handle,
            'name' => 'Verkauft',
            'amount_cent' => 4900,
            'kind' => PaymentItem::KIND_PRIMARY,
        ]);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_write(): void
    {
        $product = $this->product();
        $user = $this->userWithoutPermission();

        // Three writing routes, three locks. A hidden button is not one.
        $this->actingAs($user)->postJson('/cp/utilities/products', $this->valid())->assertForbidden();
        $this->actingAs($user)->patchJson('/cp/utilities/products/'.$product->id, $this->valid(['amount_cent' => 1]))->assertForbidden();
        $this->actingAs($user)->deleteJson('/cp/utilities/products/'.$product->id)->assertForbidden();

        $this->assertSame(4900, $product->fresh()->amount_cent);
        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function a_product_can_be_created_and_changed(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid())
            ->assertRedirect();

        $product = Product::firstWhere('handle', 'atemkurs');
        $this->assertSame(4900, $product->amount_cent);
        $this->assertTrue($product->digital);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->valid(['amount_cent' => 5900]))
            ->assertRedirect();

        $this->assertSame(5900, $product->fresh()->amount_cent);
    }

    #[Test]
    public function the_kind_of_supply_has_to_be_answered(): void
    {
        // No default, because every default is wrong for half a catalogue and a
        // wrong one here surfaces as a tax line nobody checked. Silence fails;
        // both answers pass, including the falsy one.
        $payload = $this->valid();
        unset($payload['digital']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $payload)
            ->assertJsonValidationErrors('digital');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['digital' => false]))
            ->assertRedirect();

        $this->assertFalse(Product::firstWhere('handle', 'atemkurs')->digital);
    }

    #[Test]
    public function a_price_with_a_comma_in_it_is_refused(): void
    {
        // `49,00` read as an integer is 49 cents. The field is in cents and
        // stays an integer for exactly that reason.
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['amount_cent' => '49,00']))
            ->assertJsonValidationErrors('amount_cent');

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function free_is_allowed_and_negative_is_not(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['amount_cent' => 0]))
            ->assertRedirect();

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['handle' => 'minus', 'amount_cent' => -100]))
            ->assertJsonValidationErrors('amount_cent');
    }

    #[Test]
    public function two_products_cannot_share_a_handle(): void
    {
        $this->product(['handle' => 'atemkurs']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['handle' => 'atemkurs']))
            ->assertJsonValidationErrors('handle');

        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function the_handle_of_a_sold_product_cannot_be_changed(): void
    {
        // It sits on payment rows and invoice lines that cannot be migrated
        // along with it. Renaming breaks nothing loudly; it makes an old
        // invoice show a line whose product cannot be found any more.
        $product = $this->product(['handle' => 'atemkurs']);
        $this->sell('atemkurs');

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->valid(['handle' => 'atemkurs-neu']))
            ->assertJsonValidationErrors('handle');

        $this->assertSame('atemkurs', $product->fresh()->handle);
    }

    #[Test]
    public function everything_else_about_a_sold_product_stays_editable(): void
    {
        // The freeze is about the name in the invoice, not about the row. A
        // price that could never be corrected after the first sale would be a
        // worse rule than the one it replaces.
        $product = $this->product(['handle' => 'atemkurs']);
        $this->sell('atemkurs');

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->valid([
                'handle' => 'atemkurs',
                'name' => 'Atemkurs 2027',
                'amount_cent' => 6900,
                'active' => false,
            ]))
            ->assertRedirect();

        $product->refresh();
        $this->assertSame('Atemkurs 2027', $product->name);
        $this->assertSame(6900, $product->amount_cent);
        $this->assertFalse($product->active);
    }

    #[Test]
    public function a_sold_product_cannot_be_deleted(): void
    {
        $product = $this->product(['handle' => 'atemkurs']);
        $this->sell('atemkurs');

        $this->actingAs($this->user())
            ->deleteJson('/cp/utilities/products/'.$product->id)
            ->assertJsonValidationErrors('handle');

        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function an_unsold_product_can_be_deleted(): void
    {
        $product = $this->product();

        $this->actingAs($this->user())
            ->deleteJson('/cp/utilities/products/'.$product->id)
            ->assertRedirect();

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function an_abandoned_checkout_does_not_freeze_the_handle(): void
    {
        // **The trap this guard walked into.** A visitor opens the checkout and
        // closes the tab. That writes a payment and its line at `initiated`,
        // and nothing prunes them. Counting those rows locked the handle and
        // made deletion refuse for ever, for a product nobody ever bought.
        $product = $this->product(['handle' => 'atemkurs']);
        $this->abandon('atemkurs');

        $this->assertFalse($product->fresh()->hasBeenSold());

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->valid(['handle' => 'atemkurs-neu']))
            ->assertRedirect();

        $this->assertSame('atemkurs-neu', $product->fresh()->handle);
    }

    #[Test]
    public function an_abandoned_checkout_does_not_block_deletion(): void
    {
        $product = $this->product(['handle' => 'atemkurs']);
        $this->abandon('atemkurs');

        $this->actingAs($this->user())
            ->deleteJson('/cp/utilities/products/'.$product->id)
            ->assertRedirect();

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function the_listing_batch_agrees_with_the_single_row_check(): void
    {
        // Two spellings of one rule is how a listing and a form come to
        // disagree about the same product: the row would offer an edit the
        // save then refuses, or the other way round.
        $this->product(['handle' => 'bezahlt', 'name' => 'Bezahlt']);
        $this->product(['handle' => 'abgebrochen', 'name' => 'Abgebrochen']);
        $this->sell('bezahlt');
        $this->abandon('abgebrochen');

        $rows = collect(
            $this->actingAs($this->user())->getJson('/cp/utilities/products')->assertOk()->json('data')
        )->keyBy('handle');

        $this->assertTrue($rows['bezahlt']['edit_values']['sold']);
        $this->assertFalse($rows['abgebrochen']['edit_values']['sold']);
    }

    #[Test]
    public function a_patch_that_omits_a_field_does_not_erase_it(): void
    {
        // **Silently destructive, and it answered 200.** `$request->boolean()`
        // reads an absent key as false and `(array) null` is `[]`, so a client
        // that patched only the price switched the product off and threw away
        // its access slugs. The addon's own form sends everything, so the form
        // never saw this.
        $product = $this->product([
            'handle' => 'atemkurs',
            'active' => true,
            'grants' => ['atemkurs-zugang', 'community'],
        ]);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, [
                'name' => 'Atemkurs',
                'handle' => 'atemkurs',
                'type' => Product::TYPE_DOWNLOAD,
                'amount_cent' => 5900,
                'digital' => true,
            ])
            ->assertRedirect();

        $product->refresh();
        $this->assertSame(5900, $product->amount_cent, 'the change that was sent did not land');
        $this->assertTrue($product->active, 'an omitted `active` switched the product off');
        $this->assertSame(['atemkurs-zugang', 'community'], $product->grants, 'an omitted `grants` erased the access slugs');
    }

    #[Test]
    public function sending_an_empty_grants_list_still_clears_it(): void
    {
        // Absent is not empty. Somebody who really means "opens nothing" says so.
        $product = $this->product(['handle' => 'atemkurs', 'grants' => ['weg-damit']]);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->valid(['grants' => []]))
            ->assertRedirect();

        $this->assertNull($product->fresh()->grants);
    }

    #[Test]
    public function a_sale_recorded_on_the_old_single_product_column_counts_too(): void
    {
        // `payments.product` predates line items, and rows written then are
        // exactly the old ones this guard is for.
        $product = $this->product(['handle' => 'atemkurs']);

        Payment::create([
            'product' => 'atemkurs',
            'provider_id' => 'tr_alt',
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'provider' => 'fake',
        ]);

        SoldHandles::forget();

        $this->assertTrue($product->hasBeenSold());
    }

    #[Test]
    public function blanks_and_duplicates_never_reach_the_grants_column(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid([
                'grants' => ['zugang', '', 'zugang', '   ', 'community'],
            ]))
            ->assertRedirect();

        $this->assertSame(['zugang', 'community'], Product::firstWhere('handle', 'atemkurs')->grants);
    }

    #[Test]
    public function granting_nothing_is_stored_as_nothing(): void
    {
        // `null`, not `[]`. An empty array is a statement; null is the absence
        // of one, and a product that opens nothing is the normal case.
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['grants' => []]))
            ->assertRedirect();

        $this->assertNull(Product::firstWhere('handle', 'atemkurs')->grants);
    }

    #[Test]
    public function the_listing_says_which_rows_the_config_file_shadows(): void
    {
        $this->product(['handle' => 'noten-paket', 'name' => 'Doppelt']);
        $this->product(['handle' => 'atemkurs', 'name' => 'Eindeutig']);

        $rows = $this->actingAs($this->user())
            ->getJson('/cp/utilities/products')
            ->assertOk()
            ->json('data');

        $shadowed = collect($rows)->keyBy('handle');

        $this->assertTrue($shadowed['noten-paket']['shadowed']);
        $this->assertFalse($shadowed['atemkurs']['shadowed']);
    }

    #[Test]
    public function the_listing_asks_the_payment_tables_once_and_not_once_per_row(): void
    {
        // Twenty-five rows asking separately is fifty queries for a question
        // that is one `whereIn`. Counted rather than asserted about in prose,
        // because a batch that silently stops batching looks identical.
        foreach (range(1, 5) as $i) {
            $this->product(['handle' => 'produkt-'.$i, 'name' => 'Produkt '.$i]);
        }
        $this->sell('produkt-3');

        $this->actingAs($this->user())->getJson('/cp/utilities/products');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'payment')) {
                $queries[] = $query->sql;
            }
        });

        $rows = $this->actingAs($this->user())
            ->getJson('/cp/utilities/products')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $queries, 'One query per payment table, not one per row.');

        $sold = collect($rows)->keyBy('handle')->map(fn ($row) => $row['edit_values']['sold']);
        $this->assertTrue($sold['produkt-3']);
        $this->assertFalse($sold['produkt-1']);
    }
}
