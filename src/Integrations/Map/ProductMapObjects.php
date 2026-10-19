<?php

namespace Goldnead\StatamicProducts\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicProducts\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Products as objects the funnels map can point at.
 *
 * A product is identified by its handle, the same key the website and the
 * payments rows use, so a reference on the map keeps working when the name
 * changes. The map asks for the label, the link and the switch every time;
 * nothing here is cached.
 *
 * The metric is the number of paid orders for the product in the period. It
 * is deliberately not the revenue figure of statamic-payments' "Orders"
 * insight: the card counts orders, not money, so currencies are not split.
 * A payment counts once if it has a line for the product, or, when it has no
 * lines at all, if its single-product column names it. Only paid payments
 * whose `paid_at` lies in the period and not in the future count, in the
 * current brand.
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
     * Paid orders for the product over the period. Null when the payments
     * table is missing or the product is unknown; see the class comment.
     */
    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        if (! Schema::hasTable('payments') || $this->find($id) === null) {
            return null;
        }

        $now = Carbon::now();

        $orders = Brands::only(Payment::query(), Brands::readerId())
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $period->from)
            ->where('paid_at', '<', $period->to)
            ->where('paid_at', '<=', $now)
            ->where(fn (Builder $match) => $match
                ->whereExists(fn ($line) => $line->from('payment_items')
                    ->whereColumn('payment_items.payment_id', 'payments.id')
                    ->where('payment_items.product', $id))
                ->orWhere(fn ($legacy) => $legacy
                    ->where('product', $id)
                    ->whereNotExists(fn ($line) => $line->from('payment_items')
                        ->whereColumn('payment_items.payment_id', 'payments.id'))))
            ->count();

        return new MapMetric(
            (int) $orders,
            __('statamic-products::messages.map_metric_orders'),
            $period,
        );
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
