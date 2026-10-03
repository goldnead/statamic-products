<script setup>
/**
 * Einen Zugang anlegen. Eine eigene Seite wie beim Produkt; geaendert wird
 * danach auf der Detailseite.
 */
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Button, CommandPaletteItem } from '@statamic/cms/ui';
import AccessFields from './Fields.vue';

const props = defineProps({
    storeUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    form: { type: Object, required: true },
    t: { type: Object, required: true },
});

const values = ref({
    handle: '', name: '', description: '', cover: '',
    active: true, opens_members_area: false,
    contents: [], credits: [],
});
const errors = ref({});
const saving = ref(false);

function save() {
    saving.value = true;

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
        <Head :title="[t.access_create, t.accesses_title]" />

        <Header :title="t.access_create" icon="key">
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

        <AccessFields :form="values" :errors="errors" :context="form" :t="t" auto-handle />
    </div>
</template>
