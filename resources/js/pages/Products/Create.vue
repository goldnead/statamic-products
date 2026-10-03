<script setup>
/**
 * Ein neues Produkt anlegen.
 *
 * Eine eigene Seite, weil es beim Anlegen noch keinen Datensatz gibt, auf dem
 * eine Detailseite stehen koennte, genau wie „Eintrag erstellen" im Kern.
 * Geaendert wird danach auf der Detailseite selbst.
 */
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Button, CommandPaletteItem } from '@statamic/cms/ui';
import ProductFields from './Fields.vue';

const props = defineProps({
    storeUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    form: { type: Object, required: true },
    t: { type: Object, required: true },
});

/**
 * `digital` and `type` start as null, and that is the design of the fields: they
 * decide the place of supply and what the pointer even means, and every default
 * is wrong for half a catalogue.
 */
const values = ref({
    handle: '', name: '', type: null, ref: '',
    amount_cent: null, currency: null,
    interval: null, times: null, trial_days: null, trial_amount_cent: null,
    digital: null, grants: [], active: true,
});
const errors = ref({});
const saving = ref(false);

function save() {
    saving.value = true;

    // `router`, not axios: it drives the progress bar, the flash toast, the
    // dirty-state guard and the back button.
    router.post(props.storeUrl, values.value, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.create_title, t.title]" />

        <Header :title="t.create_title" icon="shopping-cart">
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

        <ProductFields :form="values" :errors="errors" :context="form" :t="t" />
    </div>
</template>
