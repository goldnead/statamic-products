<script setup>
/**
 * Eine Datei waehlen, mit core's Assets-Feld und seinem Asset-Browser.
 *
 * Kein eigener Browser: core's Feld bringt Hochladen, Ordner, Vorschau und
 * Rechte mit, und ein Nachbau saehe nie ganz so aus. Das Feld lebt nur in
 * einem `PublishContainer`; der Server baut dafuer je Ablage (Container) ein
 * Blueprint mit genau diesem einen Feld samt `meta`. Gibt es mehrere Ablagen,
 * waehlt man zuerst die Ablage.
 *
 * Der Wert ist die Asset-ID `container::pfad`, genau wie vorher im Textfeld.
 */
import { computed, ref, useId, watch } from 'vue';
import { PublishContainer, PublishFields, PublishFieldsProvider, Select } from '@statamic/cms/ui';

const props = defineProps({
    modelValue: { type: String, default: '' },
    // [{ handle, title, blueprint, meta }]
    pickers: { type: Array, required: true },
    containerLabel: { type: String, required: true },
    // Ohne Wert: die Ablage der zuletzt gewaehlten Datei dieses Zugangs.
    defaultContainer: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'picked']);

const uid = useId();

function known(handle) {
    return props.pickers.some((picker) => picker.handle === handle);
}

function containerOf(value) {
    const handle = typeof value === 'string' && value.includes('::') ? value.split('::')[0] : null;

    if (known(handle)) return handle;
    if (known(props.defaultContainer)) return props.defaultContainer;

    return props.pickers[0]?.handle;
}

const container = ref(containerOf(props.modelValue));

watch(() => props.modelValue, (value) => {
    if (value) container.value = containerOf(value);
});

const picker = computed(() => props.pickers.find((p) => p.handle === container.value) || props.pickers[0]);
const fields = computed(() => picker.value?.blueprint?.tabs?.[0]?.sections?.[0]?.fields ?? []);

const containerOptions = computed(() => props.pickers.map((p) => ({ value: p.handle, label: p.title })));

// `meta.data` traegt die Daten aller Dateien dieses Zugangs in der Ablage. Das
// Feld bekommt nur die eine, die ihm gehoert, sonst zeigte es fremde Dateien.
const meta = computed(() => {
    const base = picker.value?.meta || {};
    const asset = base.asset || {};
    const data = Array.isArray(asset.data) ? asset.data.filter((item) => item.id === props.modelValue) : asset.data;

    return { ...base, asset: { ...asset, data } };
});

// Nur ein Wert aus genau dieser Ablage gehoert in dieses Feld.
const values = computed(() => ({
    asset: props.modelValue && props.modelValue.startsWith(`${container.value}::`) ? [props.modelValue] : [],
}));

function changed(next) {
    const id = Array.isArray(next?.asset) ? next.asset[0] : null;

    if (id) emit('picked', container.value);

    emit('update:modelValue', id || '');
}
</script>

<template>
    <div class="space-y-3">
        <!-- Ohne eigene Beschriftung: das Feld darueber heisst schon „Datei".
             Bei nur einer erlaubten Ablage gibt es nichts zu waehlen. -->
        <Select
            v-if="pickers.length > 1"
            v-model="container"
            :options="containerOptions"
            :aria-label="containerLabel"
            :title="containerLabel"
        />

        <PublishContainer
            v-if="picker"
            :key="container"
            :name="`access-asset-${uid}-${container}`"
            :blueprint="picker.blueprint"
            :meta="meta"
            :model-value="values"
            :track-dirty-state="false"
            @update:model-value="changed"
        >
            <PublishFieldsProvider :fields="fields">
                <PublishFields />
            </PublishFieldsProvider>
        </PublishContainer>
    </div>
</template>
