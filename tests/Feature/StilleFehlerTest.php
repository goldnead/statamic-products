<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Two silent failures of the Control Panel controllers.
 *
 * 1. A PATCH that leaves out `interval` / `currency` must not empty the
 *    subscription fields and the currency, answer 200 and say nothing.
 * 2. update and destroy must filter by brand like show() does.
 */
class StilleFehlerTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

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

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'handle' => 'abo',
            'name' => 'Abo',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 1000,
            'digital' => true,
        ], $overrides);
    }

    protected function abo(array $overrides = []): Product
    {
        return Product::create($this->form(array_merge([
            'interval' => '1 month',
            'times' => 12,
            'trial_days' => 14,
            'trial_amount_cent' => 0,
            'currency' => 'CHF',
            'active' => true,
        ], $overrides)));
    }

    #[Test]
    public function a_patch_without_interval_and_currency_keeps_the_subscription_fields(): void
    {
        $product = $this->abo();

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->form(['amount_cent' => 1200]))
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame(1200, $fresh->amount_cent);
        $this->assertSame('1 month', $fresh->interval);
        $this->assertSame(12, $fresh->times);
        $this->assertSame(14, $fresh->trial_days);
        $this->assertSame(0, $fresh->trial_amount_cent);
        $this->assertSame('CHF', $fresh->currency);
    }

    #[Test]
    public function an_explicitly_empty_interval_still_clears_the_dependent_fields(): void
    {
        $product = $this->abo();

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$product->id, $this->form(['interval' => '', 'currency' => '']))
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertNull($fresh->interval);
        $this->assertNull($fresh->times);
        $this->assertNull($fresh->trial_days);
        $this->assertNull($fresh->trial_amount_cent);
        $this->assertNull($fresh->currency);
    }

    #[Test]
    public function another_brands_product_cannot_be_updated_or_deleted(): void
    {
        $this->marke(current: 1);
        $fremd = Product::create($this->form(['handle' => 'fremd', 'brand_id' => 2]));

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$fremd->id, $this->form(['handle' => 'fremd', 'name' => 'Gekapert']))
            ->assertNotFound();
        $this->actingAs($this->user())
            ->deleteJson('/cp/utilities/products/'.$fremd->id)
            ->assertNotFound();

        $this->assertSame('Abo', $fremd->fresh()->name);
        $this->assertNotNull(Product::find($fremd->id));
    }

    #[Test]
    public function the_own_brands_product_can_be_updated_and_deleted(): void
    {
        $this->marke(current: 1);
        $eigen = Product::create($this->form(['handle' => 'eigen', 'brand_id' => 1]));

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$eigen->id, $this->form(['handle' => 'eigen', 'name' => 'Neu']))
            ->assertRedirect();
        $this->assertSame('Neu', $eigen->fresh()->name);

        $this->actingAs($this->user())->deleteJson('/cp/utilities/products/'.$eigen->id)->assertRedirect();
        $this->assertNull(Product::find($eigen->id));
    }

    #[Test]
    public function another_brands_access_cannot_be_updated_or_deleted(): void
    {
        $this->marke(current: 1);
        $fremd = Access::create(['handle' => 'fremd', 'name' => 'Fremd', 'brand_id' => 2]);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$fremd->id, ['handle' => 'fremd', 'name' => 'Gekapert'])
            ->assertNotFound();
        $this->actingAs($this->user())
            ->deleteJson('/cp/utilities/product-accesses/'.$fremd->id)
            ->assertNotFound();

        $this->assertSame('Fremd', $fremd->fresh()->name);
        $this->assertNotNull(Access::find($fremd->id));
    }

    #[Test]
    public function the_own_brands_access_can_be_updated_and_deleted(): void
    {
        $this->marke(current: 1);
        $eigen = Access::create(['handle' => 'eigen', 'name' => 'Eigen', 'brand_id' => 1]);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$eigen->id, ['handle' => 'eigen', 'name' => 'Neu'])
            ->assertRedirect();
        $this->assertSame('Neu', $eigen->fresh()->name);

        $this->actingAs($this->user())->deleteJson('/cp/utilities/product-accesses/'.$eigen->id)->assertRedirect();
        $this->assertNull(Access::find($eigen->id));
    }

    #[Test]
    public function a_single_brand_install_is_unchanged(): void
    {
        $this->marke(multi: false);
        $p = Product::create($this->form(['handle' => 'solo']));
        $a = Access::create(['handle' => 'solo', 'name' => 'Solo']);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/products/'.$p->id, $this->form(['handle' => 'solo', 'name' => 'Neu']))
            ->assertRedirect();
        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$a->id, ['handle' => 'solo', 'name' => 'Neu'])
            ->assertRedirect();
        $this->assertSame('Neu', $p->fresh()->name);
        $this->assertSame('Neu', $a->fresh()->name);
    }
}
