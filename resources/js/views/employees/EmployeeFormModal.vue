<script setup lang="ts">
import { employeesApi } from '@/api';
import type { Employee } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { reactive, ref, watch } from 'vue';

const props = defineProps<{ employee?: Employee | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [employee: Employee] }>();
const { push } = useToasts();
const company = ref<OrgUnitValue | null>(null);
const form = reactive({
    last_name: '', first_name: '', middle_name: '', personnel_number: '',
    email: '', work_phone: '', phone_extension: '', is_active: true,
});
const { submitting, errors, formError, submit, reset } = useForm();

watch(open, (value) => {
    if (!value) return;
    reset();
    const e = props.employee;
    company.value = null;
    Object.assign(form, {
        last_name: e?.last_name ?? '', first_name: e?.first_name ?? '', middle_name: e?.middle_name ?? '',
        personnel_number: e?.personnel_number ?? '', email: e?.email ?? '', work_phone: e?.work_phone ?? '',
        phone_extension: e?.phone_extension ?? '', is_active: e?.is_active ?? true,
    });
});

async function save(): Promise<void> {
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    const result = await submit(() =>
        props.employee ? employeesApi.update(props.employee.id, body) : employeesApi.create({ ...body, company_id: company.value?.id }),
    );
    if (result) {
        open.value = false;
        push(props.employee ? 'Данные сотрудника сохранены.' : 'Сотрудник добавлен.');
        emit('saved', result);
    }
}
</script>

<template>
    <UiModal v-model:open="open" :title="employee ? 'Данные сотрудника' : 'Новый сотрудник'" size="lg">
        <form id="employee-form" class="space-y-4" @submit.prevent="save">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <OrgUnitSelect v-if="!employee" v-model="company" permission="employees.manage" :types="['company']" label="Основная компания" required :error="errors.company_id"
                           hint="Работодатель. Подразделение и должность задаются назначением в карточке." />
            <div class="grid gap-4 sm:grid-cols-3">
                <UiInput v-model="form.last_name" label="Фамилия" required :error="errors.last_name" />
                <UiInput v-model="form.first_name" label="Имя" required :error="errors.first_name" />
                <UiInput v-model="form.middle_name" label="Отчество" :error="errors.middle_name" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <UiInput v-model="form.personnel_number" label="Табельный номер" :error="errors.personnel_number" />
                <UiInput v-model="form.email" label="E-mail" type="email" :error="errors.email" />
                <UiInput v-model="form.work_phone" label="Рабочий телефон" :error="errors.work_phone" />
                <UiInput v-model="form.phone_extension" label="Добавочный номер" :error="errors.phone_extension" hint="Понадобится для сопоставления звонков АТС." />
            </div>
            <UiCheckbox v-model="form.is_active" label="Работает" />
        </form>
        <template #footer>
            <UiButton @click="open = false">Отмена</UiButton>
            <UiButton type="submit" form="employee-form" variant="primary" :loading="submitting">Сохранить</UiButton>
        </template>
    </UiModal>
</template>
