<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Panel, Card, Text, DocsCallout, Listing,
} from '@statamic/cms/ui';

/**
 * One product, seen from the rest of the family.
 *
 * The row is edited in the stack on the listing. This screen answers the two
 * questions the listing cannot: who sells it, and who bought it. Each section
 * is there only when the sibling that knows is installed — `null` means the
 * question cannot be asked here, and a section that says "nobody" when the
 * truth is "no idea" is the wrong kind of quiet.
 *
 * Both sections are core's listing in client mode, so they sort like Entries
 * and look like Entries. Every label arrives finished in `t`.
 */
const props = defineProps({
    product: { type: Object, required: true },
    offers: { type: Array, default: null },
    buyers: { type: Array, default: null },
    buyersLimit: { type: Number, default: 50 },
    indexUrl: { type: String, required: true },
    t: { type: Object, required: true },
});

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

// The listing wants an id per row. A buyer may appear twice — two lines of
// one product on one payment — so the index goes into it.
const buyerItems = computed(() => (props.buyers || []).map((buyer, index) => ({ id: `${buyer.payment_id}-${index}`, ...buyer })));

const facts = computed(() => [
    [props.t.field_type, props.product.type_label],
    [props.t.field_digital, props.product.digital ? props.t.digital_yes : props.t.digital_no],
]);

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

        <Header :title="product.name" icon="shopping-cart">
            <Button :href="indexUrl" :text="t.back_to_list" />
        </Header>

        <div class="space-y-6">
            <!-- The facts, the way core's entry sidebar shows meta: a short
                 label/value list, no form controls. Editing is one click away
                 on the listing. -->
            <Panel :heading="t.facts_heading">
                <Card class="p-0!">
                    <dl class="divide-y divide-content-border text-sm">
                        <div class="flex gap-4 px-4 py-2.5">
                            <dt class="w-48 shrink-0 text-gray-500 dark:text-gray-400">{{ t.field_handle }}</dt>
                            <dd class="min-w-0">
                                <span class="font-mono text-xs">{{ product.handle }}</span>
                                <Badge v-if="product.sold" color="default" :text="t.sold_note" class="ms-2" />
                            </dd>
                        </div>
                        <div class="flex gap-4 px-4 py-2.5">
                            <dt class="w-48 shrink-0 text-gray-500 dark:text-gray-400">{{ t.field_amount }}</dt>
                            <dd class="tabular-nums">
                                {{ product.amount }}
                                <span class="ms-1 text-2xs text-gray-500 dark:text-gray-400">{{ product.currency }}</span>
                            </dd>
                        </div>
                        <div v-for="[label, value] in facts" :key="label" class="flex gap-4 px-4 py-2.5">
                            <dt class="w-48 shrink-0 text-gray-500 dark:text-gray-400">{{ label }}</dt>
                            <dd>{{ value }}</dd>
                        </div>
                        <div class="flex gap-4 px-4 py-2.5">
                            <dt class="w-48 shrink-0 text-gray-500 dark:text-gray-400">{{ t.field_grants }}</dt>
                            <dd class="min-w-0">
                                <span v-if="product.grants.length === 0" class="text-gray-500 dark:text-gray-400">{{ t.grants_none }}</span>
                                <span v-else class="flex flex-wrap gap-1">
                                    <Badge v-for="slug in product.grants" :key="slug" color="default" :text="slug" class="font-mono" />
                                </span>
                            </dd>
                        </div>
                        <div class="flex gap-4 px-4 py-2.5">
                            <dt class="w-48 shrink-0 text-gray-500 dark:text-gray-400">{{ t.field_active }}</dt>
                            <dd>
                                <Badge :color="product.active ? 'green' : 'default'" :text="product.active ? t.yes : t.no" />
                            </dd>
                        </div>
                    </dl>
                </Card>
            </Panel>

            <!-- Offers: only when the offers addon is there. -->
            <Panel v-if="offers !== null" :heading="t.section_offers" :subheading="t.section_offers_hint">
                <Card v-if="offers.length === 0">
                    <div class="py-8 text-center">
                        <Text size="sm" variant="subtle">{{ t.offers_empty }}</Text>
                    </div>
                </Card>
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
            </Panel>

            <!-- Buyers: only once the payments tables exist. -->
            <Panel v-if="buyers !== null" :heading="t.section_buyers" :subheading="buyersHint">
                <Card v-if="buyers.length === 0">
                    <div class="py-8 text-center">
                        <Text size="sm" variant="subtle">{{ t.buyers_empty }}</Text>
                    </div>
                </Card>
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
            </Panel>
        </div>

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-products#readme"
        />
    </div>
</template>
