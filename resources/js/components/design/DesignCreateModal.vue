<script setup lang="ts">
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useForm } from '@/composables/useForm';
import { reactive, watch } from 'vue';

/** Создание чек-листа или процесса: название и область (вся группа или компания). */
const props = defineProps<{
    title: string;
    permission: string;
    withCode?: boolean;
    action: (body: Record<string, unknown>) => Promise<void>;
}>();
const open = defineModel<boolean>('open', { required: true });
const form = reactive({ code: '', name: '', description: '', company: null as OrgUnitValue | null });
const { submitting, errors, formError, submit, reset } = useForm();

watch(open, (v) => {
    if (v) {
        Object.assign(form, { code: '', name: '', description: '', company: null });
        reset();
    }
});

async function save(): Promise<void> {
    await submit(() =>
        props.action({
            ...(props.withCode ? { code: form.code } : {}),
            name: form.name,
            description: form.description || null,
            company_id: form.company?.id ?? null,
        }),
    );
}
</script>

<template>
    <UiModal v-model:open="open" :title="title">
        <form id="design-create" class="space-y-4" @submit.prevent="save">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <UiInput v-if="withCode" v-model="form.code" label="Код" required :error="errors.code" hint="Латиница в нижнем регистре, цифры, подчёркивание." />
            <UiInput v-model="form.name" label="Название" required :error="errors.name" />
            <UiTextarea v-model="form.description" label="Описание" :rows="2" :error="errors.description" />
            <OrgUnitSelect
                v-model="form.company"
                :permission="permission"
                :types="['company']"
                label="Область"
                placeholder="Вся группа (общий)"
                hint="Общий — для всей группы (нужно право на уровне группы). Компании — только для её звонков."
                :error="errors.company_id"
            />
        </form>
        <template #footer>
            <UiButton @click="open = false">Отмена</UiButton>
            <UiButton type="submit" form="design-create" variant="primary" :loading="submitting">Создать</UiButton>
        </template>
    </UiModal>
</template>
