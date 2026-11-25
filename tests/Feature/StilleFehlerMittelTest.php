<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\SoldHandles;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Mittlere stille Fehler, Befunde 6 und 8 aus dem Sweep vom 24.11.2026.
 *
 * 6. Ein verkauftes Produkt darf seinen Handle auch am Modell nicht aendern,
 *    nicht nur im Controller (Zugaenge und Kaeufe haengen am Handle).
 * 8. Der Hinweisbanner zaehlt kaputte Ziele (MISSING). Ziele, die nicht
 *    geprueft werden konnten (UNKNOWABLE), duerfen nicht mehr still
 *    herausfallen, sondern werden getrennt gezaehlt.
 */
class StilleFehlerMittelTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function produkt(array $overrides = []): Product
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

    protected function verkauft(string $handle): void
    {
        Payment::create([
            'product' => $handle,
            'provider_id' => 'tr_'.$handle,
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'provider' => 'fake',
        ]);

        SoldHandles::forget();
    }

    // ---- 6: Modell schuetzt den Handle eines verkauften Produkts ---------------

    #[Test]
    public function the_model_refuses_renaming_a_sold_product(): void
    {
        $product = $this->produkt(['handle' => 'atemkurs']);
        $this->verkauft('atemkurs');

        try {
            $product->update(['handle' => 'umbenannt']);
            $this->fail('Der Handle eines verkauften Produkts wurde am Modell geaendert.');
        } catch (ValidationException) {
            // erwartet
        }

        $this->assertSame('atemkurs', $product->fresh()->handle);
    }

    #[Test]
    public function an_unsold_product_can_still_be_renamed_at_model_level(): void
    {
        $product = $this->produkt(['handle' => 'entwurf']);

        $product->update(['handle' => 'entwurf-neu']);

        $this->assertSame('entwurf-neu', $product->fresh()->handle);
    }

    // ---- 8: unpruefbare Ziele werden getrennt gezaehlt -------------------------

    #[Test]
    public function the_products_screen_counts_unknowable_apart_from_missing(): void
    {
        // Event-Zeiger ohne installierte Events-Tabelle: nicht pruefbar.
        $this->produkt(['handle' => 'termin', 'name' => 'Termin', 'type' => Product::TYPE_EVENT, 'ref' => 'irgendwas']);
        // Zugang, den es nicht gibt: kaputt.
        $this->produkt(['handle' => 'weg', 'name' => 'Weg', 'type' => Product::TYPE_ACCESS, 'ref' => 'gibt-es-nicht']);
        $this->produkt(['handle' => 'heil', 'name' => 'Heil']);

        $this->actingAs($this->user())
            ->get('/cp/utilities/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('danglingCount', 1)
                ->where('unknowableCount', 1)
                ->whereNot('unknowableBanner', null));
    }

    #[Test]
    public function the_accesses_screen_counts_unknowable_apart_from_missing(): void
    {
        // Inhalt einer Art, die niemand aufloest: nicht pruefbar.
        Access::query()->create([
            'handle' => 'innen',
            'name' => 'Innen',
            'active' => true,
            'contents' => [['kind' => 'library', 'ref' => 'x']],
        ]);
        // Inhalt, der auf einen fehlenden Zugang zeigt: kaputt.
        Access::query()->create([
            'handle' => 'aussen',
            'name' => 'Aussen',
            'active' => true,
            'contents' => [['kind' => 'access', 'ref' => 'gibt-es-nicht']],
        ]);

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('missingCount', 1)
                ->where('unknowableCount', 1)
                ->whereNot('unknowableBanner', null));
    }
}
