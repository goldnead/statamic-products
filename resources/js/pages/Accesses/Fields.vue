<script setup>
/**
 * Die Felder eines Zugangs, fuer Anlegen und Detailseite.
 *
 * Dasselbe Geruest wie beim Produkt (und beim Collection-Entry): Tabs ueber der
 * ganzen Breite, links der Inhalt des Tabs, rechts eine Seitenspalte, die bei
 * jedem Tab stehen bleibt. Jede Karte ist `CardPanel`.
 *
 * Inhalte und Guthabenzeilen sind Listen mit fester Reihenfolge. Core hat dafuer
 * nur den Replicator im Blueprint-Formular; dieses Formular ist von Hand
 * verdrahtet, also sind es Zeilen in einer Karte, getrennt durch Linien.
 *
 * Wer es bedient, ist kein Entwickler. Deshalb: Auswahl statt Kennungen, wo
 * immer eine Liste existiert (Zugaenge, Kurse, Termine, Sessiontypen, Dateien
 * ueber core's Asset-Browser), und Klarnamen statt Zeilennummern.
 */
import { computed, ref, useSlots, watch } from 'vue';
import {
    Alert, Badge, Button, CardPanel, Combobox, Field, Input, Select, Switch, Textarea,
    TabContent, TabList, TabTrigger, Tabs, Text,
} from '@statamic/cms/ui';
import AssetPicker from './AssetPicker.vue';

const props = defineProps({
    form: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    // kinds, creditKinds, choices, sessionTypes, assetPickers
    context: { type: Object, required: true },
    // granted, targets, values: nur beim bestehenden Zugang
    access: { type: Object, default: null },
    // Beim Anlegen fuellt sich die Kennung aus dem Namen, wie bei core.
    autoHandle: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const slots = useSlots();
const hasRelated = computed(() => Boolean(slots.related));

const tab = ref('basics');

const tabOfField = {
    name: 'basics', description: 'basics', cover: 'basics',
    contents: 'contents', credits: 'credits',
};

const sidebarFields = ['handle', 'active', 'opens_members_area'];

function errorKeys() {
    return Object.keys(props.errors || {}).filter((key) => key !== 'version');
}

// Wie viele Fehler in welchem Tab stehen. Ein Fehler in einem verdeckten Tab
// waere sonst unsichtbar.
const errorsByTab = computed(() => {
    const counts = {};

    errorKeys().forEach((key) => {
        const name = tabOfField[key.split('.')[0]];

        if (name) counts[name] = (counts[name] || 0) + 1;
    });

    return counts;
});

// Die Seitenspalte steht auf dem Handy unter dem Tab. Ihr Fehler (meist die
// Kennung) steht deshalb zusaetzlich oben, sichtbar auf jedem Tab.
const sidebarLabels = computed(() => ({
    handle: props.t.field_handle, active: props.t.field_active, opens_members_area: props.t.access_members_area,
}));

const sidebarErrors = computed(() => sidebarFields
    .filter((key) => props.errors?.[key])
    .map((key) => `${sidebarLabels.value[key]}: ${props.errors[key]}`));

watch(() => props.errors, (errors) => {
    const hit = Object.keys(errors || {}).map((key) => tabOfField[key.split('.')[0]]).find(Boolean);

    if (hit) tab.value = hit;
});

const granted = computed(() => Boolean(props.access?.granted));

// ---- Kennung aus dem Namen ---------------------------------------------------

const handleTouched = ref(Boolean(props.form.handle));

/** Wie core's Slug: Kleinbuchstaben, Umlaute ausgeschrieben, sonst Bindestriche. */
function slugify(text) {
    return String(text || '')
        .toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFKD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

watch(() => props.form.name, (name) => {
    if (props.autoHandle && !handleTouched.value && !granted.value) {
        props.form.handle = slugify(name);
    }
});

function handleInput(value) {
    handleTouched.value = value !== '';
    props.form.handle = value;
}

// ---- Inhalte ---------------------------------------------------------------

const kindsByValue = computed(() => Object.fromEntries(props.context.kinds.map((kind) => [kind.value, kind])));

function kindOptions(current) {
    // Eine gespeicherte Art, die die Website gerade nicht anmeldet, bleibt
    // waehlbar, sonst zeigte das Feld leer und speicherte sie trotzdem.
    if (current && !kindsByValue.value[current]) {
        return [...props.context.kinds, { value: current, label: current }];
    }

    return props.context.kinds;
}

function choicesFor(item) {
    const list = props.context.choices?.[item.kind];

    if (!Array.isArray(list) || list.length === 0) return null;

    // Ein Verweis, der in der Auswahl fehlt (geloescht, andere Marke), bleibt
    // als Option stehen, damit das Feld zeigt, was gespeichert ist.
    if (item.ref && !list.some((choice) => choice.value === item.ref)) {
        return [{ value: item.ref, label: item.ref }, ...list];
    }

    return list;
}

const hasAssetPicker = computed(() => (props.context.assetPickers || []).length > 0);

function usesAssetPicker(item) {
    return item.kind === 'file' && hasAssetPicker.value;
}

// Ein eingebautes Ziel ohne Auswahlliste (Geschwister fehlt, nichts angelegt)
// wird von Hand eingetragen. Das sagt das Feld dann auch.
function manualFor(item) {
    return !kindsByValue.value[item.kind]?.host && !choicesFor(item) && !usesAssetPicker(item);
}

// Die erste Art, die man bei einem Angebot am haeufigsten braucht: ein Kurs.
const defaultKind = computed(() => (kindsByValue.value.course ? 'course' : props.context.kinds[0]?.value ?? null));

function addContent() {
    props.form.contents.push({ kind: defaultKind.value, ref: '', label: '' });
}

function removeContent(index) {
    props.form.contents.splice(index, 1);
}

function moveContent(index, by) {
    const to = index + by;

    if (to < 0 || to >= props.form.contents.length) return;

    const [item] = props.form.contents.splice(index, 1);
    props.form.contents.splice(to, 0, item);
}

function target(item) {
    return props.access?.targets?.[`${item.kind}|${item.ref}`] || null;
}

const badgeOf = {
    resolved: { color: 'green', key: 'target_resolved' },
    missing: { color: 'red', key: 'target_missing' },
    unknowable: { color: 'default', key: 'target_unknowable' },
};

// ---- Guthaben --------------------------------------------------------------

const sessionTypes = computed(() => props.context.sessionTypes || []);
const sessionLabels = computed(() => Object.fromEntries(sessionTypes.value.map((type) => [type.value, type.label])));

function sessionOptions(current) {
    if (current && !sessionLabels.value[current]) {
        return [{ value: current, label: current }, ...sessionTypes.value];
    }

    return sessionTypes.value;
}

// Gefunden, fehlt, nicht pruefbar: dieselbe Auskunft wie bei Inhalten, hier
// im Browser, weil die Liste vollstaendig im Formular liegt.
function sessionState(credit) {
    if (!credit.session_type) return null;
    if (sessionTypes.value.length === 0) return 'unknowable';

    return sessionLabels.value[credit.session_type] ? 'resolved' : 'missing';
}

function creditTitle(credit) {
    const type = sessionLabels.value[credit.session_type] || credit.session_type || '…';
    const subscription = credit.kind === 'subscription';
    const count = subscription ? credit.per_month : credit.count;

    if (!count) return type;

    return (subscription ? props.t.credit_summary_named_subscription : props.t.credit_summary_named)
        .replace(':count', count)
        .replace(':type', type);
}

function hasNumber(credit) {
    return credit.line !== null && credit.line !== undefined;
}

function addCredit() {
    props.form.credits.push({
        line: null, session_type: sessionTypes.value.length === 1 ? sessionTypes.value[0].value : '',
        kind: props.context.creditKinds[0]?.value ?? 'one_time',
        count: null, per_month: null, valid_months: null, ended: false,
    });
}

function removeCredit(index) {
    props.form.credits.splice(index, 1);
}

// Nach einer Vergabe ist eine Zeile mit Nummer nicht mehr loeschbar. Der Server
// lehnt es ohnehin ab; hier faellt der Knopf weg, damit niemand es versucht.
function creditRemovable(credit) {
    return !(granted.value && hasNumber(credit));
}

// Und eine nach der Vergabe beendete Zeile bleibt beendet.
function endedLocked(credit) {
    if (!granted.value || !hasNumber(credit)) return false;

    const stored = (props.access?.values?.credits || []).find((line) => line.line === credit.line);

    return Boolean(stored?.ended);
}

function numberOrNull(target, key, value) {
    target[key] = value === '' || value === null ? null : Number(value);
}

function error(key) {
    return props.errors[key] || null;
}
</script>

<template>
    <Tabs v-model="tab">
        <Alert v-if="errorKeys().length" variant="error" class="mb-4">
            <p>{{ t.form_errors }}</p>
            <p v-for="message in sidebarErrors" :key="message" class="mt-1">{{ message }}</p>
        </Alert>

        <TabList class="overflow-x-auto overflow-y-hidden">
            <TabTrigger v-for="name in ['basics', 'contents', 'credits']" :key="name" :name="name" class="whitespace-nowrap">
                {{ t[`access_section_${name}`] }}
                <Badge v-if="errorsByTab[name]" pill color="red" :text="String(errorsByTab[name])" class="ms-1" />
            </TabTrigger>
            <TabTrigger v-if="hasRelated" name="related" :text="t.access_section_products" class="whitespace-nowrap" />
        </TabList>

        <div class="mt-4 grid max-w-[1180px] items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="min-w-0">
                <TabContent name="basics">
                    <CardPanel>
                        <div class="space-y-5">
                            <Field :label="t.field_name" :instructions="t.access_name_help" :error="error('name')" required>
                                <Input v-model="form.name" />
                            </Field>

                            <Field :label="t.access_description" :instructions="t.access_description_help" :error="error('description')">
                                <Textarea v-model="form.description" :rows="4" elastic />
                            </Field>

                            <Field :label="t.access_cover" :instructions="t.access_cover_help" :error="error('cover')">
                                <AssetPicker
                                    v-if="hasAssetPicker"
                                    :model-value="form.cover || ''"
                                    :pickers="context.assetPickers"
                                    :container-label="t.asset_container"
                                    @update:model-value="form.cover = $event"
                                />
                                <Input v-else v-model="form.cover" class="font-mono text-xs" />
                            </Field>
                        </div>
                    </CardPanel>
                </TabContent>

                <TabContent name="contents">
                    <CardPanel>
                        <div>
                            <Text size="sm" variant="subtle" class="mb-4 block">{{ t.access_contents_help }}</Text>
                            <Alert v-if="error('contents')" variant="error" :text="error('contents')" class="mb-4" />

                            <div v-if="form.contents.length === 0" class="py-6 text-center">
                                <Text size="sm" variant="subtle">{{ t.access_contents_empty }}</Text>
                            </div>

                            <ol v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                                <li
                                    v-for="(item, index) in form.contents"
                                    :key="index"
                                    class="grid gap-3 py-4 first:pt-0 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start"
                                >
                                    <Field :label="t.access_content_kind" :error="error(`contents.${index}.kind`)">
                                        <Select v-model="item.kind" :options="kindOptions(item.kind)" adaptive-width />
                                    </Field>

                                    <div class="min-w-0 space-y-3">
                                        <Field
                                            :label="kindsByValue[item.kind]?.ref_label || t.access_content_kind"
                                            :instructions="manualFor(item) ? t.content_ref_manual : null"
                                            :error="error(`contents.${index}.ref`)"
                                        >
                                            <AssetPicker
                                                v-if="usesAssetPicker(item)"
                                                :model-value="item.ref || ''"
                                                :pickers="context.assetPickers"
                                                :container-label="t.asset_container"
                                                @update:model-value="item.ref = $event"
                                            />
                                            <Combobox
                                                v-else-if="choicesFor(item)"
                                                :model-value="item.ref || null"
                                                :options="choicesFor(item)"
                                                searchable
                                                @update:model-value="item.ref = $event || ''"
                                            />
                                            <Input v-else v-model="item.ref" class="font-mono text-xs" />
                                        </Field>

                                        <Field
                                            :label="t.access_content_label"
                                            :instructions="t.access_content_label_help"
                                            :error="error(`contents.${index}.label`)"
                                        >
                                            <Input v-model="item.label" />
                                        </Field>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <template v-if="item.ref">
                                                <Badge
                                                    v-if="target(item)"
                                                    pill
                                                    :color="badgeOf[target(item).state].color"
                                                    :text="t[badgeOf[target(item).state].key]"
                                                />
                                                <Badge v-else pill color="default" :text="t.target_unsaved" />
                                                <Text v-if="target(item)?.label" size="sm" variant="subtle">{{ target(item).label }}</Text>
                                            </template>

                                            <div class="ms-auto flex gap-1">
                                                <Button
                                                    size="sm" variant="ghost" icon="arrow-up" icon-only
                                                    :aria-label="t.access_content_up" :title="t.access_content_up"
                                                    :disabled="index === 0"
                                                    @click="moveContent(index, -1)"
                                                />
                                                <Button
                                                    size="sm" variant="ghost" icon="arrow-down" icon-only
                                                    :aria-label="t.access_content_down" :title="t.access_content_down"
                                                    :disabled="index === form.contents.length - 1"
                                                    @click="moveContent(index, 1)"
                                                />
                                                <Button
                                                    size="sm" variant="ghost" icon="trash" icon-only
                                                    :aria-label="t.access_content_remove" :title="t.access_content_remove"
                                                    @click="removeContent(index)"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            </ol>

                            <div class="mt-4">
                                <Button icon="plus" :text="t.access_content_add" size="sm" @click="addContent" />
                            </div>
                        </div>
                    </CardPanel>
                </TabContent>

                <TabContent name="credits">
                    <CardPanel>
                        <div>
                            <Text size="sm" variant="subtle" class="mb-4 block">{{ t.access_credits_help }}</Text>
                            <Alert v-if="error('credits')" variant="error" :text="error('credits')" class="mb-4" />
                            <Alert v-if="granted && form.credits.length" variant="default" :text="t.credit_locked_hint" class="mb-4" />

                            <div v-if="form.credits.length === 0" class="py-6 text-center">
                                <Text size="sm" variant="subtle">{{ t.access_credits_empty }}</Text>
                            </div>

                            <ol v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                                <li v-for="(credit, index) in form.credits" :key="hasNumber(credit) ? credit.line : `neu-${index}`" class="space-y-3 py-4 first:pt-0">
                                    <!-- Kopf in Worten: was gutgeschrieben wird. Die Nummer
                                         zaehlt ab 1; intern bleibt `line`, wie sie ist. -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Text variant="strong">{{ creditTitle(credit) }}</Text>
                                        <Badge
                                            pill
                                            color="default"
                                            :text="hasNumber(credit) ? t.credit_line.replace(':line', credit.line + 1) : t.credit_new_line"
                                        />
                                        <Badge v-if="credit.ended" pill color="amber" :text="t.credit_ended_badge" />
                                        <span v-if="error(`credits.${index}.line`)" class="text-xs text-red-600 dark:text-red-400">{{ error(`credits.${index}.line`) }}</span>

                                        <div class="ms-auto flex items-center gap-3">
                                            <label v-if="hasNumber(credit)" class="flex items-center gap-2 text-sm">
                                                <Switch v-model="credit.ended" size="sm" :disabled="endedLocked(credit)" />
                                                {{ t.credit_ended }}
                                            </label>
                                            <Button
                                                v-if="creditRemovable(credit)"
                                                size="sm" variant="ghost" icon="trash" icon-only
                                                :aria-label="t.credit_remove" :title="t.credit_remove"
                                                @click="removeCredit(index)"
                                            />
                                        </div>
                                    </div>
                                    <span v-if="error(`credits.${index}.ended`)" class="block text-xs text-red-600 dark:text-red-400">{{ error(`credits.${index}.ended`) }}</span>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <Field
                                            class="sm:col-span-2"
                                            :label="t.credit_session_type"
                                            :instructions="sessionTypes.length ? t.credit_session_type_help : t.credit_session_type_help_free"
                                            :error="error(`credits.${index}.session_type`)"
                                            required
                                        >
                                            <Combobox
                                                v-if="sessionTypes.length"
                                                :model-value="credit.session_type || null"
                                                :options="sessionOptions(credit.session_type)"
                                                @update:model-value="credit.session_type = $event || ''"
                                            />
                                            <Input v-else v-model="credit.session_type" class="font-mono text-xs" />
                                            <div v-if="sessionState(credit)" class="mt-2">
                                                <Badge
                                                    pill
                                                    :color="badgeOf[sessionState(credit)].color"
                                                    :text="t[badgeOf[sessionState(credit)].key]"
                                                />
                                            </div>
                                        </Field>

                                        <Field :label="t.credit_kind" :error="error(`credits.${index}.kind`)" required>
                                            <Select v-model="credit.kind" :options="context.creditKinds" />
                                        </Field>

                                        <Field
                                            v-if="credit.kind === 'subscription'"
                                            :label="t.credit_per_month"
                                            :error="error(`credits.${index}.per_month`)"
                                            required
                                        >
                                            <Input
                                                :model-value="credit.per_month"
                                                type="number"
                                                min="1"
                                                @update:model-value="numberOrNull(credit, 'per_month', $event)"
                                            />
                                        </Field>
                                        <Field
                                            v-else
                                            :label="t.credit_count"
                                            :error="error(`credits.${index}.count`)"
                                            required
                                        >
                                            <Input
                                                :model-value="credit.count"
                                                type="number"
                                                min="1"
                                                @update:model-value="numberOrNull(credit, 'count', $event)"
                                            />
                                        </Field>

                                        <Field :label="t.credit_valid_months" :error="error(`credits.${index}.valid_months`)">
                                            <Input
                                                :model-value="credit.valid_months"
                                                type="number"
                                                min="1"
                                                :placeholder="t.credit_valid_months_placeholder"
                                                @update:model-value="numberOrNull(credit, 'valid_months', $event)"
                                            />
                                        </Field>
                                    </div>
                                </li>
                            </ol>

                            <div class="mt-4">
                                <Button icon="plus" :text="t.credit_add" size="sm" @click="addCredit" />
                            </div>
                        </div>
                    </CardPanel>
                </TabContent>

                <TabContent v-if="hasRelated" name="related">
                    <slot name="related" />
                </TabContent>
            </div>

            <!-- Die Seitenspalte, bei jedem Tab sichtbar. -->
            <div class="space-y-4">
                <CardPanel>
                    <div class="space-y-5">
                        <Field :label="t.field_active" :instructions="t.access_active_help" :error="error('active')">
                            <Switch v-model="form.active" />
                        </Field>

                        <Field :label="t.access_members_area" :instructions="t.access_members_area_help" :error="error('opens_members_area')">
                            <Switch v-model="form.opens_members_area" />
                        </Field>
                    </div>
                </CardPanel>

                <CardPanel>
                    <Field
                        :label="t.field_handle"
                        :instructions="granted ? t.access_handle_frozen : t.access_handle_help"
                        :error="error('handle')"
                        required
                    >
                        <Input :model-value="form.handle" class="font-mono" :disabled="granted" @update:model-value="handleInput" />
                    </Field>
                </CardPanel>
            </div>
        </div>
    </Tabs>
</template>
