<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, Stack, Heading, ConfirmationModal,
    Field, Input, Select, Combobox, Switch, DropdownItem, Alert,
} from '@statamic/cms/ui';

/**
 * Products.
 *
 * Built as the sibling of the offers screen next door, on purpose: two addons
 * that answer the same gestures differently is a worse tell than either of them
 * looking slightly off on its own.
 *
 * Every label arrives finished in `t`. Nothing here composes a sentence, and
 * nothing here decides what anything is worth — the row shows what the server
 * worked out, and the form posts what somebody typed for the server to judge.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'name' },
    sortDirection: { type: String, default: 'asc' },
    hasAny: { type: Boolean, default: false },
    currency: { type: String, default: 'EUR' },
    configuredHandles: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    danglingCount: { type: Number, default: 0 },
    danglingBanner: { type: String, default: null },
    t: { type: Object, required: true },
});

/**
 * `digital` starts as null and that is the whole design of the field.
 *
 * It decides the place of supply and with it the mandatory tax notice, and
 * every default is wrong for half a catalogue. A switch would have a resting
 * position and would therefore answer the question on somebody's behalf; a
 * select with nothing chosen makes them answer it.
 */
const blank = () => ({
    handle: '', name: '',
    // Null, like `digital`: the kind decides what the pointer beside it even
    // means, so a preselected one would give that pointer a meaning nobody
    // chose.
    type: null, ref: '',
    amount_cent: null, currency: null,
    digital: null,
    grants: [],
    active: true,
    sold: false,
});

/**
 * Strings, not booleans, and it cost a screenshot to find out.
 *
 * `Select` declares its `modelValue` as Object, Number or String. A boolean
 * option silently never selects: the dropdown opens, the option is there, the
 * click does nothing and the field keeps saying "choose one". Laravel's
 * `boolean` rule accepts `'1'` and `'0'`, and `'0'` is still *present* for
 * `required` — which is the whole reason this field exists.
 */
const supplyOptions = computed(() => [
    { value: '1', label: props.t.digital_yes },
    { value: '0', label: props.t.digital_no },
]);

/**
 * The listing fetches its own rows over axios; an Inertia redirect updates the
 * page's props but never touches them. Without asking it to refresh, a saved
 * row simply is not there afterwards and the save looks like it failed.
 */
const listing = ref(null);

const open = ref(false);
const saving = ref(false);
const errors = ref({});
const editing = ref(null);
const form = ref(blank());

const title = computed(() => (editing.value ? props.t.edit : props.t.new));

/**
 * Whether the handle being typed is already a line in the config file.
 *
 * Shown while typing rather than after saving. Config wins in the catalogue, so
 * a colliding row saves cleanly, looks right on this screen, and charges the
 * other price — which is exactly how a checkout once took 330 while the
 * catalogue said 332, and the person who noticed was a customer.
 */
const shadowed = computed(() => form.value.handle !== ''
    && props.configuredHandles.includes(form.value.handle));

/** The kind currently chosen, with the words that belong to it. */
const chosenType = computed(() => props.types.find((t) => t.value === form.value.type) || null);

/**
 * Whether this kind names something elsewhere.
 *
 * A download's thing *is* the product, so it points at nothing and the field
 * goes away rather than sitting there empty and inviting a leftover id.
 */
const needsRef = computed(() => Boolean(chosenType.value?.needs_ref));

/**
 * The chosen slugs, handed back to the combobox as its own options.
 *
 * Without this it has none, and a `taggable` combobox with an empty option list
 * shows two lies at once: the trigger says "1 selected" instead of naming what
 * was picked, and the dropdown says "no options available" under a field that
 * has just accepted one. Both read as a broken control rather than an empty
 * list. There is no list to offer from — an access slug is a name the site
 * invents — so the list is what has been typed so far.
 */
const grantOptions = computed(() => (form.value.grants || [])
    .map((slug) => ({ value: slug, label: slug })));

/** Whether the row being edited points at something that is gone. */
const refMissing = computed(() => Boolean(editing.value?.ref_missing) && form.value.ref === editing.value.ref);

/** Sold products keep their handle. The server refuses it either way. */
const handleFrozen = computed(() => Boolean(editing.value && form.value.sold));

function create() {
    editing.value = null;
    form.value = blank();
    errors.value = {};
    open.value = true;
}

function edit(row) {
    editing.value = row;
    form.value = { ...blank(), ...row.edit_values };
    errors.value = {};
    open.value = true;
}

function save() {
    saving.value = true;
    const url = editing.value ? `${props.storeUrl}/${editing.value.id}` : props.storeUrl;
    const method = editing.value ? 'patch' : 'post';

    // `router`, not axios: the Inertia router is what drives the progress bar,
    // the flash toast, the dirty-state guard and the back button.
    router[method](url, form.value, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { open.value = false; errors.value = {}; listing.value?.refresh(); },
        onFinish: () => { saving.value = false; },
    });
}

/**
 * Deleting asks first, and a sold product refuses outright.
 *
 * The refusal comes back from the server as a validation error rather than a
 * toast, so it is shown here too — a Delete button that silently does nothing
 * is worse than one that says no.
 */
const deleting = ref(null);
const deleteError = ref(null);

const deletePrompt = computed(() => (deleting.value
    ? props.t.delete_body.replace(':name', deleting.value.name)
    : ''));

function confirmRemove() {
    const row = deleting.value;
    deleting.value = null;
    deleteError.value = null;

    if (row) {
        router.delete(`${props.storeUrl}/${row.id}`, {
            preserveScroll: true,
            onError: (e) => { deleteError.value = e?.handle || props.t.delete_refused_sold; },
            onSuccess: () => listing.value?.refresh(),
        });
    }
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <Header :title="t.title" icon="shopping-cart">
            <Button variant="primary" :text="t.new" @click="create" />
        </Header>

        <CommandPaletteItem
            :text="[t.utilities, t.title]"
            :url="listingUrl"
            icon="shopping-cart"
            prioritize
        />

        <Alert v-if="deleteError" variant="error" :text="deleteError" class="mb-4" />

        <!-- The count, above the table and outside every column preference.
             The badge on a row says *which* product points nowhere; this says
             *that* some do, and it survives a reader who has hidden the column
             the badge sits on. Every column here is toggleable, the name
             included — there is no such thing as an unhideable one. -->
        <Alert v-if="danglingCount" variant="error" :text="danglingBanner" class="mb-4" />

        <EmptyStateMenu v-if="!hasAny" :heading="t.empty_heading">
            <EmptyStateItem
                :heading="t.empty_title"
                :description="t.empty_description"
                icon="shopping-cart"
                @click="create"
            />
        </EmptyStateMenu>

        <!-- The core listing, fed the way core feeds its own. No `actionUrl`:
             there are no bulk actions here, and passing one would turn on
             checkboxes and a toolbar that do nothing. A `preferences-prefix` is
             what makes saved views and the column picker remember anything. -->
        <Listing
            v-else
            ref="listing"
            :url="listingUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-products.products"
            push-query
        >
            <template #cell-name="{ row }">
                <button type="button" class="font-medium hover:text-primary" @click="edit(row)">
                    {{ row.name }}
                </button>
                <!-- Which product it is. Every column is toggleable, this one
                     too, so the count above the table is what actually
                     guarantees the defect is seen; this badge names it. -->
                <Badge v-if="row.ref_missing" color="red" :text="t.ref_missing_badge" class="ms-2" />
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs">{{ row.handle }}</span>
                <!-- The collision, on the row. Config wins silently in the
                     catalogue, and silent is right for the answer and wrong for
                     the screen. -->
                <Badge v-if="row.shadowed" color="amber" :text="t.shadowed_badge" class="ms-2" />
            </template>

            <template #cell-amount="{ row }">
                <span class="tabular-nums">{{ row.amount }}</span>
                <span class="ms-1 text-2xs text-gray-500 dark:text-gray-400">{{ row.currency }}</span>
            </template>

            <template #cell-type="{ row }">
                <span class="text-xs">{{ row.type_label }}</span>
            </template>

            <template #cell-ref="{ row }">
                <span v-if="row.ref" class="font-mono text-xs">{{ row.ref_label || row.ref }}</span>
            </template>

            <template #cell-digital="{ row }">
                <span class="text-xs">{{ row.digital ? t.digital_yes : t.digital_no }}</span>
            </template>

            <template #cell-grants="{ row }">
                <span v-if="row.grants_count" class="tabular-nums">{{ row.grants_count }}</span>
            </template>

            <template #cell-active="{ row }">
                <Badge :color="row.active ? 'green' : 'default'" :text="row.active ? t.yes : t.no" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="edit" :text="t.edit_action" @click="edit(row)" />
                <DropdownItem icon="trash" variant="destructive" :text="t.delete_action" @click="deleting = row" />
            </template>
        </Listing>

        <!-- `:open`, not `v-if`. The modal owns its own visibility and its own
             focus trap; mounting it conditionally means it never opens, which
             looks exactly like a Delete button that does nothing. -->
        <ConfirmationModal
            :open="deleting !== null"
            :title="t.delete_title"
            :body-text="deletePrompt"
            :button-text="t.delete_action"
            danger
            @update:open="deleting = $event ? deleting : null"
            @confirm="confirmRemove"
        />

        <Stack v-model:open="open" size="narrow">
            <!-- Surfaces use core's tokens, never a literal colour: the palette
                 is themeable at runtime, and a hard-coded surface drifts the
                 moment somebody re-themes their Control Panel. -->
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="title" size="lg" />
                </div>

                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <Alert v-if="shadowed" variant="warning" :text="t.shadowed_warning" />
                    <Alert v-if="refMissing" variant="error" :text="t.ref_missing_warning" />

                    <Field :label="t.field_name" :instructions="t.field_name_help" :error="errors.name" required>
                        <Input v-model="form.name" />
                    </Field>

                    <Field
                        :label="t.field_handle"
                        :instructions="handleFrozen ? t.handle_frozen : t.field_handle_help"
                        :error="errors.handle"
                        required
                    >
                        <!-- Locked rather than merely refused. The server says
                             no either way; this is the half that stops somebody
                             typing a new name into a field that was never going
                             to accept it. -->
                        <Input v-model="form.handle" class="font-mono" :disabled="handleFrozen" />
                    </Field>

                    <!-- One explanation under both, not one beside each: two
                         instruction blocks in a two-column grid set the fields
                         at different heights and the row reads as broken. -->
                    <div>
                        <div class="grid grid-cols-2 gap-4">
                            <Field :label="t.field_amount" :error="errors.amount_cent" required>
                                <Input
                                    :model-value="form.amount_cent"
                                    type="number"
                                    min="0"
                                    :append="form.currency || currency"
                                    @update:model-value="form.amount_cent = $event === '' ? null : Number($event)"
                                />
                            </Field>

                            <Field :label="t.field_currency" :error="errors.currency">
                                <Input v-model="form.currency" class="font-mono uppercase" :placeholder="currency" />
                            </Field>
                        </div>

                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ t.field_amount_help }} {{ t.field_currency_help }}
                        </p>
                    </div>

                    <!-- The kind, and directly under it the pointer that only
                         means anything once the kind is chosen. Two fields, one
                         decision, so they sit together and the second explains
                         itself in the first's words. -->
                    <div>
                        <Field :label="t.field_type" :instructions="t.field_type_help" :error="errors.type" required>
                            <Select v-model="form.type" :options="types" />
                        </Field>

                        <p v-if="chosenType" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ chosenType.description }}
                        </p>
                    </div>

                    <Field
                        v-if="needsRef"
                        :label="chosenType.ref_label"
                        :instructions="t.field_ref_help"
                        :error="errors.ref"
                        required
                    >
                        <Input v-model="form.ref" class="font-mono" />
                    </Field>

                    <!-- No preselection, and the empty state is the point: this
                         is a tax fact, and a default would answer it on
                         somebody's behalf. -->
                    <Field :label="t.field_digital" :instructions="t.field_digital_help" :error="errors.digital" required>
                        <Select v-model="form.digital" :options="supplyOptions" />
                    </Field>

                    <Field :label="t.field_grants" :instructions="t.field_grants_help" :error="errors.grants">
                        <!-- `taggable`: an access slug is a name the site
                             invents, not one this addon can offer a list of.
                             `statamic-entitlements` takes free strings by
                             design and stays ignorant of what a product is. -->
                        <!-- `searchable` is not optional next to `taggable`:
                             the tag is typed into the search input, and without
                             it there is no input to type into. The field then
                             renders, opens, says "no options available" and
                             accepts nothing — which looks like an empty list
                             rather than a broken control. -->
                        <Combobox
                            v-model="form.grants"
                            :options="grantOptions"
                            :placeholder="t.field_grants_placeholder"
                            multiple
                            searchable
                            taggable
                            clearable
                        />
                    </Field>

                    <Field :label="t.field_active">
                        <Switch v-model="form.active" />
                    </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t.cancel" @click="open = false" />
                        <Button variant="primary" :text="t.save" :disabled="saving" @click="save" />
                    </div>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-products#readme"
        />
    </div>
</template>
