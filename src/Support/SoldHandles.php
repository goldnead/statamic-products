<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which handles have taken money, asked once for a whole page.
 *
 * `Product::hasBeenSold()` is two queries. A listing renders twenty-five rows
 * and asks each of them, which is fifty queries for a screen whose real
 * question — "which of these twenty-five handles appear in the payment tables"
 * — is two.
 *
 * Primed by the listing before the rows are built. Unprimed, every lookup falls
 * back to the per-row query, so nothing here can make an answer *wrong*, only
 * slower. That asymmetry is deliberate: this is a cache in front of a guard
 * that refuses edits, and a cache that could turn a "no" into a "yes" would
 * unlock the one change that cannot be undone.
 */
final class SoldHandles
{
    /** @var array<string, bool>|null */
    private static ?array $memo = null;

    /**
     * Answer for these handles in two queries instead of two per handle.
     *
     * @param  array<array-key, mixed>  $handles  Straight from a `pluck()`, so
     *                                            unchecked. Checked here rather than at the call site because a
     *                                            nonsense handle in a `whereIn` is a query error, not a miss.
     */
    public static function prime(array $handles): void
    {
        $handles = array_values(array_unique(array_filter(
            $handles,
            static fn (mixed $handle): bool => is_string($handle) && $handle !== '',
        )));

        if ($handles === []) {
            self::$memo = [];

            return;
        }

        try {
            // **Paid, not merely started.** `Checkout::start()` writes both
            // tables before it calls the provider, at status `initiated`, and
            // nothing clears those rows by default. Counting them froze a
            // handle because somebody opened a checkout and closed the tab.
            // Kept byte-for-byte in step with `Product::hasBeenSold()`: two
            // spellings of one rule is how a listing and a form come to
            // disagree about the same product.
            $sold = array_merge(
                PaymentItem::query()
                    ->whereIn('product', $handles)
                    ->whereHas('payment', fn ($query) => $query->where('status', Payment::STATUS_PAID))
                    ->distinct()
                    ->pluck('product')
                    ->all(),
                // The single-product column from before line items existed. Rows
                // written then are exactly the old ones this guard is for.
                Payment::query()
                    ->whereIn('product', $handles)
                    ->where('status', Payment::STATUS_PAID)
                    ->distinct()
                    ->pluck('product')
                    ->all(),
            );
        } catch (Throwable $e) {
            Log::warning('statamic-products: could not read the payment tables; every handle on this screen is treated as sold and stays put.', [
                'exception' => $e->getMessage(),
            ]);

            // Fail closed, the same way the per-row check does.
            self::$memo = array_fill_keys($handles, true);

            return;
        }

        self::$memo = array_fill_keys($handles, false);

        foreach ($sold as $handle) {
            if (is_string($handle)) {
                self::$memo[$handle] = true;
            }
        }
    }

    /**
     * The primed answer, or null when this handle was not part of the batch.
     *
     * Null rather than false for an unknown handle. False would mean "not
     * sold", and answering that about something never looked up is how a cache
     * unlocks a frozen field.
     */
    public static function get(string $handle): ?bool
    {
        return self::$memo[$handle] ?? null;
    }

    /** Between requests, and between tests. */
    public static function forget(): void
    {
        self::$memo = null;
    }
}
