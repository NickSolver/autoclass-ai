<script setup lang="ts">
import { orgApi } from '@/api';
import type { Company, Department } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useForm } from '@/composables/useForm';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { departmentKinds } from '@/utils/format';
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

const auth = useAuthStore();
const route = useRoute();
const { push } = useToasts();
const list = usePaginatedList(orgApi.departments.list, {
    q: '',
    company_id: '',
    dealership_id: (route.query.dealership_id as string | undefined) ?? '',
    is_active: '' as '' | '1' | '0',
});
const companies = ref<Company[]>([]);
onMounted(async () => {
    companies.value = (await orgApi.companies.list({ per_page: 100 }).catch(() => ({ data: [] }))).data;
});

const kindOptions = Object.entries(departmentKinds).map(([value, label]) => ({ value, label }));
const columns = [
    { key: 'name', label: 'Отдел' },
    { key: 'place', label: 'Подразделение' },
    { key: 'kind', label: 'Тип', class: 'w-36' },
    { key: 'status', label: 'Статус', class: 'w-28' },
    { key: 'actions', label: '', class: 'w-24 text-right' },
];

const modalOpen = ref(false);
const editing = ref<Department | null>(null);
const parent = ref<OrgUnitValue | null>(null);
const form = reactive({ code: '', name: '', kind: null as string | null, is_active: true });
const { submitting, errors, formError, submit, reset } = useForm();

function openCreate(): void {
    editing.value = null;
    parent.value = null;
    Object.assign(form, { code: '', name: '', kind: null, is_active: true });
    reset();
    modalOpen.value = true;
}

function openEdit(d: Department): void {
    editing.value = d;
    Object.assign(form, { code: d.code, name: d.name, kind: d.kind, is_active: d.is_active });
    reset();
    modalOpen.value = true;
}

/** Родитель — компания (отдел уровня компании) или дилерский центр. */
async function parentIds(): Promise<{ company_id?: number; dealership_id?: number | null }> {
    if (!parent.value) return {};
    if (parent.value.type === 'company') return { company_id: parent.value.id, dealership_id: null };
    const dealership = await orgApi.dealerships.show(parent.value.id);
    return { company_id: dealership.company_id, dealership_id: dealership.id };
}

async function save(): Promise<void> {
    const body = { code: form.code, name: form.name, kind: form.kind, is_active: form.is_active };
    const result = await submit(async () =>
        editing.value ? orgApi.departments.update(editing.value.id, body) : orgApi.departments.create({ ...body, ...(await parentIds()) }),
    );
    if (result) {
        modalOpen.value = false;
        push('Отдел сохранён.');
        await list.load();
    }
}
</script>

<template>
    <PageHeader title="Отделы" description="Отделы дилерских центров и отделы уровня компании (например, общий колл-центр).">
        <template #actions>
            <UiButton v-if="auth.can('organization.manage')" variant="primary" @click="openCreate">Добавить отдел</UiButton>
        </template>
    </PageHeader>

    <UiCard :padded="false">
        <div class="flex flex-wrap gap-3 border-b border-line p-4">
            <UiInput v-model="list.filters.q" placeholder="Название или код" class="w-64" />
            <UiSelect v-model="list.filters.company_id" :options="[{ value: '', label: 'Все компании' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" class="w-56" />
            <UiSelect v-model="list.filters.is_active" :options="[{ value: '', label: 'Все статусы' }, { value: '1', label: 'Активные' }, { value: '0', label: 'Неактивные' }]" class="w-44" />
            <UiButton v-if="list.filters.dealership_id" variant="ghost" @click="list.filters.dealership_id = ''">Сбросить фильтр по центру ×</UiButton>
        </div>
        <DataTable :columns="columns" :rows="list.rows.value" :loading="list.loading.value" :error="list.error.value" empty-title="Отделов нет" @retry="list.load">
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
                <code class="block text-[12px] text-muted">{{ row.code }}</code>
            </template>
            <template #cell-place="{ row }">
                {{ row.company?.name }}<span class="text-muted"> / {{ row.dealership?.name ?? 'уровень компании' }}</span>
            </template>
            <template #cell-kind="{ row }">{{ row.kind ? departmentKinds[row.kind] : '—' }}</template>
            <template #cell-status="{ row }"><UiBadge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Активен' : 'Неактивен' }}</UiBadge></template>
            <template #cell-actions="{ row }"><UiButton v-if="row.can.update" size="sm" variant="ghost" @click="openEdit(row)">Изменить</UiButton></template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>

    <UiModal v-model:open="modalOpen" :title="editing ? 'Отдел' : 'Новый отдел'">
        <form id="department-form" class="space-y-4" @submit.prevent="save">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <OrgUnitSelect
                v-if="!editing"
                v-model="parent"
                permission="organization.manage"
                :types="['company', 'dealership']"
                label="Входит в"
                hint="Дилерский центр либо компания (для отдела уровня компании)."
                required
                :error="errors.company_id || errors.dealership_id"
            />
            <UiInput v-model="form.name" label="Название" required :error="errors.name" />
            <UiInput v-model="form.code" label="Код" required :error="errors.code" hint="Уникален в пределах компании." />
            <UiSelect v-model="form.kind" label="Тип" placeholder="Не указан" :options="kindOptions" :error="errors.kind" />
            <UiCheckbox v-model="form.is_active" label="Активен" />
        </form>
        <template #footer>
            <UiButton @click="modalOpen = false">Отмена</UiButton>
            <UiButton type="submit" form="department-form" variant="primary" :loading="submitting">Сохранить</UiButton>
        </template>
    </UiModal>
</template>
