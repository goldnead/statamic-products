<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, ConfirmationModal, DropdownItem, Alert,
} from '@statamic/cms/ui';

/**
 * Products.
 *
 * Built as the sibling of the offers screen next door, on purpose: two addons
 * that answer the same gestures differently is a worse tell than either of them
 * looking slightly off on its own.
 *
 * A row leads to the product's own page, and so does "new". Nothing is edited
 * here any more: the detail page is the form, the way a collection entry is.
 *
 * Every label arrives finished in `t`. Nothing here composes a sentence, and
 * nothing here decides what anything is worth.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'name' },
    sortDirection: { type: String, default: 'asc' },
    hasAny: { type: Boolean, default: false },
    danglingCount: { type: Number, default: 0 },
    danglingBanner: { type: String, default: null },
    t: { type: Object, required: true },
});

/**
 * The listing fetches its own rows over axios; an Inertia redirect updates the
 * page's props but never touches them. Without asking it to refresh, a deleted
 * row is still there afterwards and the delete looks like it failed.
 */
const listing = ref(null);

/**
 * Deleting asks first, and a sold product refuses outright.
 *
 * The refusal comes back from the server as a validation error rather than a
 * toast, so it is shown here too: a Delete button that silently does nothing is
 * worse than one that says no.
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
        router.delete(`${props.listingUrl}/${row.id}`, {
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
            <Button variant="primary" :text="t.new" :href="createUrl" />
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
                :href="createUrl"
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
                <a :href="row.show_url" class="text-start font-medium hover:text-primary">{{ row.name }}</a>
                <!-- Which product it is. Every column is toggleable, this one
                     too, so the count above the table is what actually
                     guarantees the defect is seen; this badge names it. -->
                <Badge v-if="row.ref_missing" color="red" :text="t.ref_missing_badge" class="ms-2" />
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs">{{ row.handle }}</span>
                <!-- The collision, on the row. Config wins silently in the
                     catalogue, and silent is right for the answer and wrong
                     for the screen. -->
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

            <!-- Eine Zeile, ein Ziel: "Bearbeiten" und "Ansehen" waeren derselbe
                 Link mit zwei Namen, seit die Detailseite das Formular ist. -->
            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="edit" :text="t.edit_action" :href="row.show_url" />
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

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-products#readme"
        />
    </div>
</template>
