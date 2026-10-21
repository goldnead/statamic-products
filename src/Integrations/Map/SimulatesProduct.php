<?php

namespace Goldnead\StatamicProducts\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicProducts\Models\Product;

/**
 * What a product would do in a dry run. Part of {@see ProductMapObjects}: the
 * map registry finds these methods with `method_exists`, so they live on the provider.
 *
 * Nothing is charged, invoiced, sent, queued or granted. The product is read and the
 * steps are described as effects. The rules follow the code they describe: the checkout
 * resolves a product only while it is active (ServiceProvider::bootCatalogue()), and a
 * product it cannot resolve refuses the purchase (Checkout::start()).
 *
 * @phpstan-require-extends ProductMapObjects
 */
trait SimulatesProduct
{
    /** Where a path can begin: a person buys the product. */
    public function paths(string $id): array
    {
        return $this->resolveProduct($id) !== null ? [new SimEvent(SimEvent::PURCHASED)] : [];
    }

    /** The dry run shows the grants and the purchase, so it is as guarded as the panel details. */
    public function simulationAuthorized(string $id, $user): bool
    {
        return $this->detailsAuthorized($id, $user);
    }

    /** @return list<Effect> */
    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        $product = $this->resolveProduct($id);

        if ($product === null) {
            return [Effect::unknown(__('statamic-products::messages.sim_missing'))];
        }

        if (! $product->active) {
            return [Effect::stop(__('statamic-products::messages.sim_inactive'))];
        }

        $handle = (string) $product->handle;
        $slugs = $product->grantSlugs();

        $line = [
            new Effect(
                Effect::UNKNOWN,
                __('statamic-products::messages.sim_checkout'),
                __('statamic-products::messages.sim_checkout_detail'),
            ),
        ];

        foreach ($slugs as $slug) {
            $line[] = Effect::access(
                __('statamic-products::messages.sim_access', ['slug' => $slug]),
                null,
                ['slug' => $slug],
            );
        }

        // No access, nothing to announce: the emit is only there when something is granted.
        $tail = $slugs === [] ? [] : [Effect::emit(
            new SimEvent(SimEvent::GRANTED, via: ['triggers', 'unlocks'], data: ['slugs' => $slugs, 'product' => $handle]),
            __('statamic-products::messages.sim_granted', ['product' => (string) $product->name]),
        )];

        return Effect::chain($line, $tail);
    }

    /** The product with this handle, read the way {@see ProductMapObjects::find()} reads it. */
    protected function resolveProduct(string $id): ?Product
    {
        return Product::query()
            ->forBrand()
            ->where('handle', $id)
            ->first();
    }
}
