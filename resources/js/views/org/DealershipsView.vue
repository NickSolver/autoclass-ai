<script setup lang="ts">
import { orgApi } from '@/api';
import type { Company, Dealership } from '@/api/types';
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
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

const auth = useAuthStore();
const route = useRoute();
const { push } = useToasts();
const list = usePaginatedList(orgApi.dealerships.list, {
    q: '',
    company_id: (route.query.company_id as string | undefined) ?? '',
    is_active: '' as '' | '1' | '0',
});
const companies = ref<Company[]>([]);
onMounted(async () => {
    companies.value = (await orgApi.companies.list({ per_page: 100 }).catch(() => ({ data: [] }))).data;
});

const columns = [
    { key: 'name', label: 'Дилерский центр' },
    { key: 'company', label: 'Компания' },
    { key: 'city', label: 'Город', class: 'w-40' },
    { key: 'departments_count', label: 'Отделов', class: 'w-24' },
    { key: 'status', label: 'Статус', class: 'w-28' },
    { key: 'actions', label: '', class: 'w-24 text-right' },
];

const modalOpen = ref(false);
const editing = ref<Dealership | null>(null);
const parent = ref<OrgUnitValue | null>(null);
const form = reactive({ code: '', name: '', city: '', address: '', is_active: true });
const { submitting, errors, formError, submit, reset } = useForm();

function openCreate(): void {
    editing.value = null;
    parent.value = null;
    Object.assign(form, { code: '', name: '', city: '', address: '', is_active: true });
    reset();
    modalOpen.value = true;
}

function openEdit(d: Dealership): void {
    editing.value = d;
    Object.assign(form, { code: d.code, name: d.name, city: d.city ?? '', address: d.address ?? '', is_active: d.is_active });
    reset();
    modalOpen.value = true;
}

async function save(): Promise<void> {
    const body = { code: form.code, name: form.name, city: form.city || null, address: form.address || null, is_active: form.is_active };
    const result = await submit(() =>
        editing.value ? orgApi.dealerships.update(editing.value.id, body) : orgApi.dealerships.create({ ...body, company_id: parent.value?.id }),
    );
    if (result) {
        modalOpen.value = false;
        push('Дилерский центр сохранён.');
        await list.load();
    }
}
</script>

<template>
    <PageHeader title="Дилерские центры">
        <template #actions>
            <UiButton v-if="auth.can('organization.manage')" variant="primary" @click="openCreate">Добавить центр</UiButton>
        </template>
    </PageHeader>

    <UiCard :padded="false">
        <div class="flex flex-wrap gap-3 border-b border-line p-4">
            <UiInput v-model="list.filters.q" placeholder="Название, код или город" class="w-64" />
            <UiSelect v-model="list.filters.company_id" :options="[{ value: '', label: 'Все компании' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" class="w-56" />
            <UiSelect v-model="list.filters.is_active" :options="[{ value: '', label: 'Все статусы' }, { value: '1', label: 'Активные' }, { value: '0', label: 'Неактивные' }]" class="w-44" />
        </div>
        <DataTable :columns="columns" :rows="list.rows.value" :loading="list.loading.value" :error="list.error.value" empty-title="Дилерских центров нет" @retry="list.load">
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
                <code class="block text-[12px] text-muted">{{ row.code }}</code>
            </template>
            <template #cell-company="{ row }">{{ row.company?.name }}</template>
            <template #cell-departments_count="{ row }">
                <RouterLink :to="{ path: '/org/departments', query: { dealership_id: row.id } }" class="text-brand-700 hover:underline">{{ row.departments_count }}</RouterLink>
            </template>
            <template #cell-status="{ row }"><UiBadge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Активен' : 'Неактивен' }}</UiBadge></template>
            <template #cell-actions="{ row }"><UiButton v-if="row.can.update" size="sm" variant="ghost" @click="openEdit(row)">Изменить</UiButton></template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>

    <UiModal v-model:open="modalOpen" :title="editing ? 'Дилерский центр' : 'Новый дилерский центр'">
        <form id="dealership-form" class="space-y-4" @submit.prevent="save">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <OrgUnitSelect v-if="!editing" v-model="parent" permission="organization.manage" :types="['company']" label="Компания" required :error="errors.company_id" />
            <p v-else class="text-[13px] text-muted">Компания: <span class="text-ink">{{ editing.company?.name }}</span> (не меняется после создания)</p>
            <UiInput v-model="form.name" label="Название" required :error="errors.name" />
            <UiInput v-model="form.code" label="Код" required :error="errors.code" hint="Уникален в пределах компании." />
            <div class="grid gap-4 sm:grid-cols-2">
                <UiInput v-model="form.city" label="Город" :error="errors.city" />
                <UiInput v-model="form.address" label="Адрес" :error="errors.address" />
            </div>
            <UiCheckbox v-model="form.is_active" label="Активен" />
        </form>
        <template #footer>
            <UiButton @click="modalOpen = false">Отмена</UiButton>
            <UiButton type="submit" form="dealership-form" variant="primary" :loading="submitting">Сохранить</UiButton>
        </template>
    </UiModal>
</template>
