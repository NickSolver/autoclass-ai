<script setup lang="ts">
import { employeesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Employee, EmployeeAssignment } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { formatDate } from '@/utils/format';
import EmployeeFormModal from './EmployeeFormModal.vue';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps<{ id: number }>();
const { push } = useToasts();
const employee = ref<Employee | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const editOpen = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        employee.value = await employeesApi.show(props.id);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить сотрудника.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const assignments = computed(() =>
    [...(employee.value?.assignments ?? [])].sort((a, b) => b.starts_on.localeCompare(a.starts_on)),
);

// Назначения
const assignmentOpen = ref(false);
const editingAssignment = ref<EmployeeAssignment | null>(null);
const unit = ref<OrgUnitValue | null>(null);
const aForm = reactive({ position: '', is_primary: false, starts_on: '', ends_on: '' });
const { submitting, errors, formError, submit, reset } = useForm();

function openAssignment(a: EmployeeAssignment | null): void {
    editingAssignment.value = a;
    unit.value = null;
    Object.assign(aForm, {
        position: a?.position ?? '',
        is_primary: a?.is_primary ?? !assignments.value.some((x) => x.is_current && x.is_primary),
        starts_on: a?.starts_on ?? new Date().toISOString().slice(0, 10),
        ends_on: a?.ends_on ?? '',
    });
    reset();
    assignmentOpen.value = true;
}

async function saveAssignment(): Promise<void> {
    const body = { position: aForm.position || null, is_primary: aForm.is_primary, starts_on: aForm.starts_on, ends_on: aForm.ends_on || null };
    const unitKey = unit.value ? `${unit.value.type}_id` : 'company_id';
    const result = await submit(() =>
        editingAssignment.value
            ? employeesApi.updateAssignment(props.id, editingAssignment.value.id, body)
            : employeesApi.addAssignment(props.id, { ...body, [unitKey]: unit.value?.id ?? null }),
    );
    if (result) {
        assignmentOpen.value = false;
        push('Назначение сохранено.');
        await load();
    }
}

const deleting = ref<EmployeeAssignment | null>(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteError = ref<string>();

async function confirmDelete(): Promise<void> {
    if (!deleting.value) return;
    deleteBusy.value = true;
    deleteError.value = undefined;
    try {
        await employeesApi.deleteAssignment(props.id, deleting.value.id);
        deleteOpen.value = false;
        push('Назначение удалено.');
        await load();
    } catch (e) {
        deleteError.value = e instanceof ApiError ? e.message : 'Ошибка.';
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="error" tone="danger" title="Карточка недоступна">{{ error }}</UiAlert>
    <template v-else-if="employee">
        <PageHeader :title="employee.full_name" :description="employee.company?.name">
            <template #actions>
                <UiBadge :tone="employee.is_active ? 'success' : 'neutral'">{{ employee.is_active ? 'Работает' : 'Не работает' }}</UiBadge>
                <UiButton v-if="employee.can.update" @click="editOpen = true">Изменить</UiButton>
            </template>
        </PageHeader>

        <div class="grid gap-5 lg:grid-cols-3">
            <UiCard title="Данные">
                <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2 text-[13px]">
                    <dt class="text-muted">Табельный №</dt><dd>{{ employee.personnel_number ?? '—' }}</dd>
                    <dt class="text-muted">E-mail</dt><dd class="break-all">{{ employee.email ?? '—' }}</dd>
                    <dt class="text-muted">Телефон</dt><dd>{{ employee.work_phone ?? '—' }}</dd>
                    <dt class="text-muted">Добавочный</dt><dd>{{ employee.phone_extension ?? '—' }}</dd>
                    <dt class="text-muted">Учётная запись</dt>
                    <dd>
                        <RouterLink v-if="employee.user" :to="`/users/${employee.user.id}`" class="text-brand-700 hover:underline">{{ employee.user.login }}</RouterLink>
                        <span v-else class="text-muted">не связана</span>
                    </dd>
                </dl>
            </UiCard>

            <UiCard title="Назначения" description="История работы в подразделениях. При переводе закройте старое назначение датой окончания и добавьте новое." class="lg:col-span-2">
                <template #actions>
                    <UiButton v-if="employee.can.update" size="sm" variant="primary" @click="openAssignment(null)">Добавить назначение</UiButton>
                </template>
                <p v-if="!assignments.length" class="text-[13px] text-muted">Назначений нет.</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="a in assignments" :key="a.id" class="flex flex-wrap items-start gap-3 py-3 first:pt-0">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ a.position ?? 'Должность не указана' }}
                                <UiBadge v-if="a.is_primary" tone="info" class="ml-1">основное</UiBadge>
                                <UiBadge v-if="!a.is_current" class="ml-1">завершено</UiBadge>
                            </p>
                            <p class="text-[13px] text-muted">{{ [a.company.name, a.dealership?.name, a.department?.name].filter(Boolean).join(' / ') }}</p>
                            <p class="text-[12px] text-muted">{{ formatDate(a.starts_on) }} — {{ a.ends_on ? formatDate(a.ends_on) : 'по настоящее время' }}</p>
                        </div>
                        <div v-if="employee.can.update" class="flex gap-1">
                            <UiButton size="sm" variant="ghost" @click="openAssignment(a)">Изменить</UiButton>
                            <UiButton size="sm" variant="ghost" @click="deleting = a; deleteError = undefined; deleteOpen = true">Удалить</UiButton>
                        </div>
                    </li>
                </ul>
            </UiCard>
        </div>

        <EmployeeFormModal v-model:open="editOpen" :employee="employee" @saved="load" />

        <UiModal v-model:open="assignmentOpen" :title="editingAssignment ? 'Назначение' : 'Новое назначение'">
            <form id="assignment-form" class="space-y-4" @submit.prevent="saveAssignment">
                <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
                <OrgUnitSelect v-if="!editingAssignment" v-model="unit" permission="employees.manage" :types="['company', 'dealership', 'department']"
                               label="Подразделение" required :error="errors.company_id || errors.dealership_id || errors.department_id" />
                <p v-else class="text-[13px] text-muted">
                    Подразделение: <span class="text-ink">{{ [editingAssignment.company.name, editingAssignment.dealership?.name, editingAssignment.department?.name].filter(Boolean).join(' / ') }}</span>
                    — не меняется; для перевода создайте новое назначение.
                </p>
                <UiInput v-model="aForm.position" label="Должность" :error="errors.position" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiInput v-model="aForm.starts_on" type="date" label="Дата начала" required :error="errors.starts_on" />
                    <UiInput v-model="aForm.ends_on" type="date" label="Дата окончания" :error="errors.ends_on" />
                </div>
                <UiCheckbox v-model="aForm.is_primary" label="Основное назначение" />
            </form>
            <template #footer>
                <UiButton @click="assignmentOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="assignment-form" variant="primary" :loading="submitting">Сохранить</UiButton>
            </template>
        </UiModal>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Удалить назначение?"
            message="Удаляйте только ошибочно созданные назначения. Для перевода сотрудника укажите дату окончания — так история сохранится."
            confirm-label="Удалить"
            danger
            :loading="deleteBusy"
            :error="deleteError"
            @confirm="confirmDelete"
        />
    </template>
</template>
