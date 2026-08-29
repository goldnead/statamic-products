<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * One Control Panel, three catalogues.
 *
 * An agency with three brands must not be able to put another brand's product
 * into its own offer. The failure would be quiet — a picker with too many rows
 * looks like a picker — so it is asserted rather than trusted.
 */
class BrandScopeTest extends TestCase
{
    /**
     * Bind a stand-in for statamic-brand-context's manager.
     *
     * No more permissive than the real one: it answers exactly the questions
     * `Brands` asks and nothing else.
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

    protected function produkt(string $handle, int $brand): Product
    {
        return Product::create([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'amount_cent' => 1000,
            'digital' => true,
            'active' => true,
            'brand_id' => $brand,
        ]);
    }

    #[Test]
    public function a_picker_only_offers_the_current_brands_products(): void
    {
        $this->marke(current: 1);

        $this->produkt('nordlicht-kurs', 1);
        $this->produkt('halbmond-vinyl', 2);

        $all = app(Catalogue::class)->all();

        $this->assertArrayHasKey('nordlicht-kurs', $all);
        $this->assertArrayNotHasKey('halbmond-vinyl', $all);
    }

    #[Test]
    public function no_brand_current_offers_nothing_rather_than_the_unassigned_rows(): void
    {
        // Fail-closed. Zero is the brand of every product created by a console
        // command or a seeder, and "the rows nobody claimed" is not an answer
        // to "what may this reader sell".
        $this->marke(current: null);

        $this->produkt('niemandes-kurs', 0);
        $this->produkt('nordlicht-kurs', 1);

        $this->assertSame(
            app(Catalogue::class)->configured(),
            app(Catalogue::class)->all(),
        );
    }

    #[Test]
    public function a_price_still_resolves_where_no_brand_is_current(): void
    {
        // **The half that must NOT be scoped.** A provider webhook arrives
        // hours after the sale with no brand and no session, and it has to be
        // able to price what it was sent. A catalogue that went fail-closed
        // here would refuse to fulfil a payment that already went through.
        $this->marke(current: null);

        $this->produkt('nordlicht-kurs', 1);

        $this->assertSame(1000, app(Catalogue::class)->find('nordlicht-kurs')['amount_cent']);
    }

    #[Test]
    public function a_single_brand_install_sees_everything(): void
    {
        // Nearly every install. Filtering on a column that is zero everywhere
        // would be theatre, and a listing that filtered anyway would be empty.
        $this->marke(multi: false);

        $this->produkt('kurs', 0);
        $this->produkt('workbook', 0);

        $all = app(Catalogue::class)->all();

        $this->assertArrayHasKey('kurs', $all);
        $this->assertArrayHasKey('workbook', $all);
    }

    #[Test]
    public function without_the_sibling_installed_nothing_is_scoped(): void
    {
        $this->produkt('kurs', 0);

        $this->assertFalse($this->app->bound('brand-context'));
        $this->assertArrayHasKey('kurs', app(Catalogue::class)->all());
    }
}
