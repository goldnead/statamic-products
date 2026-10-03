<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * „Schaltet frei (Zugänge)" am Produkt: ein Picker ueber die Zugaenge, der
 * weiter Slugs speichert.
 *
 * Zahlungen und Vergaben lesen `grants` als Liste von Slugs und duerfen vom
 * Picker nichts merken. Slugs ohne Zugang-Datensatz sind Bestand (die Website
 * fuehrt sie bis zur Uebernahme selbst) und bleiben gueltig.
 */
class ProductGrantsPickerTest extends TestCase
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
            'handle' => 'accelerator-kauf',
            'name' => 'ChoirAccelerator',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 99700,
            'digital' => true,
            'active' => true,
        ], $overrides);
    }

    #[Test]
    public function the_form_offers_the_accesses_by_name(): void
    {
        Access::create(['handle' => 'choiraccelerator', 'name' => 'ChoirAccelerator']);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/new')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('form.accesses.0.value', 'choiraccelerator')
                ->where('form.accesses.0.label', 'ChoirAccelerator'));
    }

    #[Test]
    public function grants_are_still_stored_as_slugs(): void
    {
        Access::create(['handle' => 'choiraccelerator', 'name' => 'ChoirAccelerator']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['grants' => ['choiraccelerator']]))
            ->assertRedirect();

        $product = Product::firstWhere('handle', 'accelerator-kauf');
        $this->assertSame(['choiraccelerator'], $product->grants);
        $this->assertSame(['choiraccelerator'], $product->toCatalogueEntry()['grants']);
    }

    #[Test]
    public function a_slug_without_an_access_record_is_accepted_and_shown_as_unresolved(): void
    {
        Access::create(['handle' => 'choiraccelerator', 'name' => 'ChoirAccelerator']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid(['grants' => ['choiraccelerator', 'free-resources']]))
            ->assertRedirect();

        $product = Product::firstWhere('handle', 'accelerator-kauf');
        $this->assertSame(['choiraccelerator', 'free-resources'], $product->grants);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.unresolved_grants', ['free-resources']));
    }

    #[Test]
    public function the_pointer_is_optional_when_a_granted_access_carries_the_contents(): void
    {
        Access::create(['handle' => 'kurs-vokale', 'name' => 'Kurs Vokale']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid([
                'type' => Product::TYPE_ACCESS,
                'grants' => ['kurs-vokale'],
            ]))
            ->assertRedirect();

        $this->assertNull(Product::firstWhere('handle', 'accelerator-kauf')->ref);
    }

    #[Test]
    public function the_pointer_stays_required_when_no_granted_slug_is_an_access(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/products', $this->valid([
                'type' => Product::TYPE_ACCESS,
                'grants' => ['nur-ein-slug'],
            ]))
            ->assertJsonValidationErrors('ref');

        $this->assertSame(0, Product::count());
    }
}
