<script setup>
/**
 * Die Felder eines Produkts, einmal, fuer beide Seiten, die sie zeigen.
 *
 * Die Detailseite IST das Formular (wie beim Collection-Entry), und das Anlegen
 * laeuft ueber dieselben Felder. Zwei Abschriften derselben Liste liefen
 * auseinander, sobald ein Feld dazukommt.
 *
 * Aufbau nach dem Statamic-Vorbild: der graue Grund traegt die Seite, die Felder
 * sitzen auf weissen Karten, die Seitenleiste haelt den Schalter. Kein grauer
 * Container mit Ueberschrift und weisser Insel darin.
 *
 * `form` wird direkt bearbeitet, statt ueber zwanzig `v-model`-Leitungen.
 */
import { computed } from 'vue';
import { Alert, Card, Combobox, Field, Heading, Input, Select, Switch } from '@statamic/cms/ui';

const props = defineProps({
    form: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    // currency, configuredHandles, types
    context: { type: Object, required: true },
    // sold, ref_missing, shadowed, values — nur beim bestehenden Produkt
    product: { type: Object, default: null },
    t: { type: Object, required: true },
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

// A taggable combobox with an empty option list shows "1 selected" and "no
// options available" at once. The list is what has been typed so far.
const grantOptions = computed(() => (props.form.grants || []).map((slug) => ({ value: slug, label: slug })));

function numberOrNull(key, value) {
    props.form[key] = value === '' || value === null ? null : Number(value);
}
</script>

<template>
    <!-- Ein Raster, kein `flex-col` mit `lg:flex-row`: ein Raster fuegt nur eine
         Regel hinzu, statt eine des Kern-Stylesheets ueberschreiben zu muessen. -->
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">
            <Alert v-if="shadowed" variant="warning" :text="t.shadowed_warning" />
            <Alert v-if="refMissing" variant="error" :text="t.ref_missing_warning" />

            <Card>
                <Heading :text="t.section_basics" class="mb-4" />

                <div class="space-y-4">
                    <Field :label="t.field_name" :instructions="t.field_name_help" :error="errors.name" required>
                        <Input v-model="form.name" />
                    </Field>

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
                        required
                    >
                        <Input v-model="form.ref" class="font-mono" />
                    </Field>
                </div>
            </Card>

            <Card>
                <Heading :text="t.section_price" class="mb-4" />

                <div class="space-y-4">
                    <div>
                        <div class="grid grid-cols-2 gap-4">
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

                    <!-- Vier Felder, eine Entscheidung: der Hilfstext steht einmal
                         darunter. Leer ist der Normalfall (einmalig zahlen); ohne
                         Rhythmus sind die drei anderen wirkungslos und werden beim
                         Speichern mit geleert. -->
                    <div>
                        <div class="grid grid-cols-2 gap-4">
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
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-4">
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
            </Card>

            <Card>
                <Heading :text="t.section_supply" class="mb-4" />

                <div class="space-y-4">
                    <!-- Keine Vorauswahl, und der leere Zustand ist der Punkt: eine
                         Steuerangabe, die ein Default fuer jemanden beantwortete. -->
                    <Field :label="t.field_digital" :instructions="t.field_digital_help" :error="errors.digital" required>
                        <Select v-model="form.digital" :options="supplyOptions" />
                    </Field>

                    <Field :label="t.field_grants" :instructions="t.field_grants_help" :error="errors.grants">
                        <!-- `taggable`: ein Zugangsname ist ein Name, den die Website
                             erfindet, keine Liste, die dieses Addon anbieten koennte.
                             `searchable` ist daneben Pflicht: das Tag wird ins
                             Suchfeld getippt. -->
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
                </div>
            </Card>
        </div>

        <!-- Die Seitenspalte wie beim Entry: der Schalter. -->
        <div class="space-y-4">
            <Card>
                <Heading :text="t.section_visibility" class="mb-4" />

                <Field :label="t.field_active" :instructions="t.field_active_help" :error="errors.active">
                    <Switch v-model="form.active" />
                </Field>
            </Card>
        </div>
    </div>
</template>
