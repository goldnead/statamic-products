<?php

namespace Goldnead\StatamicProducts\Http\Controllers\Cp;

use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicProducts\Http\Resources\Cp\AccessesCollection;
use Goldnead\StatamicProducts\Http\Resources\Cp\ListedAccess;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Goldnead\StatamicProducts\Support\SessionTypes;
use Goldnead\StatamicProducts\Support\Setup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Statamic\Entries\Entry as CoreEntry;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Statamic;
use Throwable;

/**
 * Zugaenge im Control Panel: Liste, Anlegen, Detailseite.
 *
 * Gebaut wie die Produkte daneben, mit Absicht: dieselben Gesten, dieselbe
 * Seite. Die Detailseite ist das Formular, angelegt wird auf einer eigenen
 * Seite unter `/new`.
 */
class AccessesController extends CpController
{
    public function index(FilteredRequest $request)
    {
        $this->authorizeAccess();

        if ($setup = Setup::guard(__('statamic-products::messages.accesses_title'), 'product_accesses')) {
            return $setup;
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        $missing = $this->missingCount();

        return Inertia::render('statamic-products::Accesses/Index', [
            'listingUrl' => cp_route('utilities.product-accesses'),
            'createUrl' => cp_route('utilities.product-accesses.create'),
            'sortColumn' => 'name',
            'sortDirection' => 'asc',
            'hasAny' => Access::query()->forBrand()->exists(),
            'missingCount' => $missing,
            'missingBanner' => $missing > 0
                ? trans_choice('statamic-products::messages.access_missing_banner', $missing, ['count' => $missing])
                : null,
            't' => $this->strings(),
        ]);
    }

    public function create()
    {
        $this->authorizeAccess();

        if ($setup = Setup::guard(__('statamic-products::messages.accesses_title'), 'product_accesses')) {
            return $setup;
        }

        return Inertia::render('statamic-products::Accesses/Create', [
            'storeUrl' => cp_route('utilities.product-accesses.store'),
            'indexUrl' => cp_route('utilities.product-accesses'),
            'form' => $this->formContext(),
            't' => $this->strings(),
        ]);
    }

    public function show(Request $request, int $access)
    {
        $this->authorizeAccess();

        // Gescopet wie die Liste. Eine fremde Marke bekommt 404, nicht 403.
        $access = Access::query()->forBrand()->findOrFail($access);

        $row = (new ListedAccess($access))->toArray($request);

        return Inertia::render('statamic-products::Accesses/Show', [
            'access' => [
                'id' => $access->id,
                'name' => $access->name,
                'values' => $row['edit_values'],
                'targets' => $row['targets'],
                'granted' => $access->hasBeenGranted(),
                'version' => $access->version(),
                'sessionTargets' => $row['session_targets'],
            ],
            'form' => $this->formContext($access),
            'products' => $this->productsGranting($access),
            'updateUrl' => cp_route('utilities.product-accesses.update', $access->id),
            'deleteUrl' => cp_route('utilities.product-accesses.destroy', $access->id),
            'indexUrl' => cp_route('utilities.product-accesses'),
            't' => $this->strings(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $access = Access::create($this->validated($request));

        return redirect(cp_route('utilities.product-accesses.show', $access->id))
            ->with('message', __('statamic-products::messages.saved', ['name' => $access->name]));
    }

    public function update(Request $request, Access $access)
    {
        $this->authorizeAccess();

        // In einer Transaktion, gegen den frisch gelesenen Stand. Die Sperre
        // hilft auf MySQL und Postgres; auf SQLite ist `lockForUpdate` nichts.
        // Der eigentliche Schutz ist der Fingerabdruck: das Formular schickt
        // mit, welchen Stand es geladen hat, und ein inzwischen geaenderter
        // Datensatz wird nicht still ueberschrieben.
        $access = DB::transaction(function () use ($request, $access) {
            $fresh = Access::query()->lockForUpdate()->findOrFail($access->getKey());

            $version = $request->input('version');

            if (is_string($version) && $version !== '' && ! hash_equals($fresh->version(), $version)) {
                throw ValidationException::withMessages([
                    'version' => __('statamic-products::messages.access_stale'),
                ])->status(409);
            }

            $fresh->update($this->validated($request, $fresh));

            return $fresh;
        });

        return back()->with('message', __('statamic-products::messages.saved', ['name' => $access->name]));
    }

    public function destroy(Access $access)
    {
        $this->authorizeAccess();

        // Wie beim verkauften Produkt: ein Slug, den eine Vergabe traegt, hat
        // Datensaetze ausserhalb dieses Addons, die weiter lesbar sein muessen.
        if ($access->hasBeenGranted()) {
            throw ValidationException::withMessages([
                'handle' => __('statamic-products::messages.access_delete_refused'),
            ]);
        }

        $access->delete();

        return redirect(cp_route('utilities.product-accesses'))
            ->with('message', __('statamic-products::messages.access_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Access $access = null): array
    {
        $granted = $access !== null && $access->hasBeenGranted();
        $kinds = ContentKinds::all();

        // Eine Art, die schon gespeichert ist, bleibt gueltig, auch wenn die
        // Website sie gerade nicht anmeldet. Sonst blockierte ein vergessener
        // `register()`-Aufruf jede andere Aenderung an diesem Zugang.
        foreach ($access?->contentItems() ?? [] as $item) {
            $kinds[] = $item['kind'];
        }

        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:191'],
            'handle' => [
                'required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('product_accesses', 'handle')->ignore($access?->getKey()),
                ...($granted ? [Rule::in([$access->handle])] : []),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'cover' => ['nullable', 'string', 'max:512'],
            'active' => ['boolean'],
            'opens_members_area' => ['boolean'],

            'contents' => ['nullable', 'array', 'max:200'],
            'contents.*.kind' => ['required', 'string', Rule::in(array_values(array_unique($kinds)))],
            'contents.*.ref' => ['required', 'string', 'max:191'],
            'contents.*.label' => ['nullable', 'string', 'max:191'],

            'credits' => ['nullable', 'array', 'max:50'],
            'credits.*.line' => ['nullable', 'integer', 'min:0'],
            // Hat die Website ihre Sessiontypen angemeldet, nur diese (und was
            // an diesem Zugang schon steht, damit ein abgemeldeter Typ nicht
            // jede andere Aenderung blockiert). Sonst Freitext.
            'credits.*.session_type' => [
                'required', 'string', 'max:191',
                ...(SessionTypes::known() ? [Rule::in(array_values(array_unique([
                    ...SessionTypes::ids(),
                    ...array_map(fn (array $line) => (string) ($line['session_type'] ?? ''), $access?->creditLines() ?? []),
                ])))] : []),
            ],
            'credits.*.kind' => ['required', Rule::in(Access::creditKinds())],
            'credits.*.count' => ['nullable', 'required_if:credits.*.kind,'.Access::CREDIT_ONE_TIME, 'integer', 'min:1', 'max:999'],
            'credits.*.per_month' => ['nullable', 'required_if:credits.*.kind,'.Access::CREDIT_SUBSCRIPTION, 'integer', 'min:1', 'max:99'],
            'credits.*.valid_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'credits.*.ended' => ['nullable', 'boolean'],
        ], [
            'handle.in' => __('statamic-products::messages.access_handle_frozen'),
            'credits.*.session_type.in' => __('statamic-products::messages.credit_session_type_unknown'),
        ], [
            'credits.*.count' => __('statamic-products::messages.credit_count'),
            'credits.*.per_month' => __('statamic-products::messages.credit_per_month'),
            'credits.*.session_type' => __('statamic-products::messages.credit_session_type'),
        ]);

        $validator->after(function (Validator $validator) use ($request, $access, $granted) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkCycle($validator, $request, $access);
            $this->checkLines($validator, $request, $access, $granted);
        });

        $data = $validator->validate();

        $out = [
            'name' => $data['name'],
            'handle' => $data['handle'],
            'description' => ($data['description'] ?? null) ?: null,
            'cover' => ($data['cover'] ?? null) ? trim((string) $data['cover']) : null,
        ];

        // Dieselbe Regel wie beim Produkt: ein Schluessel, den niemand
        // geschickt hat, ist keiner, den jemand geleert hat.
        foreach (['active', 'opens_members_area'] as $flag) {
            if ($request->has($flag)) {
                $out[$flag] = $request->boolean($flag);
            }
        }

        if ($request->has('contents')) {
            $out['contents'] = $this->contents((array) ($data['contents'] ?? [])) ?: null;
        }

        if ($request->has('credits')) {
            $out['credits'] = $this->credits((array) ($data['credits'] ?? []), $access) ?: null;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return list<array{kind: string, ref: string, label: string|null}>
     */
    protected function contents(array $contents): array
    {
        return array_values(array_map(fn (array $item) => [
            'kind' => (string) $item['kind'],
            'ref' => trim((string) $item['ref']),
            'label' => isset($item['label']) && trim((string) $item['label']) !== '' ? trim((string) $item['label']) : null,
        ], $contents));
    }

    /**
     * Die Zeilen in ihrer gespeicherten Form.
     *
     * Eine Abo-Zeile hat keine Gesamtzahl, eine einmalige keine Monatszahl;
     * die jeweils andere wird geleert, damit beim Wechsel der Art nichts
     * Unsichtbares stehen bleibt. `ended` aus dem Formular wird zu `ended_at`;
     * ein schon gesetztes Datum bleibt beim erneuten Speichern stehen.
     *
     * @param  array<int, array<string, mixed>>  $credits
     * @return list<array<string, mixed>>
     */
    protected function credits(array $credits, ?Access $access): array
    {
        $stored = [];

        foreach ($access?->creditLines() ?? [] as $line) {
            $stored[$line['line']] = $line;
        }

        return array_values(array_map(function (array $line) use ($stored) {
            $kind = (string) $line['kind'];
            $number = isset($line['line']) ? (int) $line['line'] : null;
            $ended = filter_var($line['ended'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $endedAt = $number !== null ? ($stored[$number]['ended_at'] ?? null) : null;

            return [
                'line' => $number,
                'session_type' => trim((string) $line['session_type']),
                'kind' => $kind,
                'count' => $kind === Access::CREDIT_ONE_TIME ? (int) $line['count'] : null,
                'per_month' => $kind === Access::CREDIT_SUBSCRIPTION ? (int) $line['per_month'] : null,
                'valid_months' => isset($line['valid_months']) ? (int) $line['valid_months'] : null,
                'ended_at' => $ended ? ($endedAt ?? Carbon::now()->toIso8601String()) : null,
            ];
        }, $credits));
    }

    protected function checkCycle(Validator $validator, Request $request, ?Access $access): void
    {
        $handle = (string) $request->input('handle');
        $contents = $this->contents((array) $request->input('contents', $access?->contentItems() ?? []));

        $cycle = Access::cycleThrough($handle, $contents);

        // Beim Umbenennen zeigt ein anderer Zugang vielleicht noch auf den alten
        // Slug. Das ist ein toter Verweis, kein Kreis; geprueft wird der neue.
        if ($cycle !== null) {
            $validator->errors()->add('contents', __('statamic-products::messages.access_cycle', [
                'path' => implode(' → ', $cycle),
            ]));
        }
    }

    protected function checkLines(Validator $validator, Request $request, ?Access $access, bool $granted): void
    {
        if (! $request->has('credits')) {
            return;
        }

        $stored = [];

        foreach ($access?->creditLines() ?? [] as $line) {
            $stored[$line['line']] = $line;
        }

        $known = array_keys($stored);
        $sent = [];

        foreach ((array) $request->input('credits', []) as $i => $line) {
            $number = is_array($line) ? ($line['line'] ?? null) : null;

            if ($number === null || $number === '') {
                continue;
            }

            $number = (int) $number;

            // Nummern vergibt nur der Server. Eine Zahl, die dieser Zugang nie
            // vergeben hat, ist erfunden; zweimal dieselbe ein kopierter Eintrag.
            if (! in_array($number, $known, true) || in_array($number, $sent, true)) {
                $validator->errors()->add("credits.{$i}.line", __('statamic-products::messages.credit_line_unknown'));

                continue;
            }

            $sent[] = $number;

            // Nach einer Vergabe bleibt eine beendete Zeile beendet. Wieder
            // geoeffnet, schriebe sie neuen Kaeufern Guthaben unter einem
            // Schluessel gut, den die Website womoeglich schon abgeschlossen hat.
            if ($granted && ($stored[$number]['ended_at'] ?? null) !== null
                && ! filter_var($line['ended'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $validator->errors()->add("credits.{$i}.ended", __('statamic-products::messages.credit_line_reopen'));
            }
        }

        if (! $granted) {
            return;
        }

        $removed = array_values(array_diff($known, $sent));

        if ($removed !== []) {
            // Gezaehlt ab 1, wie der Bildschirm sie zeigt. Intern bleibt `line`.
            $validator->errors()->add('credits', trans_choice('statamic-products::messages.credit_line_not_deletable', count($removed), [
                'lines' => implode(', ', array_map(fn (int $n) => $n + 1, $removed)),
            ]));
        }
    }

    /**
     * Was das Formular ausser dem Datensatz braucht.
     *
     * @return array<string, mixed>
     */
    protected function formContext(?Access $current = null): array
    {
        return [
            'kinds' => ContentKinds::options(),
            'sessionTypes' => SessionTypes::options(),
            'assetPickers' => $this->assetPickers(array_values(array_filter([
                (string) ($current->cover ?? ''),
                ...array_map(
                    fn (array $item) => $item['ref'],
                    array_filter($current?->contentItems() ?? [], fn (array $item) => $item['kind'] === ContentKinds::FILE),
                ),
            ], fn (string $id) => str_contains($id, '::')))),
            'creditKinds' => array_map(fn (string $kind) => [
                'value' => $kind,
                'label' => __('statamic-products::messages.credit_kind_'.$kind),
            ], Access::creditKinds()),
            // Auswahllisten fuer die Arten, deren Ziele dieses Addon kennt. Fehlt
            // eine, tippt man den Verweis von Hand; er wird trotzdem geprueft.
            'choices' => [
                ContentKinds::ACCESS => Access::query()->forBrand()
                    ->when($current, fn (Builder $q) => $q->whereKeyNot($current->getKey()))
                    ->orderBy('name')
                    ->get(['handle', 'name'])
                    ->map(fn (Access $a) => ['value' => $a->handle, 'label' => $a->name.' ('.$a->handle.')'])
                    ->values()
                    ->all(),
                ContentKinds::COURSE => $this->courseChoices(),
                ContentKinds::EVENT => $this->eventChoices(),
            ],
        ];
    }

    /**
     * Ein Assets-Feld von core je Container, fuer Dateien und das Titelbild.
     *
     * Core's Feld braucht einen Container, sobald es mehr als einen gibt, und
     * seine `meta` (Container, Rechte, Ordner) entsteht serverseitig. Also
     * baut der Server je Container ein Blueprint mit genau einem Feld; das
     * Formular stellt es in einen `PublishContainer` und nimmt die erste ID.
     * Gespeichert wird weiter `container::pfad`, genau die Asset-ID.
     *
     * `$ids` sind die Assets, die dieser Zugang schon traegt. Ihre Daten
     * (Name, Vorschau, Groesse) kommen in `meta.data` mit, sonst zeigte das
     * Feld nach dem Neuladen „0/1", obwohl die Datei gespeichert ist: core's
     * Feld liest sie von dort und laedt sie nicht nach. Jede Zeile filtert
     * sich im Browser die eine heraus, die ihr gehoert.
     *
     * @param  list<string>  $ids
     * @return list<array{handle: string, title: string, blueprint: array<string, mixed>, meta: array<string, mixed>}>
     */
    protected function assetPickers(array $ids = []): array
    {
        try {
            return AssetContainer::all()
                ->sortBy(fn ($container) => $container->title())
                ->map(function ($container) use ($ids) {
                    $own = array_values(array_filter($ids, fn (string $id) => str_starts_with($id, $container->handle().'::')));

                    $blueprint = Blueprint::makeFromFields([
                        'asset' => [
                            'type' => 'assets',
                            'container' => $container->handle(),
                            'max_files' => 1,
                            'mode' => 'list',
                            'display' => $container->title(),
                            'hide_display' => true,
                        ],
                    ]);

                    $fields = $blueprint->fields()->addValues(['asset' => $own])->preProcess();

                    return [
                        'handle' => (string) $container->handle(),
                        'title' => (string) $container->title(),
                        'blueprint' => $blueprint->toPublishArray(),
                        'meta' => $fields->meta()->all(),
                    ];
                })
                ->values()
                ->all();
        } catch (Throwable) {
            // Ohne Picker bleibt das Textfeld; geprueft wird der Verweis trotzdem.
            return [];
        }
    }

    /**
     * Die Kurse aus `statamic-courses`, oder null, wenn es das nicht gibt.
     *
     * @return list<array{value: string, label: string}>|null
     */
    protected function courseChoices(): ?array
    {
        if (! class_exists('\Goldnead\Courses\ServiceProvider')) {
            return null;
        }

        try {
            $collection = (string) config('courses.collections.courses', 'courses');

            return EntryFacade::whereCollection($collection)
                ->filter(fn ($entry) => $entry instanceof CoreEntry)
                ->map(fn (CoreEntry $entry) => ['value' => (string) $entry->id(), 'label' => (string) ($entry->get('title') ?: $entry->slug())])
                ->sortBy('label')
                ->values()
                ->all();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Die Termine aus `statamic-events`, oder null, wenn es das nicht gibt.
     *
     * Ueber den Query Builder, damit kein Modell dieses Pakets geladen wird.
     *
     * @return list<array{value: string, label: string}>|null
     */
    protected function eventChoices(): ?array
    {
        try {
            if (! class_exists('\Goldnead\Events\Models\Event') || ! Schema::hasTable('events')) {
                return null;
            }

            return DB::table('events')
                ->when(Brands::multiBrand(), fn ($q) => $q->where('brand_id', (int) Brands::readerId()))
                ->orderBy('title')
                ->limit(500)
                ->get(['uuid', 'title'])
                ->map(fn ($event) => ['value' => (string) $event->uuid, 'label' => (string) $event->title])
                ->values()
                ->all();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Die Produkte, die diesen Zugang freischalten. Der Weg zurueck zum Verkauf.
     *
     * @return list<array<string, mixed>>
     */
    protected function productsGranting(Access $access): array
    {
        return Product::query()->forBrand()
            ->whereJsonContains('grants', $access->handle)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'handle' => $product->handle,
                'active' => $product->active,
                'url' => cp_route('utilities.products.show', $product->id),
            ])
            ->values()
            ->all();
    }

    protected function missingCount(): int
    {
        return Access::query()->forBrand()->get()
            ->filter(fn (Access $access) => ListedAccess::hasMissingContent($access))
            ->count();
    }

    protected function json(FilteredRequest $request)
    {
        $query = Access::query()->forBrand();

        if ($search = trim((string) $request->get('search', ''))) {
            $escaped = addcslashes($search, '%_\\');

            $query->where(function (Builder $q) use ($escaped) {
                foreach (['name', 'handle'] as $column) {
                    $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
                }
            });
        }

        $sortable = ['name' => 'name', 'handle' => 'handle', 'active' => 'active', 'members' => 'opens_members_area'];
        $column = $sortable[(string) $request->get('sort', 'name')] ?? 'name';
        $direction = strtolower((string) $request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        $page = $query->orderBy($column, $direction)->paginate(Statamic::cpPerPage($request->get('perPage')));

        return (new AccessesCollection($page))
            ->columnPreferenceKey('statamic-products.accesses.columns')
            ->additional(['meta' => ['activeFilterBadges' => []]]);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('access product-accesses utility'), 403);
    }

    /**
     * @return array<string, string>
     */
    protected function strings(): array
    {
        $keys = [
            'accesses_title', 'accesses_empty_heading', 'accesses_empty_title', 'accesses_empty_description',
            'access_new', 'access_create', 'access_back_to_list', 'access_delete_title', 'access_delete_body',
            'access_delete_refused', 'access_handle_frozen', 'access_handle_help', 'access_name_help',
            'access_section_basics', 'access_section_contents', 'access_section_credits', 'access_section_products',
            'access_description', 'access_description_help', 'access_cover', 'access_cover_help',
            'access_members_area', 'access_members_area_help', 'access_active_help',
            'access_contents_help', 'access_contents_empty', 'access_content_add', 'access_content_kind',
            'access_content_label', 'access_content_label_help', 'access_content_remove', 'access_content_up', 'access_content_down',
            'target_resolved', 'target_missing', 'target_unknowable', 'target_unsaved',
            'access_credits_help', 'access_credits_empty', 'credit_add', 'credit_remove', 'credit_line',
            'credit_new_line', 'credit_session_type', 'credit_session_type_help', 'credit_kind', 'credit_count',
            'credit_per_month', 'credit_valid_months', 'credit_valid_months_placeholder', 'credit_ended',
            'credit_ended_badge', 'credit_locked_hint', 'access_products_hint', 'access_products_empty',
            'access_granted_note', 'col_contents', 'col_credits', 'col_members', 'access_missing_badge',
            'open_product', 'col_product',
            'content_ref_manual', 'credit_session_type_help_free', 'credit_summary_named',
            'credit_summary_named_subscription', 'asset_container', 'form_errors',
        ];

        $strings = [];

        foreach ($keys as $key) {
            $strings[$key] = __('statamic-products::messages.'.$key);
        }

        return $strings + [
            'field_name' => __('statamic-products::messages.field_name'),
            'field_handle' => __('statamic-products::messages.field_handle'),
            'field_active' => __('statamic-products::messages.field_active'),
            'yes' => __('statamic-products::messages.yes'),
            'no' => __('statamic-products::messages.no'),
            'utilities' => __('Utilities'),
            'save' => __('Save'),
            'edit_action' => __('Edit'),
            'delete_action' => __('Delete'),
            'col_active' => __('statamic-products::messages.column_active'),
        ];
    }
}
