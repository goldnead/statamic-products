<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\Events\Models\Event;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\RefTarget;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User;

/**
 * The kind of thing a product is, and the pointer beside it.
 *
 * Two rules are load-bearing here and both are easy to lose later.
 *
 * **The kind is an answer, never an instruction.** Naming a product a date does
 * not reserve a seat. The day something in this addon acts on a kind, it has
 * turned into a delivery platform and the decision that produced this design is
 * gone.
 *
 * **A pointer that cannot be checked is not a pointer that is wrong.** The LMS
 * and the community addon do not exist yet; refusing to catalogue what they will
 * sell would be the tail wagging the dog.
 */
class ProductTypeTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
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

    protected function produkt(array $overrides = []): Product
    {
        return Product::create($this->valid($overrides));
    }

    /**
     * A real event, through `statamic-events`' own migration and model.
     *
     * Not a hand-made `products`-style insert: a row written past that
     * package's own `booted()` would carry no uuid and no slug, and would
     * therefore prove nothing about the shape this addon reads.
     */
    protected function event(string $title): Event
    {
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-events/database/migrations');
        $this->artisan('migrate');
        RefTarget::forget();

        // `statamic-events` rows carry a brand, and both the stamp and the
        // global scope ask the container for `brand-context` — whose provider
        // this suite does not register. A stand-in that answers exactly the
        // questions `BrandScope` asks, and no more: a double that said yes to
        // everything would prove nothing about how the scope behaves.
        $this->app->instance('brand-context', new class
        {
            public function scopeIsDisabled(): bool
            {
                return false;
            }

            public function multiBrandEnabled(): bool
            {
                return false;
            }

            public function hasCurrent(): bool
            {
                return true;
            }

            public function currentId(): ?int
            {
                // A single-brand install still has a default brand, and
                // `HasBrand` stamps it onto every row it creates. Answering
                // null here made that stamp write NULL into a NOT NULL column —
                // the double was looser than the thing it stands in for.
                return 1;
            }

            public function failMode(): string
            {
                return 'closed';
            }
        });

        return Event::create(['title' => $title, 'brand_id' => 0]);
    }

    #[Test]
    public function the_german_kinds_from_1_0_0_are_rewritten_to_english(): void
    {
        // 1.0.0 shipped `zugang`, `termin`, `sitzungen` and `kohorte` while every
        // sibling stores English. A row written by that version has to arrive on
        // the new value, or `RefTarget` quietly answers "unknowable" for it and
        // stops checking its pointer at all — the silent half of a failure.
        foreach (['zugang' => 'access', 'termin' => 'event', 'sitzungen' => 'sessions', 'kohorte' => 'cohort'] as $alt => $neu) {
            DB::table('products')->insert([
                'handle' => 'alt-'.$alt,
                'name' => 'Alt '.$alt,
                'type' => $alt,
                'ref' => 'irgendwas',
                'amount_cent' => 1000,
                'digital' => true,
                'active' => true,
                'brand_id' => 0,
            ]);
        }

        // Die Migration selbst, nicht `artisan migrate`: die Suite hat sie in
        // `setUp()` schon gefahren, und ein zweiter Lauf taete nichts.
        (require __DIR__.'/../../database/migrations/2026_08_30_170000_rename_product_types_to_english.php')->up();

        foreach (['zugang' => 'access', 'termin' => 'event', 'sitzungen' => 'sessions', 'kohorte' => 'cohort'] as $alt => $neu) {
            $this->assertSame($neu, Product::firstWhere('handle', 'alt-'.$alt)->type);
        }

        // And every value the model knows is one this addon's siblings would
        // recognise as their own spelling: lowercase ASCII, no German.
        foreach (Product::types() as $type) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $type);
        }
    }

    #[Test]
    public function a_product_written_before_kinds_existed_is_a_download(): void
    {
        // The column's default, and the only reason it has one. Rows exist that
        // were written before this migration; `download` is the honest answer
        // for a row that carries no pointer.
        DB::table('products')->insert([
            'handle' => 'alt',
            'name' => 'Alt',
            'amount_cent' => 1000,
            'digital' => true,
            'active' => true,
            'brand_id' => 0,
        ]);

        $this->assertSame(Product::TYPE_DOWNLOAD, Product::firstWhere('handle', 'alt')->type);
    }

    #[Test]
    public function the_kind_has_to_be_chosen_and_has_to_be_one_of_the_six(): void
    {
        $ohne = $this->valid();
        unset($ohne['type']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $ohne)
            ->assertJsonValidationErrors('type');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['type' => 'kursplayer']))
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function every_kind_but_a_download_needs_a_pointer(): void
    {
        foreach (Product::typesNeedingRef() as $type) {
            $this->actingAs($this->user())
                ->postJson('/cp/utilities/products', $this->valid(['handle' => 'p-'.$type, 'type' => $type]))
                ->assertJsonValidationErrors('ref');
        }

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function a_download_points_at_nothing_even_if_something_was_sent(): void
    {
        // A leftover from a changed mind. Kept, it would be resolved against
        // the wrong sibling and show a name out of another product's world.
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['ref' => 'uebrig-geblieben']))
            ->assertRedirect();

        $this->assertNull(Product::firstWhere('handle', 'atemkurs')->ref);
    }

    #[Test]
    public function a_pointer_at_a_statamic_entry_shows_its_title(): void
    {
        $collection = tap(CollectionFacade::make('kurse'))->save();
        $entry = tap(EntryFacade::make()->collection($collection)->slug('atemkurs')->data(['title' => 'Atem und Stütze']))->save();

        $product = $this->produkt(['type' => Product::TYPE_ACCESS, 'ref' => $entry->id()]);

        $target = $product->refTarget();
        $this->assertSame(RefTarget::RESOLVED, $target->state);
        $this->assertSame('Atem und Stütze', $target->label);
        $this->assertFalse($target->isMissing());
    }

    #[Test]
    public function a_pointer_at_an_entry_that_is_gone_is_reported_missing(): void
    {
        // **The failure this whole field exists to surface.** Sold, paid,
        // access granted, and the identifier corresponds to nothing. Nothing
        // errors; the screen is the only thing that can say it.
        $product = $this->produkt(['type' => Product::TYPE_ACCESS, 'ref' => 'gibt-es-nicht']);

        $this->assertTrue($product->refTarget()->isMissing());
    }

    #[Test]
    public function a_pointer_at_a_collection_shows_its_title(): void
    {
        tap(CollectionFacade::make('newsletter')->title('Wochenbrief'))->save();

        $target = $this->produkt(['type' => Product::TYPE_FEED, 'ref' => 'newsletter'])->refTarget();

        $this->assertSame(RefTarget::RESOLVED, $target->state);
        $this->assertSame('Wochenbrief', $target->label);
    }

    #[Test]
    public function an_event_pointer_is_unknowable_until_that_addon_has_migrated(): void
    {
        // `statamic-events` is installed here as a dev dependency but its table
        // is not created unless a test asks for it — which is exactly the state
        // of a site in the window between `composer require` and `migrate`.
        $this->assertTrue(class_exists(Event::class));
        $this->assertFalse(Schema::hasTable('events'));

        $target = $this->produkt(['type' => Product::TYPE_EVENT, 'ref' => 'irgendwas'])->refTarget();

        $this->assertSame(RefTarget::UNKNOWABLE, $target->state);
        $this->assertFalse($target->isMissing(), 'accused a pointer nobody can check');
    }

    #[Test]
    public function an_event_pointer_resolves_against_the_real_events_schema(): void
    {
        // **Against that package's own migration and its own model**, not
        // against a table this addon invented. The whole point: if
        // `statamic-events` renames `uuid` or `title`, this test goes red here
        // instead of every date product silently turning red in the Control
        // Panel.
        $event = $this->event('Werkstatt-Tag');

        $target = $this->produkt(['type' => Product::TYPE_EVENT, 'ref' => $event->uuid])->refTarget();

        $this->assertSame(RefTarget::RESOLVED, $target->state);
        $this->assertSame('Werkstatt-Tag', $target->label);
    }

    #[Test]
    public function an_event_pointer_at_nothing_is_missing(): void
    {
        $this->event('Irgendein Termin');

        $target = $this->produkt(['type' => Product::TYPE_EVENT, 'ref' => 'gibt-es-nicht'])->refTarget();

        $this->assertTrue($target->isMissing());
    }

    #[Test]
    public function a_booking_pointer_is_unknowable_while_no_funnel_is_configured(): void
    {
        // An empty `endpoints` config is that addon's shipped state — "Empty on
        // purpose. An addon cannot know which funnels a site runs." Reading it
        // as "this funnel does not exist" would accuse every sessions product
        // on a site that has not set one up yet.
        $this->assertTrue(class_exists(Booking::class));
        $this->assertSame([], (array) config('statamic-booking.endpoints', []));

        $target = $this->produkt(['type' => Product::TYPE_SESSIONS, 'ref' => 'beratung'])->refTarget();

        $this->assertSame(RefTarget::UNKNOWABLE, $target->state);
    }

    #[Test]
    public function a_booking_pointer_resolves_to_the_funnel_label(): void
    {
        config(['statamic-booking.endpoints' => [
            'beratung' => ['secret' => 'geheim', 'label' => 'Kostenloses Erstgespräch'],
        ]]);

        $target = $this->produkt(['type' => Product::TYPE_SESSIONS, 'ref' => 'beratung'])->refTarget();

        $this->assertSame(RefTarget::RESOLVED, $target->state);
        $this->assertSame('Kostenloses Erstgespräch', $target->label);
    }

    #[Test]
    public function a_booking_pointer_at_a_funnel_that_is_not_configured_is_missing(): void
    {
        config(['statamic-booking.endpoints' => [
            'beratung' => ['secret' => 'geheim'],
        ]]);

        $target = $this->produkt(['type' => Product::TYPE_SESSIONS, 'ref' => 'abgeschafft'])->refTarget();

        $this->assertTrue($target->isMissing());
    }

    #[Test]
    public function a_kind_the_resolver_does_not_know_is_unknowable_rather_than_missing(): void
    {
        // A kind added to the model but not to `RefTarget`. The row is not
        // wrong; the resolver is behind. Accusing the row would send somebody
        // looking for a defect that is not there.
        $product = $this->produkt(['type' => Product::TYPE_ACCESS, 'ref' => 'egal']);
        DB::table('products')->where('handle', 'atemkurs')->update(['type' => 'kuenftige-art']);

        $this->assertSame(RefTarget::UNKNOWABLE, $product->fresh()->refTarget()->state);
    }

    #[Test]
    public function a_broken_lookup_does_not_take_the_listing_down(): void
    {
        // **Through the listing, not through the model.** The claim in the name
        // is about a screen with twenty-five rows on it, and asserting it
        // against `refTarget()` would leave a collapse one layer higher — in the
        // resource, in the JSON — green while the sentence still reads as
        // proven.
        //
        // And the row comes back **unknowable**, not missing: a lookup that
        // threw has not established that the target is gone.
        $this->produkt(['handle' => 'kaputt', 'name' => 'Kaputt', 'type' => Product::TYPE_ACCESS, 'ref' => 'egal']);
        $this->produkt(['handle' => 'datei', 'name' => 'Datei']);

        EntryFacade::swap(new class
        {
            public function query(): never
            {
                throw new RuntimeException('the content repository is having a day');
            }

            public function find(string $id): never
            {
                throw new RuntimeException('the content repository is having a day');
            }
        });

        $rows = collect(
            $this->actingAs($this->user())->getJson('/cp/utilities/products')->assertOk()->json('data')
        )->keyBy('handle');

        $this->assertCount(2, $rows, 'the listing lost rows over one broken lookup');
        $this->assertFalse($rows['kaputt']['ref_missing'], 'a lookup that threw accused the row');
        $this->assertFalse($rows['datei']['ref_missing']);
    }

    #[Test]
    public function the_listing_asks_the_events_table_once_and_not_once_per_row(): void
    {
        // **Counted against the kind that is actually batched.** An earlier
        // version of this test used access products, which `prime()` never
        // touches — it would have stayed green with the batching deleted, which
        // is precisely the failure its own comment claims to rule out.
        //
        // Dates are the batched kind, and one `select ... from events` for six
        // of them is the whole claim.
        $event = $this->event('Werkstatt-Tag');

        foreach (range(1, 6) as $i) {
            $this->produkt([
                'handle' => 'termin-'.$i,
                'name' => 'Termin '.$i,
                'type' => Product::TYPE_EVENT,
                // Five point at the same date, one at nothing: the memo must
                // not paper over a batch that never ran, and a miss has to stay
                // a miss.
                'ref' => $i === 6 ? 'gibt-es-nicht' : $event->uuid,
            ]);
        }

        $selects = 0;
        DB::listen(function ($query) use (&$selects) {
            if (str_contains(strtolower($query->sql), 'from "events"')) {
                $selects++;
            }
        });

        $rows = collect(
            $this->actingAs($this->user())->getJson('/cp/utilities/products')->assertOk()->json('data')
        )->keyBy('handle');

        $this->assertSame('Werkstatt-Tag', $rows['termin-1']['ref_label']);
        $this->assertTrue($rows['termin-6']['ref_missing']);
        $this->assertSame(1, $selects, 'the batch stopped batching: one query per row is back');
    }

    #[Test]
    public function the_screen_says_how_many_products_point_at_nothing(): void
    {
        // The badge sits on a column, and every column in the Control Panel can
        // be switched off — the name included. A catalogue that reads as tidy
        // because somebody hid a column is the silent failure this field exists
        // against, so the number lives above the table where no preference
        // reaches it.
        $this->produkt(['handle' => 'weg-1', 'name' => 'Weg 1', 'type' => Product::TYPE_ACCESS, 'ref' => 'gibt-es-nicht']);
        $this->produkt(['handle' => 'weg-2', 'name' => 'Weg 2', 'type' => Product::TYPE_COHORT, 'ref' => 'auch-nicht']);
        $this->produkt(['handle' => 'heil', 'name' => 'Heil']);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('danglingCount', 2));
    }

    #[Test]
    public function the_kind_never_makes_the_addon_deliver_anything(): void
    {
        // **The line this addon does not cross, written as a test.** Kajabi and
        // Podia make the kind the delivery: the course type *is* the player.
        // Here it stays an answer. What reaches the payment catalogue is the
        // price, the name, the tax fact and the access slugs — and no kind, no
        // pointer, nothing a checkout could act on.
        $entry = tap(
            EntryFacade::make()
                ->collection(tap(CollectionFacade::make('kurse'))->save())
                ->slug('atemkurs')
                ->data(['title' => 'Atem und Stütze'])
        )->save();

        $product = $this->produkt(['type' => Product::TYPE_ACCESS, 'ref' => $entry->id()]);

        $entry = app(Catalogue::class)->find('atemkurs');

        $this->assertArrayNotHasKey('type', $entry);
        $this->assertArrayNotHasKey('ref', $entry);
        // `brand_id` seit 1.6.1: wem die Zeile gehoert, nicht was geliefert
        // wird. Die Grenze oben bleibt damit unangetastet — es ist eine
        // Zuordnung fuer Rechnungsserie und Absender, nichts, worauf eine
        // Kasse handeln koennte.
        $this->assertSame(
            ['handle', 'name', 'amount_cent', 'currency', 'digital', 'brand_id'],
            array_keys($product->toCatalogueEntry()),
        );
    }

    #[Test]
    public function changing_the_kind_to_a_download_clears_the_pointer(): void
    {
        // Changing your mind is a normal edit and must not require clearing the
        // field by hand first.
        $product = $this->produkt(['type' => Product::TYPE_ACCESS, 'ref' => 'irgendeine-id']);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->valid([
                'type' => Product::TYPE_DOWNLOAD,
                'ref' => 'irgendeine-id',
            ]))
            ->assertRedirect();

        $this->assertNull($product->fresh()->ref);
    }

    #[Test]
    public function the_listing_flags_a_missing_target_and_leaves_an_uncheckable_one_alone(): void
    {
        $this->produkt(['handle' => 'weg', 'name' => 'Weg', 'type' => Product::TYPE_ACCESS, 'ref' => 'gibt-es-nicht']);
        $this->produkt(['handle' => 'ungewiss', 'name' => 'Ungewiss', 'type' => Product::TYPE_EVENT, 'ref' => 'irgendwas']);
        $this->produkt(['handle' => 'datei', 'name' => 'Datei', 'type' => Product::TYPE_DOWNLOAD]);

        $rows = collect(
            $this->actingAs($this->user())->getJson('/cp/utilities/products')->assertOk()->json('data')
        )->keyBy('handle');

        $this->assertTrue($rows['weg']['ref_missing']);
        $this->assertFalse($rows['ungewiss']['ref_missing'], 'accused a pointer nobody can check');
        $this->assertFalse($rows['datei']['ref_missing']);
    }
}
