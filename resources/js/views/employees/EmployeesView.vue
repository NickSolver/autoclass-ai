<script setup lang="ts">
import { employeesApi, orgApi } from '@/api';
import type { Company, Employee } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { useAuthStore } from '@/stores/auth';
import EmployeeFormModal from './EmployeeFormModal.vue';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();
const list = usePaginatedList(employeesApi.list, { q: '', company_id: '', is_active: '1' as '' | '1' | '0' });
const companies = ref<Company[]>([]);
const createOpen = ref(false);
onMounted(async () => {
    companies.value = (await orgApi.companies.list({ per_page: 100 }).catch(() => ({ data: [] }))).data;
});

const columns = [
    { key: 'full_name', label: 'Сотрудник' },
    { key: 'position', label: 'Текущее назначение' },
    { key: 'phone_extension', label: 'Доб.', class: 'w-20' },
    { key: 'user', label: 'Учётная запись', class: 'w-36' },
    { key: 'status', label: 'Статус', class: 'w-28' },
];

function current(e: Employee) {
    const active = e.assignments?.filter((a) => a.is_current) ?? [];
    return active.find((a) => a.is_primary) ?? active[0];
}
</script>

<template>
    <PageHeader title="Сотрудники" description="Сотрудник — это не учётная запись: связь с пользователем портала необязательна.">
        <template #actions>
            <UiButton v-if="auth.can('employees.manage')" variant="primary" @click="createOpen = true">Добавить сотрудника</UiButton>
        </template>
    </PageHeader>

    <UiCard :padded="false">
        <div class="flex flex-wrap gap-3 border-b border-line p-4">
            <UiInput v-model="list.filters.q" placeholder="ФИО, табельный или добавочный номер" class="w-72" />
            <UiSelect v-model="list.filters.company_id" :options="[{ value: '', label: 'Все компании' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" class="w-56" />
            <UiSelect v-model="list.filters.is_active" :options="[{ value: '', label: 'Все' }, { value: '1', label: 'Работают' }, { value: '0', label: 'Не работают' }]" class="w-40" />
        </div>
        <DataTable :columns="columns" :rows="list.rows.value" :loading="list.loading.value" :error="list.error.value" :row-link="(r) => `/employees/${r.id}`" empty-title="Сотрудники не найдены" @retry="list.load">
            <template #cell-full_name="{ row }">
                <RouterLink :to="`/employees/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ row.full_name }}</RouterLink>
                <span class="block text-[12px] text-muted">{{ row.company?.name }}<template v-if="row.personnel_number"> · таб. {{ row.personnel_number }}</template></span>
            </template>
            <template #cell-position="{ row }">
                <template v-if="current(row)">
                    {{ current(row)?.position ?? '—' }}
                    <span class="block text-[12px] text-muted">{{ [current(row)?.dealership?.name, current(row)?.department?.name].filter(Boolean).join(' / ') || current(row)?.company.name }}</span>
                </template>
                <span v-else class="text-muted">нет текущего назначения</span>
            </template>
            <template #cell-user="{ row }">
                <RouterLink v-if="row.user" :to="`/users/${row.user.id}`" class="text-brand-700 hover:underline">{{ row.user.login }}</RouterLink>
                <span v-else class="text-muted">—</span>
            </template>
            <template #cell-status="{ row }"><UiBadge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Работает' : 'Не работает' }}</UiBadge></template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>

    <EmployeeFormModal v-model:open="createOpen" @saved="(e) => router.push(`/employees/${e.id}`)" />
</template>
