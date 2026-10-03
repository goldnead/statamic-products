<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, ConfirmationModal, DropdownItem, Alert,
} from '@statamic/cms/ui';

/**
 * Zugaenge. Die Schwester der Produktliste, mit denselben Gesten: eine Zeile
 * fuehrt auf die Detailseite, „Neu" auf die eigene Seite zum Anlegen.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'name' },
    sortDirection: { type: String, default: 'asc' },
    hasAny: { type: Boolean, default: false },
    missingCount: { type: Number, default: 0 },
    missingBanner: { type: String, default: null },
    t: { type: Object, required: true },
});

const listing = ref(null);
const deleting = ref(null);
const deleteError = ref(null);

const deletePrompt = computed(() => (deleting.value
    ? props.t.access_delete_body.replace(':name', deleting.value.name)
    : ''));

function confirmRemove() {
    const row = deleting.value;
    deleting.value = null;
    deleteError.value = null;

    if (row) {
        router.delete(`${props.listingUrl}/${row.id}`, {
            preserveScroll: true,
            onError: (e) => { deleteError.value = e?.handle || props.t.access_delete_refused; },
            onSuccess: () => listing.value?.refresh(),
        });
    }
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.accesses_title]" />

        <Header :title="t.accesses_title" icon="key">
            <Button variant="primary" :text="t.access_new" :href="createUrl" />
        </Header>

        <CommandPaletteItem
            :text="[t.utilities, t.accesses_title]"
            :url="listingUrl"
            icon="key"
            prioritize
        />

        <Alert v-if="deleteError" variant="error" :text="deleteError" class="mb-4" />

        <!-- Die Zahl ueber der Tabelle, ausserhalb jeder Spalteneinstellung. -->
        <Alert v-if="missingCount" variant="error" :text="missingBanner" class="mb-4" />

        <EmptyStateMenu v-if="!hasAny" :heading="t.accesses_empty_heading">
            <EmptyStateItem
                :heading="t.accesses_empty_title"
                :description="t.accesses_empty_description"
                icon="key"
                :href="createUrl"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            ref="listing"
            :url="listingUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-products.accesses"
            push-query
        >
            <template #cell-name="{ row }">
                <a :href="row.show_url" class="text-start font-medium hover:text-primary">{{ row.name }}</a>
                <Badge v-if="row.contents_missing" color="red" :text="t.access_missing_badge" class="ms-2" />
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs">{{ row.handle }}</span>
            </template>

            <template #cell-contents="{ row }">
                <span v-if="row.contents_count" class="tabular-nums">{{ row.contents_count }}</span>
            </template>

            <template #cell-credits="{ row }">
                <span v-if="row.credits_summary" class="text-xs">{{ row.credits_summary }}</span>
            </template>

            <template #cell-members="{ row }">
                <span class="text-xs">{{ row.members ? t.yes : t.no }}</span>
            </template>

            <template #cell-active="{ row }">
                <Badge :color="row.active ? 'green' : 'default'" :text="row.active ? t.yes : t.no" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="edit" :text="t.edit_action" :href="row.show_url" />
                <DropdownItem icon="trash" variant="destructive" :text="t.delete_action" @click="deleting = row" />
            </template>
        </Listing>

        <ConfirmationModal
            :open="deleting !== null"
            :title="t.access_delete_title"
            :body-text="deletePrompt"
            :button-text="t.delete_action"
            danger
            @update:open="deleting = $event ? deleting : null"
            @confirm="confirmRemove"
        />

        <DocsCallout
            :topic="t.accesses_title"
            url="https://github.com/goldnead/statamic-products#accesses"
        />
    </div>
</template>
