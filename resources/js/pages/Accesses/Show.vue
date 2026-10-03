<script setup>
/**
 * Ein Zugang: Detailseite und Formular in einem, wie das Produkt daneben.
 *
 * Der vierte Tab zeigt den Weg zurueck zum Verkauf: welche Produkte diesen
 * Zugang freischalten.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, CardPanel, Text, DocsCallout, Listing, Alert,
    Dropdown, DropdownMenu, DropdownItem, ConfirmationModal, CommandPaletteItem,
} from '@statamic/cms/ui';
import AccessFields from './Fields.vue';

const props = defineProps({
    access: { type: Object, required: true },
    form: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    updateUrl: { type: String, required: true },
    deleteUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    t: { type: Object, required: true },
});

// Tief kopiert: Inhalte und Zeilen sind Listen von Objekten, und eine flache
// Kopie bearbeitete die Props, aus denen das Formular nach dem Speichern neu
// gefuellt wird.
const values = ref(JSON.parse(JSON.stringify(props.access.values)));
const errors = ref({});
const saving = ref(false);
const confirmingDelete = ref(false);
const deleteError = ref(null);

// Der Stand, den dieses Formular geladen hat. Hat inzwischen jemand anderes
// gespeichert, lehnt der Server ab, statt dessen Aenderung zu ueberschreiben.
const version = ref(props.access.version);

function save() {
    saving.value = true;

    router.patch(props.updateUrl, { ...values.value, version: version.value }, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: (page) => {
            errors.value = {};
            // Neue Zeilen haben jetzt ihre Nummer; ohne Nachladen schickte das
            // naechste Speichern sie noch einmal als neu.
            values.value = JSON.parse(JSON.stringify(page.props.access.values));
            version.value = page.props.access.version;
        },
        onFinish: () => { saving.value = false; },
    });
}

function destroy() {
    confirmingDelete.value = false;
    deleteError.value = null;

    router.delete(props.deleteUrl, {
        onError: (e) => { deleteError.value = e?.handle || props.t.access_delete_refused; },
    });
}

function column(field, label, extra = {}) {
    return { field, label, visible: true, listable: true, sortable: true, defaultVisibility: true, numeric: false, ...extra };
}

const productColumns = computed(() => [
    column('name', props.t.col_product),
    column('active', props.t.col_active),
    column('url', '', { sortable: false }),
]);
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[access.name, t.accesses_title]" />

        <Header :title="values.name || access.name" icon="key">
            <Dropdown>
                <DropdownMenu>
                    <DropdownItem :text="t.access_back_to_list" icon="arrow-left" :href="indexUrl" />
                    <DropdownItem
                        :text="t.delete_action"
                        icon="trash"
                        variant="destructive"
                        @click="confirmingDelete = true"
                    />
                </DropdownMenu>
            </Dropdown>
            <CommandPaletteItem
                category="Actions"
                :text="t.save"
                icon="save"
                :action="save"
                prioritize
                v-slot="{ text, action }"
            >
                <Button variant="primary" :text="text" :disabled="saving" @click="action" />
            </CommandPaletteItem>
        </Header>

        <Alert v-if="deleteError" variant="error" :text="deleteError" class="mb-4" />
        <Alert v-if="errors.version" variant="error" :text="errors.version" class="mb-4" />

        <AccessFields :form="values" :errors="errors" :context="form" :access="access" :t="t">
            <template #related>
                <CardPanel>
                    <Text size="sm" variant="subtle" class="mb-3 block">{{ t.access_products_hint }}</Text>
                    <div v-if="products.length === 0" class="py-8 text-center">
                        <Text size="sm" variant="subtle">{{ t.access_products_empty }}</Text>
                    </div>
                    <Listing
                        v-else
                        :items="products"
                        :columns="productColumns"
                        :allow-search="false"
                        :allow-presets="false"
                        :allow-customizing-columns="false"
                        :allow-bulk-actions="false"
                    >
                        <template #cell-name="{ row }">
                            <span class="font-medium">{{ row.name }}</span>
                            <span class="ms-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ row.handle }}</span>
                        </template>
                        <template #cell-active="{ row }">
                            <Badge :color="row.active ? 'green' : 'default'" :text="row.active ? t.yes : t.no" />
                        </template>
                        <template #cell-url="{ row }">
                            <div class="text-end">
                                <Button :href="row.url" :text="t.open_product" size="sm" />
                            </div>
                        </template>
                    </Listing>
                </CardPanel>
            </template>
        </AccessFields>

        <ConfirmationModal
            :open="confirmingDelete"
            :title="t.access_delete_title"
            :body-text="t.access_delete_body.replace(':name', access.name)"
            :button-text="t.delete_action"
            danger
            @update:open="confirmingDelete = $event"
            @confirm="destroy"
        />

        <DocsCallout
            :topic="t.accesses_title"
            url="https://github.com/goldnead/statamic-products#accesses"
        />
    </div>
</template>
