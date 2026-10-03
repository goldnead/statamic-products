<script setup>
/**
 * Ein Produkt: Detailseite und Formular in einem.
 *
 * Vorher oeffnete ein Klick auf die Zeile einen Stack, und diese Seite zeigte
 * nur Pillen. Beim Collection-Entry gibt es diesen Bruch nicht: die Seite ist
 * das Formular, gespeichert wird oben rechts, geloescht im „…"-Menue daneben.
 *
 * Darunter steht, was der Rest der Familie ueber das Produkt weiss: welche
 * Angebote es verkaufen und wer es gekauft hat. Jeder Abschnitt ist nur da,
 * wenn das Geschwister-Addon installiert ist. `null` heisst „kann hier nicht
 * gefragt werden", eine leere Liste „niemand", und der Bildschirm zeigt beides
 * verschieden.
 *
 * Beide Tabellen sind core's Listing im Client-Modus. Jede Beschriftung kommt
 * fertig in `t`.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, CardPanel, Text, DocsCallout, Listing, Alert, Heading,
    Dropdown, DropdownMenu, DropdownItem, ConfirmationModal, CommandPaletteItem,
} from '@statamic/cms/ui';
import ProductFields from './Fields.vue';

const props = defineProps({
    product: { type: Object, required: true },
    form: { type: Object, required: true },
    updateUrl: { type: String, required: true },
    deleteUrl: { type: String, required: true },
    offers: { type: Array, default: null },
    buyers: { type: Array, default: null },
    buyersLimit: { type: Number, default: 50 },
    indexUrl: { type: String, required: true },
    t: { type: Object, required: true },
});

const values = ref({ ...props.product.values });
const errors = ref({});
const saving = ref(false);
const confirmingDelete = ref(false);

function save() {
    saving.value = true;

    router.patch(props.updateUrl, values.value, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { saving.value = false; },
    });
}

// A sold product refuses deletion; the server answers with a validation error
// on `handle`, which has no free field to sit at while the handle is locked, so
// it is shown as a banner. A delete that silently does nothing is worse than a no.
const deleteError = ref(null);

function destroy() {
    confirmingDelete.value = false;
    deleteError.value = null;

    router.delete(props.deleteUrl, {
        onError: (e) => { deleteError.value = e?.handle || props.t.delete_refused_sold; },
    });
}

const buyersHint = computed(() => props.t.section_buyers_hint.replace(':limit', String(props.buyersLimit)));

function column(field, label, extra = {}) {
    return { field, label, visible: true, listable: true, sortable: true, defaultVisibility: true, numeric: false, ...extra };
}

const offerColumns = computed(() => [
    column('name', props.t.col_offer),
    column('slot_label', props.t.col_slot),
    column('amount', props.t.col_price, { numeric: true }),
    column('active', props.t.col_active),
    column('url', '', { sortable: false }),
]);

const buyerColumns = computed(() => [
    column('email', props.t.col_buyer),
    column('paid_at', props.t.col_paid_at),
    column('amount', props.t.col_amount, { numeric: true }),
    column('url', '', { sortable: false }),
]);

// The listing wants an id per row. A buyer may appear twice (two lines of one
// product on one payment), so the index goes into it.
const buyerItems = computed(() => (props.buyers || []).map((buyer, index) => ({ id: `${buyer.payment_id}-${index}`, ...buyer })));

function paidAt(iso) {
    if (!iso) return '—';

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? iso
        : date.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[product.name, t.title]" />

        <!-- Kernreihenfolge: erst das „…"-Menue, die Hauptaktion zuletzt. Loeschen
             ist ein `DropdownItem variant="destructive"`; `Button variant="danger"`
             gehoert nur auf den Bestaetigungsknopf im Dialog. -->
        <Header :title="values.name || product.name" icon="shopping-cart">
            <Dropdown>
                <DropdownMenu>
                    <DropdownItem :text="t.back_to_list" icon="arrow-left" :href="indexUrl" />
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

        <ProductFields :form="values" :errors="errors" :context="form" :product="product" :t="t">
          <!-- Der vierte Tab: dieselbe weisse Karte im grauen Rahmen wie die
               Formular-Tabs. Die Tabellen sind core's Listing und sitzen in der
               Karte, jede unter ihrer Ueberschrift. -->
          <template #related>
            <CardPanel>
              <div class="space-y-8">
            <!-- Offers: only when the offers addon is there. -->
            <section v-if="offers !== null">
                <Heading :text="t.section_offers" />
                <Text size="sm" variant="subtle" class="mb-3">{{ t.section_offers_hint }}</Text>
                <div v-if="offers.length === 0" class="py-8 text-center">
                    <Text size="sm" variant="subtle">{{ t.offers_empty }}</Text>
                </div>
                <Listing
                    v-else
                    :items="offers"
                    :columns="offerColumns"
                    :allow-search="false"
                    :allow-presets="false"
                    :allow-customizing-columns="false"
                    :allow-bulk-actions="false"
                >
                    <template #cell-name="{ row }">
                        <span class="font-medium">{{ row.name }}</span>
                        <span class="ms-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ row.handle }}</span>
                        <Badge v-if="!row.lead" color="default" :text="t.bundle_badge" class="ms-2" />
                    </template>
                    <template #cell-slot_label="{ row }">
                        <span class="text-xs">{{ row.slot_label }}</span>
                    </template>
                    <template #cell-amount="{ row }">
                        <span class="tabular-nums">{{ row.amount }}</span>
                        <span class="ms-1 text-2xs text-gray-500 dark:text-gray-400">{{ row.currency }}</span>
                        <Badge v-if="!row.own_price" color="default" :text="t.list_price_badge" class="ms-2" />
                    </template>
                    <template #cell-active="{ row }">
                        <Badge :color="row.active ? 'green' : 'default'" :text="row.active ? t.yes : t.no" />
                    </template>
                    <template #cell-url="{ row }">
                        <div class="text-end">
                            <Button v-if="row.url" :href="row.url" :text="t.open_offer" size="sm" />
                        </div>
                    </template>
                </Listing>
            </section>

            <!-- Buyers: only once the payments tables exist. -->
            <section v-if="buyers !== null">
                <Heading :text="t.section_buyers" />
                <Text size="sm" variant="subtle" class="mb-3">{{ buyersHint }}</Text>
                <div v-if="buyers.length === 0" class="py-8 text-center">
                    <Text size="sm" variant="subtle">{{ t.buyers_empty }}</Text>
                </div>
                <Listing
                    v-else
                    :items="buyerItems"
                    :columns="buyerColumns"
                    :allow-search="false"
                    :allow-presets="false"
                    :allow-customizing-columns="false"
                    :allow-bulk-actions="false"
                >
                    <template #cell-email="{ row }">
                        <span v-if="row.email" class="font-medium">{{ row.email }}</span>
                        <span v-else class="text-gray-500 dark:text-gray-400">{{ t.no_email }}</span>
                        <span v-if="row.name" class="ms-2 text-xs text-gray-500 dark:text-gray-400">{{ row.name }}</span>
                        <Badge v-if="row.kind === 'bump'" color="default" :text="t.kind_bump" class="ms-2" />
                        <Badge v-else-if="row.kind === 'upsell'" color="default" :text="t.kind_upsell" class="ms-2" />
                    </template>
                    <template #cell-paid_at="{ row }">
                        <span class="text-xs tabular-nums">{{ paidAt(row.paid_at) }}</span>
                    </template>
                    <template #cell-amount="{ row }">
                        <span class="tabular-nums">{{ row.amount }}</span>
                        <span class="ms-1 text-2xs text-gray-500 dark:text-gray-400">{{ row.currency }}</span>
                        <Badge v-if="row.refunded" color="amber" :text="t.refunded_badge" class="ms-2" />
                    </template>
                    <template #cell-url="{ row }">
                        <div class="text-end">
                            <Button v-if="row.url" :href="row.url" :text="t.open_payment" size="sm" />
                        </div>
                    </template>
                </Listing>
            </section>
              </div>
            </CardPanel>
          </template>
        </ProductFields>

        <!-- `:open`, not `v-if`: the modal owns its visibility and focus trap. -->
        <ConfirmationModal
            :open="confirmingDelete"
            :title="t.delete_title"
            :body-text="t.delete_body.replace(':name', product.name)"
            :button-text="t.delete_action"
            danger
            @update:open="confirmingDelete = $event"
            @confirm="destroy"
        />

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-products#readme"
        />
    </div>
</template>
