<?php

namespace Goldnead\StatamicProducts\Support;

use Goldnead\StatamicProducts\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Entries\Entry as CoreEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Throwable;

/**
 * What a product's `ref` points at, if anything can say.
 *
 * **Three answers, not two, and that is the point of this class.** "Points at
 * nothing" and "nothing here can tell you" are different facts, and collapsing
 * them is how a screen ends up accusing a perfectly good product of being
 * broken — or, the other way round, calling a dangling pointer fine because the
 * package that would have noticed is not installed.
 *
 * - {@see self::RESOLVED} — found it, and here is its name.
 * - {@see self::MISSING} — the sibling that owns this kind of thing is here and
 *   says there is no such thing. That is a real defect: sold, paid, nothing behind it.
 * - {@see self::UNKNOWABLE} — the sibling is not installed, or its table has not
 *   been migrated. Nothing is wrong with the product; nobody can confirm it either.
 *
 * A product may be filed for a kind whose sibling does not exist yet. The LMS
 * and the community addon are not built; adriangoldner.com runs both today
 * without them. Refusing to catalogue what they will sell would be the tail
 * wagging the dog.
 */
final class RefTarget
{
    public const RESOLVED = 'resolved';

    public const MISSING = 'missing';

    public const UNKNOWABLE = 'unknowable';

    /**
     * Answers already worked out this request, keyed by `type|ref`.
     *
     * @var array<string, self>
     */
    private static array $memo = [];

    /** Whether `statamic-events` is here and migrated. Constant per request. */
    private static ?bool $eventsAvailable = null;

    private function __construct(
        public readonly string $state,
        public readonly ?string $label = null,
    ) {}

    /**
     * Answer for a whole page of products with one query per kind.
     *
     * Without this the listing asks per row, and the row it asks costs a
     * `Schema::hasTable()` **plus** a lookup — twenty-five rows with ten dates
     * on them is ten identical schema checks and eighteen separate selects for
     * a question that is two `whereIn`s. `SoldHandles` twenty lines away exists
     * for exactly this reason; not doing it here would have been the same
     * mistake with a different column.
     *
     * Priming can only make an answer *faster*, never different: `for()` falls
     * back to the single-row path for anything the batch did not cover.
     *
     * @param  iterable<Product>  $products
     */
    public static function prime(iterable $products): void
    {
        $wanted = [];

        foreach ($products as $product) {
            $ref = trim((string) ($product->ref ?? ''));

            if ($ref === '' || $product->type === Product::TYPE_DOWNLOAD) {
                continue;
            }

            $wanted[$product->type][$ref] = true;
        }

        self::primeEvents(array_keys($wanted[Product::TYPE_TERMIN] ?? []));

        // **Entries, collections and booking funnels are deliberately not
        // batched**, and that is a decision rather than an omission.
        //
        // All three already answer from memory: Statamic keeps entries and
        // collections in the Stache, and booking funnels are a config array.
        // The per-request memo in `for()` collapses repeats on top of that, so
        // a page of twenty-five products asks for twenty-five *distinct*
        // pointers at most.
        //
        // What batching them would cost is worse than what it saves.
        // `Statamic\Contracts\Entries\QueryBuilder` is an **empty interface**
        // — like the `Entry` contract beside it — so `query()->whereIn(...)`
        // cannot be typed at all; `statamic-funnels` does it and has carried an
        // unresolved static-analysis error there ever since. The thing that was
        // genuinely a query per row is `Schema::hasTable('events')`, and that
        // one is memoised.
    }

    /** Between requests, and between tests. */
    public static function forget(): void
    {
        self::$memo = [];
        self::$eventsAvailable = null;
    }

    /**
     * Look up what this product points at.
     *
     * Never throws. It runs inside a Control Panel listing that has to render
     * twenty-five rows, and one missing table on one of them must not take the
     * page down — the empty screen would be the same failure this class exists
     * to make visible.
     */
    public static function for(Product $product): self
    {
        $ref = trim((string) ($product->ref ?? ''));

        // A download points at nothing on purpose, and so does a product whose
        // pointer has not been filled in yet. Neither is a dangling reference.
        if ($ref === '' || $product->type === Product::TYPE_DOWNLOAD) {
            return new self(self::RESOLVED);
        }

        $key = $product->type.'|'.$ref;

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        try {
            $target = match ($product->type) {
                Product::TYPE_ZUGANG, Product::TYPE_KOHORTE => self::entry($ref),
                Product::TYPE_FEED => self::collection($ref),
                Product::TYPE_TERMIN => self::event($ref),
                Product::TYPE_SITZUNGEN => self::bookingEndpoint($ref),
                // A kind nobody taught this class about. Unknowable rather than
                // missing: the row is not wrong, this method is behind.
                default => new self(self::UNKNOWABLE),
            };
        } catch (Throwable) {
            // Same reasoning as the state itself: a lookup that blew up has not
            // established that the target is gone. Deliberately **not** memoised:
            // a transient failure must not freeze into an answer for the rest of
            // the request.
            return new self(self::UNKNOWABLE);
        }

        return self::$memo[$key] = $target;
    }

    /**
     * @param  list<string>  $uuids
     */
    private static function primeEvents(array $uuids): void
    {
        if ($uuids === [] || ! self::eventsAvailable()) {
            return;
        }

        try {
            $titles = DB::table('events')->whereIn('uuid', $uuids)->pluck('title', 'uuid');
        } catch (Throwable) {
            return;
        }

        foreach ($uuids as $uuid) {
            $title = $titles[$uuid] ?? null;

            self::$memo[Product::TYPE_TERMIN.'|'.$uuid] = $title === null
                ? new self(self::MISSING)
                : new self(self::RESOLVED, (string) $title);
        }
    }

    /**
     * A Statamic entry: a course, a members area, a cohort's programme page.
     *
     * Narrowed to core's own `Statamic\Entries\Entry` before anything is read
     * off it. The contract `EntryFacade::find()` promises —
     * `Statamic\Contracts\Entries\Entry` — is an **empty interface**: it
     * extends `Localizable` and declares nothing, while the whole API lives on
     * the class. So the narrowing is not ceremony; without it there is no typed
     * way to ask an entry for its title.
     *
     * Anything else that satisfies the contract is a thing this addon cannot
     * name — unknowable, not missing. It exists; we just cannot say what it is
     * called.
     */
    private static function entry(string $ref): self
    {
        $entry = EntryFacade::find($ref);

        if ($entry === null) {
            return new self(self::MISSING);
        }

        if (! $entry instanceof CoreEntry) {
            return new self(self::UNKNOWABLE);
        }

        return new self(self::RESOLVED, (string) ($entry->get('title') ?: $entry->slug() ?: $ref));
    }

    /** A Statamic collection: the issues of a paid newsletter, a podcast's episodes. */
    private static function collection(string $ref): self
    {
        $collection = CollectionFacade::find($ref);

        return $collection === null
            ? new self(self::MISSING)
            : new self(self::RESOLVED, (string) ($collection->title() ?: $ref));
    }

    /**
     * An event in `goldnead/statamic-events`, by uuid.
     *
     * By uuid and not by slug: the slug is unique per brand, and a product
     * handle is not scoped by brand at all. Queried straight through the query
     * builder rather than the Eloquent model, so this package does not have to
     * `use` a class that is often not installed — importing it would make
     * `class_exists` moot and turn a missing sibling into a fatal.
     */
    private static function event(string $ref): self
    {
        if (! self::eventsAvailable()) {
            return new self(self::UNKNOWABLE);
        }

        $title = DB::table('events')->where('uuid', $ref)->value('title');

        return $title === null
            ? new self(self::MISSING)
            : new self(self::RESOLVED, (string) $title);
    }

    /**
     * Whether `statamic-events` is here and migrated.
     *
     * Memoised for the request: the answer cannot change inside one, and asking
     * it per row is a `Schema::hasTable()` — a real query — for every date in
     * the catalogue.
     *
     * `Goldnead\Events`, not `Goldnead\StatamicEvents`: that package's PSR-4
     * prefix does not match its package name. Guessing it wrong would make
     * every event pointer permanently "unknowable" without a single error
     * anywhere, so it is checked against its composer.json — and, since this
     * package now carries it as a dev dependency, against its real schema in
     * the test suite rather than against a table this addon made up.
     */
    private static function eventsAvailable(): bool
    {
        // A class property rather than a `static` inside the method, so
        // `forget()` can reach it. A method-static would survive from one test
        // to the next and answer "events is installed" in a suite that has just
        // dropped its table — the kind of leak that shows up as an unrelated
        // assertion three files away.
        return self::$eventsAvailable ??= class_exists('\Goldnead\Events\Models\Event')
            && Schema::hasTable('events');
    }

    /**
     * A booking funnel in `goldnead/statamic-booking`.
     *
     * **Not a bookable thing, and that is worth saying out loud.** That addon
     * records bookings that already happened; it has no catalogue of event
     * types to point at. Its `endpoints` config is the nearest thing that
     * exists — one entry per funnel, with a handle that is "part of your setup
     * the moment the first booking arrives".
     *
     * So a sessions product points at the funnel a session is booked through,
     * not at a session. Turning a package of sessions into a credit balance
     * that a booking draws down is a separate piece of work with its own
     * decisions, and it is not this one.
     */
    private static function bookingEndpoint(string $ref): self
    {
        if (! class_exists('\Goldnead\StatamicBooking\Models\Booking')) {
            return new self(self::UNKNOWABLE);
        }

        $endpoints = (array) config('statamic-booking.endpoints', []);

        // An empty config is the shipped state of that addon ("Empty on
        // purpose. An addon cannot know which funnels a site runs."), so it
        // cannot be read as "this endpoint does not exist".
        if ($endpoints === []) {
            return new self(self::UNKNOWABLE);
        }

        if (! array_key_exists($ref, $endpoints)) {
            return new self(self::MISSING);
        }

        $label = $endpoints[$ref]['label'] ?? null;

        return new self(self::RESOLVED, is_string($label) && $label !== '' ? $label : $ref);
    }

    public function isMissing(): bool
    {
        return $this->state === self::MISSING;
    }
}
