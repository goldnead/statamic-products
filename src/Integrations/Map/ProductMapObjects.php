<?php

namespace Goldnead\StatamicProducts\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicProducts\Models\Product;

/**
 * Products as objects the funnels map can point at.
 *
 * A product is identified by its handle, the same key the website and the
 * payments rows use, so a reference on the map keeps working when the name
 * changes. The map asks for the label, the link and the switch every time;
 * nothing here is cached.
 *
 * No metric. Orders for a product live in `statamic-payments`, and its own
 * "Orders" insight counts over currency and brand and a legacy single-product
 * column as well. Reproducing that count here would be a second, slightly
 * different number under the same name, so the card shows none.
 */
class ProductMapObjects implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'product';
    }

    public function typeLabel(): string
    {
        return __('statamic-products::messages.map_type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $products = Product::query()
            ->forBrand()
            ->when($query !== null && trim($query) !== '', function ($builder) use ($query) {
                $like = '%'.trim((string) $query).'%';

                $builder->where(fn ($match) => $match
                    ->where('name', 'like', $like)
                    ->orWhere('handle', 'like', $like));
            })
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get();

        return $products
            ->map(fn (Product $product) => $this->toMapObject($product))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $product = Product::query()
            ->forBrand()
            ->where('handle', $id)
            ->first();

        return $product ? $this->toMapObject($product) : null;
    }

    /**
     * Null on purpose: see the class comment.
     */
    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    protected function toMapObject(Product $product): MapObject
    {
        return new MapObject(
            type: $this->type(),
            id: (string) $product->handle,
            label: (string) $product->name,
            cpUrl: cp_route('utilities.products.show', $product->id),
            enabled: (bool) $product->active,
            detail: $product->amount().' '.$product->currency(),
        );
    }
}
