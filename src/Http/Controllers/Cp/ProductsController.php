<?php

namespace Goldnead\StatamicProducts\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicProducts\Http\Resources\Cp\ProductsCollection;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\CpNumber;
use Goldnead\StatamicProducts\Support\ProductContext;
use Goldnead\StatamicProducts\Support\RefTarget;
use Goldnead\StatamicProducts\Support\SoldHandles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * Products in the Control Panel.
 *
 * The screen this addon exists for. Until now the only place a price could be
 * changed was a config file, which meant a deploy, which meant a developer.
 */
class ProductsController extends CpController
{
    use QueriesFilters;

    public const SCOPE = 'statamic-products-products';

    public function index(FilteredRequest $request)
    {
        $this->authorizeAccess();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        $dangling = $this->danglingCount();

        return Inertia::render('statamic-products::Products/Index', [
            'listingUrl' => cp_route('utilities.products'),
            'storeUrl' => cp_route('utilities.products.store'),
            'sortColumn' => 'name',
            'sortDirection' => 'asc',
            // Scoped like the listing itself. Unscoped, a brand with no
            // products of its own gets the listing's flat "no results" instead
            // of the empty state that explains what a product is and offers to
            // make one — because somebody else's catalogue exists.
            'hasAny' => Product::query()->forBrand()->exists(),
            'currency' => (string) config('statamic-payments.currency', 'EUR'),
            // The handles the config file already claims. Handed to the form so
            // the collision is visible *while typing a handle*, rather than
            // after a purchase went through at the other price.
            'configuredHandles' => array_keys(app(Catalogue::class)->configured()),
            // What a product can be, with the label and the one line that says
            // what its pointer is expected to hold. Built here so the form has
            // no vocabulary of its own to drift from the model's.
            'types' => collect(Product::types())->map(fn (string $type) => [
                'value' => $type,
                'label' => __('statamic-products::messages.type_'.$type),
                'description' => __('statamic-products::messages.type_'.$type.'_description'),
                'ref_label' => __('statamic-products::messages.ref_'.$type),
                'needs_ref' => in_array($type, Product::typesNeedingRef(), true),
            ])->all(),
            // **Eine Zahl, die sich nicht abschalten laesst.**
            //
            // Das Abzeichen an der Zeile sagt *welches* Produkt ins Leere
            // zeigt, aber jede Spalte im Control Panel ist ueber den
            // Spaltenwaehler abwaehlbar — die Namensspalte eingeschlossen. Eine
            // Liste, die sich sauber liest, weil jemand eine Spalte ausgeblendet
            // hat, ist genau der stille Fehler, den dieses Feld sichtbar machen
            // soll. Also steht die Zahl darueber, wo keine Einstellung sie
            // wegnimmt.
            'danglingCount' => $dangling,
            // Fertig formuliert, mit Einzahl und Mehrzahl. Der Bildschirm setzt
            // keinen Satz zusammen — das ist dieselbe Regel, nach der jedes
            // andere Label hier schon fertig ankommt, und sie faellt sonst
            // genau bei dem Text um, der eine Zahl enthaelt.
            'danglingBanner' => $dangling > 0
                ? trans_choice('statamic-products::messages.dangling_banner', $dangling, ['count' => $dangling])
                : null,
            't' => $this->strings(),
        ]);
    }

    /**
     * One product, and what the rest of the family knows about it.
     *
     * The row itself is edited in the stack on the listing; this screen is the
     * way *back* from a product: the offers that sell it and the people who
     * bought it. Either section is present only when the sibling that owns
     * the data is installed and migrated — `null` here is "cannot know", an
     * empty list is "nobody", and the screen shows them differently.
     */
    public function show(int $product)
    {
        $this->authorizeAccess();

        // Scoped, not implicitly bound: `{product}` is an id anyone can type,
        // and in multi-brand mode another brand's product is not this brand's
        // to see — least of all its buyers' e-mail addresses. Same rule as
        // the listing, a 404 rather than a 403 so that the id gives nothing
        // away.
        $product = Product::query()->forBrand()->findOrFail($product);

        return Inertia::render('statamic-products::Products/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'handle' => $product->handle,
                'type_label' => __('statamic-products::messages.type_'.$product->type),
                'amount' => CpNumber::decimal($product->amount_cent / 100, 2),
                'currency' => $product->currency(),

                // Der Zahlungsrhythmus, so wie er in der Spalte steht. `null`
                // heisst einmalig — das Formular zeigt dann ein leeres Feld,
                // und genau das ist die richtige Anzeige fuer „kein Plan".
                'interval' => $product->interval,
                'times' => $product->times,
                'trial_days' => $product->trial_days,
                'trial_amount_cent' => $product->trial_amount_cent,

                'digital' => (bool) $product->digital,
                'grants' => $product->grantSlugs(),
                'active' => (bool) $product->active,
                'sold' => $product->hasBeenSold(),
            ],
            'offers' => ProductContext::offers($product),
            'buyers' => ProductContext::buyers($product),
            'buyersLimit' => ProductContext::BUYERS_LIMIT,
            'indexUrl' => cp_route('utilities.products'),
            't' => $this->strings(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $product = Product::create($this->validated($request));

        return back()->with('message', __('statamic-products::messages.saved', ['name' => $product->name]));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeAccess();

        $product->update($this->validated($request, $product));

        return back()->with('message', __('statamic-products::messages.saved', ['name' => $product->name]));
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeAccess();

        // **Sold products are never deleted, only retired.** The handle is on
        // payment rows and invoice lines that still have to render; deleting
        // the row behind it is how an old invoice starts showing a blank line.
        // Refused rather than silently turned into a deactivation, because a
        // delete button that quietly does something else is worse than one that
        // says no.
        if ($product->hasBeenSold()) {
            throw ValidationException::withMessages([
                'handle' => __('statamic-products::messages.delete_refused_sold'),
            ]);
        }

        $product->delete();

        return back()->with('message', __('statamic-products::messages.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Product $product = null): array
    {
        // Null unless money has already moved under this name, in which case
        // it is the one value the field may still hold. See
        // `Product::hasBeenSold()` for why a handle stops being editable.
        $frozenHandle = $product !== null && $product->hasBeenSold() ? $product->handle : null;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            // Required with no fallback, like `digital` below: a kind decides
            // what the `ref` beside it even means, so guessing one would give
            // the pointer a meaning nobody chose.
            'type' => ['required', Rule::in(Product::types())],
            // **Required for the kinds that name something else, refused for a
            // download.** A download's thing *is* the product, so a pointer on
            // one is a leftover from a changed mind — kept, it would resolve
            // against the wrong sibling and show a name from another product's
            // world. It is nulled below rather than rejected, because changing
            // a kind is a normal edit and should not need the field cleared by
            // hand first.
            'ref' => [
                Rule::requiredIf(fn () => in_array($request->input('type'), Product::typesNeedingRef(), true)),
                'nullable', 'string', 'max:191',
            ],
            'handle' => [
                'required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('products', 'handle')->ignore($product?->getKey()),
                ...($frozenHandle !== null ? [Rule::in([$frozenHandle])] : []),
            ],
            // `min:0`, not `min:1`. Zero is a real price — the lead magnet, the
            // sample chapter — and the catalogue has allowed it since refusing
            // free things pushed every free thing outside the addon.
            //
            // `integer` and not `numeric`: nobody may post "49,00" and have it
            // read as 49 cents.
            'amount_cent' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],

            // Der Zahlungsrhythmus. Leer heisst einmalig — der Normalfall,
            // und deshalb `nullable` statt eines Standardwerts: ein Default
            // machte aus jedem Produkt ein Abo.
            //
            // Freitext im Wortlaut des Anbieters (`1 month`, `12 weeks`),
            // keine Aufzaehlung. `Subscriptions::afterOneInterval()` reicht
            // ihn an Carbon weiter und faellt bei Unlesbarem auf einen Monat
            // zurueck; eine engere Regel hier beschnitte, was das
            // Zahlungs-Addon kann.
            'interval' => ['nullable', 'string', 'max:32'],

            // Ohne Anzahl ist es ein Abo, mit Anzahl eine Ratenzahlung.
            // `min:1`, weil null Abbuchungen ein Tippfehler sind und keine
            // Anweisung — `planFor()` faengt das ohnehin ab, aber ein
            // Formular soll es sagen, statt es stillschweigend zu schlucken.
            'times' => ['nullable', 'integer', 'min:1', 'max:60'],

            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            // `0` ist erlaubt und heisst kostenlos; leer heisst „der
            // gewoehnliche Betrag".
            'trial_amount_cent' => ['nullable', 'integer', 'min:0'],
            // **Required with no default, and that is the point of the field.**
            // It decides the place of supply and with it the mandatory tax
            // notice (§ 3a UStG). Any default is wrong for half a catalogue,
            // and a wrong default here surfaces as a tax line nobody checked.
            // `false` counts as present for `required`, so both answers pass
            // and only silence fails.
            'digital' => ['required', 'boolean'],
            'grants' => ['nullable', 'array'],
            // `nullable`, because core's `ConvertEmptyStringsToNull` middleware
            // has already turned the blank rows of a half-filled repeater into
            // nulls by the time this runs. Without it an empty row is a 422 on
            // a form the person filled in correctly. They are dropped below.
            'grants.*' => ['nullable', 'string', 'max:191'],
            'active' => ['boolean'],
        ], [
            'handle.in' => __('statamic-products::messages.handle_frozen'),
        ]);

        // A download points at nothing. See the rule above.
        if (($data['type'] ?? null) === Product::TYPE_DOWNLOAD) {
            $data['ref'] = null;
        }

        $data['ref'] = ($data['ref'] ?? null) === '' ? null : ($data['ref'] ?? null);

        // Der Rhythmus ist der Schalter fuer die drei Felder daneben.
        //
        // Leer heisst leer: ein Formular schickt ein ungefuelltes Feld als
        // leeren String, und `planFor()` liest den zwar auch als „kein Plan",
        // aber dann staende in der Spalte etwas, das keiner gemeint hat.
        //
        // Und ohne Rhythmus werden Anzahl, Testtage und Testbetrag mit
        // geleert. Sonst bleibt an einem einmalig verkauften Produkt ein
        // `times = 3` haengen, das niemand sieht und das beim naechsten
        // Setzen eines Intervalls ploetzlich wirkt.
        $intervall = trim((string) ($data['interval'] ?? ''));
        $data['interval'] = $intervall === '' ? null : $intervall;

        if ($data['interval'] === null) {
            $data['times'] = null;
            $data['trial_days'] = null;
            $data['trial_amount_cent'] = null;
        }

        // **Only what was actually sent.** `$request->boolean()` answers false
        // for a key that is absent, so a PATCH that simply left `active` out
        // used to store `false`, drop the product out of the catalogue and
        // answer 200. The addon's own form sends every field, so the form never
        // saw it; anything else patching a price did.
        //
        // `digital` is not in this list because it is `required`: it is either
        // present or the request never got here.
        $data['digital'] = $request->boolean('digital');
        $data['currency'] = ($data['currency'] ?? null) ? strtoupper($data['currency']) : null;

        if ($request->has('active')) {
            $data['active'] = $request->boolean('active');
        } else {
            unset($data['active']);
        }

        // Same rule as `active`: a key nobody sent is not a key somebody
        // emptied. Sending `grants: []` *is* a statement and still clears them.
        if ($request->has('grants')) {
            // Duplicates would grant the same access twice and log it twice;
            // blanks come from a half-filled repeater and reach the entitlements
            // bridge as a slug that opens nothing.
            $data['grants'] = array_values(array_unique(array_filter(
                (array) ($data['grants'] ?? []),
                static fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '',
            )));

            // Empty means "opens nothing", and that belongs in the column as
            // `null` rather than `[]`. An empty array is a statement; `null` is
            // the absence of one, and the column is nullable because that is the
            // normal case.
            if ($data['grants'] === []) {
                $data['grants'] = null;
            }
        } else {
            unset($data['grants']);
        }

        return $data;
    }

    /**
     * Wie viele Produkte dieser Marke auf etwas zeigen, das es nicht gibt.
     *
     * Ueber den ganzen Katalog, nicht ueber die aktuelle Seite: „auf Seite drei
     * sind zwei kaputt" ist keine Auskunft, die jemand beim Oeffnen bekommt.
     *
     * Der Preis dafuer ist ein Durchlauf beim Aufbau des Bildschirms — bei
     * Terminen eine Abfrage fuer alle zusammen ({@see RefTarget::prime()}), bei
     * Eintraegen und Collections Zugriffe auf den Stache, der ohnehin im
     * Speicher liegt. Ein Produktkatalog hat Dutzende Zeilen, keine Millionen;
     * waechst er, gehoert die Zahl in einen Cache und nicht weg.
     */
    protected function danglingCount(): int
    {
        $products = Product::query()->forBrand()->get();

        RefTarget::prime($products);

        return $products->filter(fn (Product $product) => $product->refTarget()->isMissing())->count();
    }

    protected function json(FilteredRequest $request)
    {
        $query = Product::query()->forBrand();

        if ($search = trim((string) $request->get('search', ''))) {
            $this->applySearch($query, $search);
        }

        $badges = $this->queryFilters($query, $request->filters, ['scope' => self::SCOPE]);

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        $page = $query->paginate(Statamic::cpPerPage($request->get('perPage')));

        // Two queries for the page instead of two per row. Every row asks
        // whether its handle has taken money — the answer decides whether the
        // handle field is locked — and twenty-five rows asking separately is
        // fifty queries for a question that is one `whereIn`. Primed here and
        // not inside the resource, so it is certainly done before the first row
        // is built rather than probably.
        SoldHandles::prime($page->getCollection()->pluck('handle')->all());

        // Dieselbe Rechnung fuer die Zeiger: sonst kostet jede Zeile eine
        // `Schema::hasTable()` plus eine eigene Abfrage.
        RefTarget::prime($page->getCollection());

        return (new ProductsCollection($page))
            ->columnPreferenceKey('statamic-products.products.columns')
            ->additional(['meta' => ['activeFilterBadges' => $badges]]);
    }

    protected function authorizeAccess(): void
    {
        // Through the Gate, where `Utility::register` puts the permission and
        // where the route's `can:` middleware looks.
        abort_unless(Gate::allows('access products utility'), 403);
    }

    /**
     * @param  Builder<Product>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` and `_` are LIKE wildcards; the ESCAPE clause is spelled out
        // because SQLite, unlike MySQL and Postgres, has no default one.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            foreach (['name', 'handle', 'ref'] as $column) {
                $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function order(FilteredRequest $request): array
    {
        // A positive list: `sort` comes from the query string and would
        // otherwise order by any column in the table.
        $sortable = [
            'name' => 'name',
            'handle' => 'handle',
            'type' => 'type',
            'amount' => 'amount_cent',
            'digital' => 'digital',
            'active' => 'active',
        ];

        $requested = (string) $request->get('sort', 'name');
        $direction = strtolower((string) $request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$sortable[$requested] ?? 'name', $direction];
    }

    /**
     * @return array<string, string>
     */
    protected function strings(): array
    {
        return [
            'title' => __('statamic-products::messages.utility_title'),
            'utilities' => __('Utilities'),
            'empty_heading' => __('statamic-products::messages.empty_heading'),
            'empty_title' => __('statamic-products::messages.empty_title'),
            'empty_description' => __('statamic-products::messages.empty_description'),
            'new' => __('statamic-products::messages.new_product'),
            'edit' => __('statamic-products::messages.edit_product'),
            'delete_title' => __('statamic-products::messages.delete_title'),
            'delete_body' => __('statamic-products::messages.delete_body', ['name' => ':name']),
            'delete_refused_sold' => __('statamic-products::messages.delete_refused_sold'),
            'field_name' => __('statamic-products::messages.field_name'),
            'field_name_help' => __('statamic-products::messages.field_name_help'),
            'field_handle' => __('statamic-products::messages.field_handle'),
            'field_handle_help' => __('statamic-products::messages.field_handle_help'),
            'handle_frozen' => __('statamic-products::messages.handle_frozen'),
            'field_amount' => __('statamic-products::messages.field_amount'),
            'field_amount_help' => __('statamic-products::messages.field_amount_help'),
            'field_plan' => __('statamic-products::messages.field_plan'),
            'field_plan_help' => __('statamic-products::messages.field_plan_help'),
            'field_interval' => __('statamic-products::messages.field_interval'),
            'field_interval_placeholder' => __('statamic-products::messages.field_interval_placeholder'),
            'field_times' => __('statamic-products::messages.field_times'),
            'field_times_placeholder' => __('statamic-products::messages.field_times_placeholder'),
            'field_trial_days' => __('statamic-products::messages.field_trial_days'),
            'field_trial_amount' => __('statamic-products::messages.field_trial_amount'),
            'field_currency' => __('statamic-products::messages.field_currency'),
            'field_currency_help' => __('statamic-products::messages.field_currency_help'),
            'field_digital' => __('statamic-products::messages.field_digital'),
            'field_digital_help' => __('statamic-products::messages.field_digital_help'),
            'digital_yes' => __('statamic-products::messages.digital_yes'),
            'digital_no' => __('statamic-products::messages.digital_no'),
            'field_grants' => __('statamic-products::messages.field_grants'),
            'field_grants_help' => __('statamic-products::messages.field_grants_help'),
            'field_grants_placeholder' => __('statamic-products::messages.field_grants_placeholder'),
            'field_type' => __('statamic-products::messages.field_type'),
            'field_type_help' => __('statamic-products::messages.field_type_help'),
            'field_ref_help' => __('statamic-products::messages.field_ref_help'),
            'ref_missing_badge' => __('statamic-products::messages.ref_missing_badge'),
            'ref_missing_warning' => __('statamic-products::messages.ref_missing_warning'),
            'field_active' => __('statamic-products::messages.field_active'),
            'shadowed_badge' => __('statamic-products::messages.shadowed_badge'),
            'shadowed_warning' => __('statamic-products::messages.shadowed_warning'),
            'sold_note' => __('statamic-products::messages.sold_note'),
            'yes' => __('statamic-products::messages.yes'),
            'no' => __('statamic-products::messages.no'),
            'save' => __('Save'),
            'cancel' => __('Cancel'),
            'edit_action' => __('Edit'),
            'delete_action' => __('Delete'),
            'show_action' => __('statamic-products::messages.show_action'),
            'back_to_list' => __('statamic-products::messages.back_to_list'),
            'facts_heading' => __('statamic-products::messages.facts_heading'),
            'section_offers' => __('statamic-products::messages.section_offers'),
            'section_offers_hint' => __('statamic-products::messages.section_offers_hint'),
            'offers_empty' => __('statamic-products::messages.offers_empty'),
            'section_buyers' => __('statamic-products::messages.section_buyers'),
            'section_buyers_hint' => __('statamic-products::messages.section_buyers_hint'),
            'buyers_empty' => __('statamic-products::messages.buyers_empty'),
            'col_offer' => __('statamic-products::messages.col_offer'),
            'col_slot' => __('statamic-products::messages.col_slot'),
            'col_price' => __('statamic-products::messages.col_price'),
            'col_active' => __('statamic-products::messages.column_active'),
            'col_buyer' => __('statamic-products::messages.col_buyer'),
            'col_paid_at' => __('statamic-products::messages.col_paid_at'),
            'col_amount' => __('statamic-products::messages.col_amount'),
            'list_price_badge' => __('statamic-products::messages.list_price_badge'),
            'bundle_badge' => __('statamic-products::messages.bundle_badge'),
            'refunded_badge' => __('statamic-products::messages.refunded_badge'),
            'kind_bump' => __('statamic-products::messages.kind_bump'),
            'kind_upsell' => __('statamic-products::messages.kind_upsell'),
            'open_offer' => __('statamic-products::messages.open_offer'),
            'open_payment' => __('statamic-products::messages.open_payment'),
            'no_email' => __('statamic-products::messages.no_email'),
            'grants_none' => __('statamic-products::messages.grants_none'),
        ];
    }
}
