<?php

namespace Goldnead\StatamicProducts\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Money;
use Goldnead\StatamicProducts\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        return new MapMetric(
            $this->paidOrders($id, $period),
            __('statamic-products::messages.map_metric_orders'),
            $period,
        );
    }

    /**
     * Optional detail blocks for the map's side panel (Landkarte v4 part 3):
     * one facts block with what the panel's head and the card do not already
     * say. The head carries the price ({@see toMapObject()}), the card the paid
     * orders ({@see metric()}); so neither is repeated here. What is left is the
     * billing interval and the access the purchase grants. Null for an unknown
     * product, and null when there is nothing more to say.
     *
     * @return list<array<string, mixed>>|null
     */
    public function details(string $id, MapPeriod $period): ?array
    {
        $product = Product::query()
            ->forBrand()
            ->where('handle', $id)
            ->first();

        if ($product === null) {
            return null;
        }

        $rows = [];
        $interval = is_string($product->interval) ? trim($product->interval) : '';

        if ($interval !== '') {
            $rows[] = [
                'label' => __('statamic-products::messages.map_detail_interval'),
                'value' => Str::limit($interval, 120),
                'href' => null,
            ];
        }

        $grants = $product->grantSlugs();

        if ($grants !== []) {
            $rows[] = [
                'label' => __('statamic-products::messages.map_detail_grants'),
                'value' => Str::limit(implode(', ', $grants), 120),
                'href' => null,
            ];
        }

        return $rows === [] ? null : [['kind' => 'facts', 'title' => null, 'rows' => $rows]];
    }

    /**
     * The panel shows the price and the paid orders, so only a user who may
     * see payments in the CP may open it. Without a payments table there is
     * nothing sold to hide and the product screen's own permission decides.
     * Fail closed: no user, or one that cannot answer, is a no.
     *
     * The permission is the one statamic-payments puts on its Payments utility
     * (`->can('access payments utility')` in its service provider).
     */
    public function detailsAuthorized(string $id, $user): bool
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }

        $permission = Schema::hasTable('payments') ? 'access payments utility' : 'access products utility';

        try {
            return $user->can($permission) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The price the way the CP language writes it: "199,00 €" in German, "€199.00"
     * in English; a currency without a symbol keeps its code ("SEK 199.00").
     * Separators and the order of number and symbol come from the lang files;
     * the decimals per currency from payments.
     */
    protected function money(int $cent, string $currency): string
    {
        $decimals = Money::decimals($currency);
        $symbol = Money::symbol($currency);
        $format = (string) __('statamic-products::messages.map_money_format');

        // A code ("CHF") is a word, not a sign: it needs its space in front of the number.
        if (preg_match('/\pL/u', $symbol) === 1 && str_starts_with($format, ':symbol:')) {
            $format = ':symbol :'.substr($format, 8);
        }

        return strtr($format, [
            ':amount' => number_format(
                $cent / (10 ** $decimals),
                $decimals,
                __('statamic-products::messages.map_decimal_point'),
                __('statamic-products::messages.map_thousands_separator'),
            ),
            ':symbol' => $symbol,
        ]);
    }

    /** Paid orders for the handle in the period; the one counting rule of the card and the panel. */
    protected function paidOrders(string $id, MapPeriod $period): int
    {
        $now = Carbon::now();

        return (int) Brands::only(Payment::query(), Brands::readerId())
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
    }

    /**
     * The handles of the products whose purchase grants the entitlement slug,
     * sorted. This is how another addon (automations) draws "this product
     * triggers that automation" when the automation listens to the grant and
     * not to the product handle. The answer is computed from the catalogue on
     * every call and never stored. Uses {@see Product::grantSlugs()}, the same
     * list the checkout hands to the entitlements bridge.
     *
     * @return list<string>
     */
    public function productsGranting(string $slug): array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return [];
        }

        return Product::query()
            ->forBrand()
            ->whereNotNull('grants')
            ->orderBy('handle')
            ->get()
            ->filter(fn (Product $product) => in_array($slug, $product->grantSlugs(), true))
            ->map(fn (Product $product) => (string) $product->handle)
            ->values()
            ->all();
    }

    protected function toMapObject(Product $product): MapObject
    {
        return new MapObject(
            type: $this->type(),
            id: (string) $product->handle,
            label: (string) $product->name,
            cpUrl: cp_route('utilities.products.show', $product->id),
            enabled: (bool) $product->active,
            detail: $this->money((int) $product->amount_cent, $product->currency()),
        );
    }
}
