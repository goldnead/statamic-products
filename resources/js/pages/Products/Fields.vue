<script setup>
/**
 * Die Felder eines Produkts, einmal, fuer beide Seiten, die sie zeigen.
 *
 * Die Detailseite IST das Formular (wie beim Collection-Entry), und das Anlegen
 * laeuft ueber dieselben Felder. Zwei Abschriften derselben Liste liefen
 * auseinander, sobald ein Feld dazukommt.
 *
 * Geruest wie beim Entry: Tabs ueber der ganzen Breite, darunter links der
 * Inhalt des Tabs, rechts eine Seitenspalte, die bei jedem Tab stehen bleibt.
 * In die Seitenspalte kommt, was man beim Bearbeiten immer sehen will: der
 * Schalter und die Kennung. Jede Karte ist `Panel > Card`, so baut core seine
 * Abschnitte (`Publish/Sections.vue`): das Panel ist der graue Rahmen, die Karte
 * die weisse Flaeche darin.
 *
 * Core baut Tabs und Seitenspalte aus einem Blueprint (`PublishTabs`). Dieses
 * Formular hat keinen, weil seine Felder von Hand verdrahtet sind; die
 * Bauteile sind dieselben, das Gitter (`1fr` und 20rem, Abstand 8) auch.
 *
 * Der vierte Tab, „Angebote und Kaeufer", gehoert der Detailseite: wer sie
 * fuellt, gibt ihn ueber den Slot `related`. Beim Anlegen gibt es ihn nicht.
 *
 * `form` wird direkt bearbeitet, statt ueber zwanzig `v-model`-Leitungen.
 */
import { computed, ref, useSlots, watch } from 'vue';
import {
    Alert, CardPanel, Combobox, Field, Input, Select, Switch,
    TabContent, TabList, TabTrigger, Tabs,
} from '@statamic/cms/ui';

const props = defineProps({
    form: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    // currency, configuredHandles, types
    context: { type: Object, required: true },
    // sold, ref_missing, shadowed, values — nur beim bestehenden Produkt
    product: { type: Object, default: null },
    t: { type: Object, required: true },
});

const slots = useSlots();
const hasRelated = computed(() => Boolean(slots.related));

const tab = ref('basics');

// Welcher Tab welches Feld traegt. Ein Fehler in einem verdeckten Tab waere
// unsichtbar, also springt die Seite zum ersten Tab mit einem Fehler.
const tabOfField = {
    name: 'basics', type: 'basics', ref: 'basics',
    amount_cent: 'price', currency: 'price', interval: 'price', times: 'price',
    trial_days: 'price', trial_amount_cent: 'price',
    digital: 'supply', grants: 'supply',
};

watch(() => props.errors, (errors) => {
    const hit = Object.keys(errors || {}).map((key) => tabOfField[key.split('.')[0]]).find(Boolean);

    if (hit) tab.value = hit;
});

/**
 * Strings, not booleans: `Select` declares its `modelValue` as Object, Number or
 * String, and a boolean option silently never selects. Laravel's `boolean` rule
 * reads '1' and '0' alike, and '0' still counts as *present* for `required`.
 */
const supplyOptions = computed(() => [
    { value: '1', label: props.t.digital_yes },
    { value: '0', label: props.t.digital_no },
]);

const shadowed = computed(() => props.form.handle !== ''
    && props.context.configuredHandles.includes(props.form.handle));

const chosenType = computed(() => props.context.types.find((type) => type.value === props.form.type) || null);

// A download's thing *is* the product, so it points at nothing.
const needsRef = computed(() => Boolean(chosenType.value?.needs_ref));

const refMissing = computed(() => Boolean(props.product?.ref_missing) && props.form.ref === props.product.values.ref);

const handleFrozen = computed(() => Boolean(props.product?.sold));

// Die Zugaenge dieser Marke, dazu jeder Slug, der schon am Produkt steht und
// keinen Zugang hat (Bestand). Ohne die zweite Haelfte zeigte der Picker einen
// gespeicherten Slug als leere Pille. Gespeichert wird in beiden Faellen der Slug.
const accessOptions = computed(() => (props.context.accesses || []).map((access) => ({
    value: access.value,
    label: `${access.label} (${access.value})`,
})));

const knownSlugs = computed(() => new Set((props.context.accesses || []).map((access) => access.value)));

const unresolvedGrants = computed(() => (props.form.grants || []).filter((slug) => !knownSlugs.value.has(slug)));

// Fuehrt ein freigeschalteter Zugang die Inhalte, ist der Verweis optional; der
// Server prueft dasselbe.
const grantsAnAccess = computed(() => (props.form.grants || []).some((slug) => knownSlugs.value.has(slug)));

const grantOptions = computed(() => [
    ...accessOptions.value,
    ...unresolvedGrants.value.map((slug) => ({ value: slug, label: slug })),
]);

function numberOrNull(key, value) {
    props.form[key] = value === '' || value === null ? null : Number(value);
}
</script>

<template>
    <Tabs v-model="tab">
        <!-- Vier Tabs sind auf 390px breiter als die Zeile. Statt in drei Zeilen
             umzubrechen, scrollt die Leiste waagerecht. -->
        <TabList class="overflow-x-auto overflow-y-hidden">
            <TabTrigger name="basics" :text="t.section_basics" class="whitespace-nowrap" />
            <TabTrigger name="price" :text="t.section_price" class="whitespace-nowrap" />
            <TabTrigger name="supply" :text="t.section_supply" class="whitespace-nowrap" />
            <TabTrigger v-if="hasRelated" name="related" :text="t.show_action" class="whitespace-nowrap" />
        </TabList>

        <!-- Das Gitter von `Publish/Tabs.vue`: Inhalt und Seitenspalte, Abstand 8.
             Unter 1024px liegt die Seitenspalte unter dem Inhalt, wie beim Entry. -->
        <div class="mt-4 grid max-w-[1180px] items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="min-w-0">
                <div v-if="shadowed || refMissing" class="mb-4 space-y-4">
                    <Alert v-if="shadowed" variant="warning" :text="t.shadowed_warning" />
                    <Alert v-if="refMissing" variant="error" :text="t.ref_missing_warning" />
                </div>

                <TabContent name="basics">
                    <CardPanel>
                        <div class="space-y-5">
                                <Field :label="t.field_name" :instructions="t.field_name_help" :error="errors.name" required>
                                    <Input v-model="form.name" />
                                </Field>

                                <div>
                                    <Field :label="t.field_type" :instructions="t.field_type_help" :error="errors.type" required>
                                        <Select v-model="form.type" :options="context.types" />
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
                                    :required="!grantsAnAccess"
                                >
                                    <Input v-model="form.ref" class="font-mono text-xs" />
                                </Field>
                        </div>
                    </CardPanel>
                </TabContent>

                <TabContent name="price">
                    <CardPanel>
                        <div class="space-y-5">
                                <div>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field :label="t.field_amount" :error="errors.amount_cent" required>
                                            <Input
                                                :model-value="form.amount_cent"
                                                type="number"
                                                min="0"
                                                :append="form.currency || context.currency"
                                                @update:model-value="numberOrNull('amount_cent', $event)"
                                            />
                                        </Field>

                                        <Field :label="t.field_currency" :error="errors.currency">
                                            <Input v-model="form.currency" class="font-mono uppercase" :placeholder="context.currency" />
                                        </Field>
                                    </div>

                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ t.field_amount_help }} {{ t.field_currency_help }}
                                    </p>
                                </div>

                                <!-- Vier Felder, eine Entscheidung: der Hilfstext steht
                                     einmal darunter. Leer ist der Normalfall (einmalig
                                     zahlen); ohne Rhythmus sind die drei anderen
                                     wirkungslos und werden beim Speichern mit geleert. -->
                                <div>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field :label="t.field_interval" :error="errors.interval">
                                            <Input v-model="form.interval" class="font-mono" :placeholder="t.field_interval_placeholder" />
                                        </Field>

                                        <Field :label="t.field_times" :error="errors.times">
                                            <Input
                                                :model-value="form.times"
                                                type="number"
                                                min="1"
                                                :placeholder="t.field_times_placeholder"
                                                :disabled="!form.interval"
                                                @update:model-value="numberOrNull('times', $event)"
                                            />
                                        </Field>

                                        <Field :label="t.field_trial_days" :error="errors.trial_days">
                                            <Input
                                                :model-value="form.trial_days"
                                                type="number"
                                                min="0"
                                                :disabled="!form.interval"
                                                @update:model-value="numberOrNull('trial_days', $event)"
                                            />
                                        </Field>

                                        <Field :label="t.field_trial_amount" :error="errors.trial_amount_cent">
                                            <Input
                                                :model-value="form.trial_amount_cent"
                                                type="number"
                                                min="0"
                                                :append="form.currency || context.currency"
                                                :disabled="!form.interval"
                                                @update:model-value="numberOrNull('trial_amount_cent', $event)"
                                            />
                                        </Field>
                                    </div>

                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_plan_help }}</p>
                                </div>
                        </div>
                    </CardPanel>
                </TabContent>

                <TabContent name="supply">
                    <CardPanel>
                        <div class="space-y-5">
                                <!-- Keine Vorauswahl, und der leere Zustand ist der
                                     Punkt: eine Steuerangabe, die ein Default fuer
                                     jemanden beantwortete. -->
                                <Field :label="t.field_digital" :instructions="t.field_digital_help" :error="errors.digital" required>
                                    <Select v-model="form.digital" :options="supplyOptions" />
                                </Field>

                                <Field :label="t.field_grants" :instructions="t.field_grants_help" :error="errors.grants">
                                    <!-- Ein Picker ueber die Zugaenge. `taggable` bleibt:
                                         bis zur Uebernahme fuehrt die Website manche Zugaenge
                                         noch selbst, und deren Slug muss sich weiter
                                         eintippen lassen. `searchable` ist daneben Pflicht. -->
                                    <Combobox
                                        v-model="form.grants"
                                        :options="grantOptions"
                                        :placeholder="t.field_grants_placeholder"
                                        multiple
                                        searchable
                                        taggable
                                        clearable
                                    />
                                    <!-- Kein Fehler, nur eine Auskunft: vergeben wird der
                                         Slug trotzdem. -->
                                    <p v-if="unresolvedGrants.length" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ t.grants_unresolved.replace(':slugs', unresolvedGrants.join(', ')) }}
                                    </p>
                                </Field>
                        </div>
                    </CardPanel>
                </TabContent>

                <TabContent v-if="hasRelated" name="related">
                    <slot name="related" />
                </TabContent>
            </div>

            <!-- Die Seitenspalte wie beim Entry, bei jedem Tab sichtbar. -->
            <div class="space-y-4">
                <CardPanel>
                    <div class="space-y-5">
                        <Field :label="t.field_active" :instructions="t.field_active_help" :error="errors.active">
                            <Switch v-model="form.active" />
                        </Field>
                    </div>
                </CardPanel>

                <CardPanel>
                    <div class="space-y-5">
                        <Field
                            :label="t.field_handle"
                            :instructions="handleFrozen ? t.handle_frozen : t.field_handle_help"
                            :error="errors.handle"
                            required
                        >
                            <!-- Locked rather than merely refused: the server says no
                                 either way, this stops somebody typing a name into a
                                 field that was never going to accept it. -->
                            <Input v-model="form.handle" class="font-mono" :disabled="handleFrozen" />
                        </Field>
                    </div>
                </CardPanel>
            </div>
        </div>
    </Tabs>
</template>
