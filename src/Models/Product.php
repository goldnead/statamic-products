<?php

namespace Goldnead\StatamicProducts\Models;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicProducts\Support\RefTarget;
use Goldnead\StatamicProducts\Support\SoldHandles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thing that is sold.
 *
 * Deliberately thin. It knows what it is called, what it costs, whether it is
 * taxed as a digital supply, and what it opens. It does not know how it is
 * presented — that is an offer — and it does not know how it is delivered —
 * that is the website.
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string $type
 * @property string|null $ref
 * @property int $amount_cent
 * @property string|null $currency
 * @property bool $digital
 * @property array<array-key, mixed>|null $grants — a JSON column, so it holds
 *                                                whatever was written into it. `grantSlugs()` is the cleaned list.
 * @property bool $active
 * @property int $brand_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Product extends Model
{
    /** A file handed over. Points at nothing. */
    public const TYPE_DOWNLOAD = 'download';

    /** A course, a members area, a community. Points at a Statamic entry. */
    public const TYPE_ACCESS = 'access';

    /** A live date: workshop, concert, webinar. Points at a `statamic-events` uuid. */
    public const TYPE_EVENT = 'event';

    /** A package of sessions. Points at a `statamic-booking` funnel handle. */
    public const TYPE_SESSIONS = 'sessions';

    /** A programme with a start, an end and a group. Points at a Statamic entry. */
    public const TYPE_COHORT = 'cohort';

    /** A paid podcast or newsletter. Points at a Statamic collection handle. */
    public const TYPE_FEED = 'feed';

    protected $guarded = [];

    /**
     * The kinds a product may be.
     *
     * **Each one is an answer, never an instruction.** Naming a product a
     * `event` says it is a live date; nothing in this addon reserves a seat
     * because of it. Delivery lives on the website and in sibling addons — some
     * of which are not built yet — and the day one of them wants to act on a
     * kind, it reads this field and does so itself.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_DOWNLOAD,
            self::TYPE_ACCESS,
            self::TYPE_EVENT,
            self::TYPE_SESSIONS,
            self::TYPE_COHORT,
            self::TYPE_FEED,
        ];
    }

    /**
     * Kinds that name a thing somewhere else, and therefore need a pointer.
     *
     * A download is the only one that does not: the thing *is* the product.
     *
     * @return list<string>
     */
    public static function typesNeedingRef(): array
    {
        return array_values(array_diff(self::types(), [self::TYPE_DOWNLOAD]));
    }

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'digital' => 'boolean',
            'grants' => 'array',
            'active' => 'boolean',
            'brand_id' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            // Whose product this is. Zero on every single-brand install, and a
            // real outcome in multi-brand mode where nothing said which brand —
            // a console command, a seeder. The Control Panel then shows it to
            // nobody, which is the fail-closed half of the same decision in
            // `statamic-payments`.
            if ($product->getAttribute('brand_id') === null) {
                $product->setAttribute('brand_id', Brands::stampId());
            }
        });
    }

    /**
     * Narrow a query to what the current brand may see.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBrand(Builder $query, ?int $brandId = null): Builder
    {
        // `readerId()`, never `stampId()`. The second answers "whose row is
        // this about to be" and lands on zero where no brand is current;
        // handed to `only()` that reads as "show the rows nobody claimed"
        // rather than "show nothing", which is a listing that looks right
        // everywhere except the one place it is wrong.
        return Brands::only($query, $brandId ?? Brands::readerId());
    }

    /**
     * The shape `statamic-payments` speaks.
     *
     * Everything the catalogue is allowed to know about this product, and
     * nothing else. `handle`, `currency` and `name` are filled in by
     * `Catalogue::find()` itself where they are missing, but they are written
     * out here anyway: this same array is what `contribute()` hands to a
     * product picker, and a picker with no labels is not a picker.
     *
     * @return array<string, mixed>
     */
    public function toCatalogueEntry(): array
    {
        $entry = [
            'handle' => $this->handle,
            'name' => $this->name,
            'amount_cent' => $this->amount_cent,
            'currency' => $this->currency(),
            'digital' => $this->digital,
        ];

        // Omitted rather than sent as an empty list. `statamic-payments` reads
        // a missing key as "grants nothing" and an empty array as the same, but
        // the entitlements bridge logs the difference — and a product that was
        // never meant to open anything should not appear in that log every time
        // it is sold.
        $grants = $this->grantSlugs();

        if ($grants !== []) {
            $entry['grants'] = $grants;
        }

        return $entry;
    }

    /**
     * The access slugs this product opens, cleaned.
     *
     * A row read back from the table can hold anything the column allowed —
     * nulls from a half-filled form, an integer somebody typed. Handing those
     * to the entitlements bridge is how a purchase ends with a payment, an
     * invoice and no access, which has already happened twice in this family
     * and made no noise either time.
     *
     * @return list<string>
     */
    public function grantSlugs(): array
    {
        return array_values(array_unique(array_filter(
            (array) ($this->grants ?? []),
            static fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '',
        )));
    }

    public function currency(): string
    {
        // `$this->currency` would be a trap: this method is *called* `currency`,
        // so on a model built in memory rather than read back from the table
        // Eloquent falls through to relation resolution and tries to call this
        // very method as a relation. The same bite as in `statamic-offers`.
        $own = $this->attributes['currency'] ?? null;

        if (is_string($own) && $own !== '') {
            return $own;
        }

        return (string) config('statamic-payments.currency', 'EUR');
    }

    /**
     * Whether this handle is also a line in the config file.
     *
     * Config wins in the catalogue, silently and on purpose — a price in
     * version control must not be overruled by a row. Silent is right for the
     * *answer* and wrong for the *screen*: two truths about one price is
     * exactly the illness that had a checkout charge 330 while the catalogue
     * said 332, and the person who noticed was a customer.
     */
    public function isShadowedByConfig(): bool
    {
        return array_key_exists($this->handle, app(Catalogue::class)->configured());
    }

    /**
     * Whether this handle has ever been **paid** for.
     *
     * A handle is a name in version-less places: it sits on payment rows and
     * invoice lines that have to render years from now, and none of them can be
     * migrated when somebody tidies up a spelling. Renaming after a sale
     * therefore does not break anything loudly — it makes an old invoice show a
     * line whose product cannot be found any more.
     *
     * **Paid, not merely started, and that distinction is the whole method.**
     * `Checkout::start()` writes a `payments` row and its `payment_items`
     * *before* it calls the provider, at status `initiated`. Matching any row
     * meant one abandoned checkout froze the handle and made deletion refuse —
     * for ever, because `prune_unpaid_after_days` ships at `0` and nothing
     * clears those rows. A visitor who opened the checkout and closed the tab
     * could permanently lock a product nobody ever bought.
     *
     * A refund does not unfreeze it: refunds are columns on a paid row, the
     * status stays `paid`, and the invoice still exists.
     *
     * Both tables are asked. `payments.product` is the single-product column
     * from before line items existed, and rows written then are exactly the old
     * ones this guard is for.
     */
    public function hasBeenSold(): bool
    {
        $handle = (string) $this->getOriginal('handle', $this->handle);

        if ($handle === '') {
            return false;
        }

        // A listing asks this of every row. `SoldHandles::prime()` answers the
        // whole page in two queries; unprimed, or for a handle outside the
        // batch, it says nothing and the two queries below run as normal.
        $primed = SoldHandles::get($handle);

        if ($primed !== null) {
            return $primed;
        }

        // Fail *closed*: if the payment tables cannot be read, the safe answer
        // is "assume it was sold" and refuse the rename. The alternative lets a
        // transient database error unlock the one edit that cannot be undone.
        try {
            return Payment::query()
                ->where('product', $handle)
                ->where('status', Payment::STATUS_PAID)
                ->exists()
                || PaymentItem::query()
                    ->where('product', $handle)
                    ->whereHas('payment', fn ($query) => $query->where('status', Payment::STATUS_PAID))
                    ->exists();
        } catch (Throwable $e) {
            Log::warning('statamic-products: could not check whether a product has been sold; treating it as sold so its handle stays put.', [
                'handle' => $handle,
                'exception' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * What this product's `ref` points at, if anything can say.
     *
     * Three states, not two: found, gone, and nobody-here-can-tell. See
     * {@see RefTarget}.
     */
    public function refTarget(): RefTarget
    {
        return RefTarget::for($this);
    }

    /** The price as a decimal string: always a dot, always two decimals. */
    public function amount(): string
    {
        return number_format($this->amount_cent / 100, 2, '.', '');
    }
}
