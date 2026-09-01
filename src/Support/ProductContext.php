<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicProducts\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * What the rest of the family knows about one product.
 *
 * The product row says what a thing is and costs. Who sells it (an offer) and
 * who bought it (a paid line item) live in two other addons, and until now the
 * only way from a product to either was to open two other screens and search.
 * This is the way back.
 *
 * Read through the query builder and not through the siblings' models, so
 * that naming a column here never loads a class of theirs — the whole point
 * of the {@see Siblings} guard in front of every call.
 */
final class ProductContext
{
    /** How many buyers the screen shows. The rest is in the payments listing. */
    public const BUYERS_LIMIT = 50;

    /**
     * Every offer that sells this product, as its lead product or in a bundle.
     *
     * Null when the offers addon is not there: "no offers" and "cannot know"
     * are different answers, and the screen shows a section for the first and
     * nothing for the second.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function offers(Product $product): ?array
    {
        if (! Siblings::installed(Siblings::OFFERS)) {
            return null;
        }

        $handle = $product->handle;

        return DB::table('offers')
            ->where(function ($query) use ($handle) {
                $query->where('product', $handle)
                    // A bundle lists its further products as JSON. The lead
                    // product sits in `product` and is not repeated there.
                    ->orWhereJsonContains('products', $handle);
            })
            ->orderByDesc('active')
            ->orderBy('name')
            ->get(['id', 'handle', 'name', 'slot', 'product', 'amount_cent', 'currency', 'active'])
            ->map(fn ($offer) => [
                'id' => (int) $offer->id,
                'handle' => (string) $offer->handle,
                'name' => (string) $offer->name,
                'slot' => (string) $offer->slot,
                'slot_label' => self::label('slot_'.$offer->slot, (string) $offer->slot),
                // An offer without a price of its own charges the list price.
                // Resolved here the way `Offer::amountCent()` resolves it, so
                // the column shows what a buyer would pay and not a blank.
                'amount' => CpNumber::decimal(($offer->amount_cent ?? $product->amount_cent) / 100, 2),
                'currency' => $offer->currency ?: $product->currency(),
                'own_price' => $offer->amount_cent !== null,
                'lead' => $offer->product === $handle,
                'active' => (bool) $offer->active,
                // The offers screen edits in a stack off its listing, so there
                // is no per-offer URL to point at. The listing searches by
                // handle; landing there filtered to one row is the nearest
                // thing to opening it.
                'url' => self::listingUrl('offers', (string) $offer->handle),
            ])
            ->all();
    }

    /**
     * The last people who paid for this product.
     *
     * Over `payment_items` joined to `payments`, so a product bought as a bump
     * or an upsell counts as bought. Paid means `status = paid`; a refund is a
     * column on a paid row and the buyer stays, which is right — they did buy.
     *
     * Null when the payments tables are not there yet.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function buyers(Product $product): ?array
    {
        if (! Siblings::installed(Siblings::PAYMENTS)) {
            return null;
        }

        return DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payment_items.product', $product->handle)
            ->where('payments.status', 'paid')
            // A handle is unique across brands, so the payments of one handle
            // are one brand's — but only as long as nobody re-stamps a row.
            // Narrowed to the product's own brand anyway: a buyer list is the
            // one place where a wrong row is a stranger's e-mail address.
            ->when(Brands::multiBrand(), fn ($query) => $query->where('payments.brand_id', (int) $product->brand_id))
            ->orderByDesc('payments.paid_at')
            ->orderByDesc('payments.id')
            ->limit(self::BUYERS_LIMIT)
            ->get([
                'payments.id as payment_id',
                'payments.email',
                'payments.name',
                'payments.paid_at',
                'payments.currency',
                'payments.refunded_cent',
                'payment_items.amount_cent',
                'payment_items.quantity',
                'payment_items.kind',
            ])
            ->map(fn ($row) => [
                'payment_id' => (int) $row->payment_id,
                'email' => $row->email,
                'name' => $row->name,
                'paid_at' => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
                // What was paid for *this* line — quantity times the line
                // price — not the whole payment, which may carry other items.
                'amount' => CpNumber::decimal(((int) $row->amount_cent * max(1, (int) $row->quantity)) / 100, 2),
                'currency' => (string) $row->currency,
                'kind' => (string) $row->kind,
                'refunded' => (int) ($row->refunded_cent ?? 0) > 0,
                // Same reasoning as the offer URL: the payments listing has no
                // detail page, and searching for the e-mail lands on the rows
                // of this buyer.
                'url' => self::listingUrl('payments', (string) ($row->email ?? '')),
            ])
            ->all();
    }

    /**
     * The sibling's listing, searched for one term — or null when that
     * listing is not registered, which is the case while the sibling boots
     * as a plain package (the test suites) or when its utility is off.
     */
    protected static function listingUrl(string $utility, string $term): ?string
    {
        if (! Route::has('statamic.cp.utilities.'.$utility)) {
            return null;
        }

        return cp_route('utilities.'.$utility).'?search='.urlencode($term);
    }

    protected static function label(string $key, string $fallback): string
    {
        $full = 'statamic-products::messages.'.$key;
        $translated = __($full);

        return is_string($translated) && $translated !== $full ? $translated : $fallback;
    }
}
