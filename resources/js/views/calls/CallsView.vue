<script setup lang="ts">
import { callsApi, orgApi } from '@/api';
import type { CallSummary, Company } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { useAuthStore } from '@/stores/auth';
import { callStatusTone, formatDateTime, formatDuration } from '@/utils/format';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

const auth = useAuthStore();
const route = useRoute();
const list = usePaginatedList(callsApi.list, {
    q: '',
    status: (route.query.status as string | undefined) ?? '',
    direction: '',
    company_id: '',
    from: '',
    to: '',
    needs_employee: route.query.needs_employee === '1' ? 1 : ('' as 1 | ''),
});
const companies = ref<Company[]>([]);
const summary = ref<CallSummary | null>(null);

// Счётчики — по тем же фильтрам (кроме статуса): реальные числа из базы, без расчётных метрик.
async function loadSummary(): Promise<void> {
    const { status: _status, ...rest } = list.filters;
    summary.value = await callsApi.summary(rest).catch(() => null);
}
watch(() => ({ ...list.filters, status: '' }), loadSummary, { deep: true });
watch(list.rows, loadSummary);

onMounted(async () => {
    companies.value = (await orgApi.companies.list({ per_page: 100 }).catch(() => ({ data: [] }))).data;
});

const needsEmployee = computed({
    get: () => list.filters.needs_employee === 1,
    set: (v: boolean) => (list.filters.needs_employee = v ? 1 : ''),
});

const statusOptions = [
    { value: '', label: 'Все статусы' },
    { value: 'in_progress', label: 'В обработке' },
    { value: 'awaiting_transcription', label: 'Ожидает распознавания' },
    { value: 'failed', label: 'Ошибка обработки' },
    { value: 'excluded', label: 'Исключён' },
];

const columns = [
    { key: 'id', label: 'Звонок' },
    { key: 'occurred_at', label: 'Дата', class: 'w-36' },
    { key: 'org', label: 'Подразделение' },
    { key: 'employee', label: 'Сотрудник' },
    { key: 'direction', label: 'Направление', class: 'w-28' },
    { key: 'duration', label: 'Длит.', class: 'w-20' },
    { key: 'status', label: 'Статус', class: 'w-48' },
];
</script>

<template>
    <PageHeader title="Звонки" description="Записи в вашей области доступа. Распознавание и оценка появятся на следующих этапах.">
        <template #actions>
            <RouterLink v-if="auth.can('calls.upload')" to="/calls/upload"><UiButton variant="primary">Загрузить звонки</UiButton></RouterLink>
        </template>
    </PageHeader>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <StatTile label="Всего звонков" :value="summary?.total ?? null" icon="phone" />
        <StatTile label="В обработке" :value="summary?.in_progress ?? null" icon="clock" />
        <StatTile label="Ожидают распознавания" :value="summary?.awaiting_transcription ?? null" icon="check" tone="green" />
        <StatTile label="Ошибки обработки" :value="summary?.failed ?? null" icon="alert" :tone="summary?.failed ? 'red' : 'blue'" />
        <StatTile label="Без сотрудника" :value="summary?.needs_employee ?? null" icon="user" :tone="summary?.needs_employee ? 'amber' : 'blue'" />
    </div>

    <UiCard :padded="false">
        <div class="flex flex-wrap items-end gap-3 border-b border-line p-4">
            <UiInput v-model="list.filters.q" placeholder="№ звонка, файл или примечание" class="w-64" />
            <UiSelect v-model="list.filters.status" :options="statusOptions" class="w-52" />
            <UiSelect v-model="list.filters.direction" :options="[{ value: '', label: 'Все направления' }, { value: 'incoming', label: 'Входящие' }, { value: 'outgoing', label: 'Исходящие' }, { value: 'unknown', label: 'Не указано' }]" class="w-44" />
            <UiSelect v-if="companies.length > 1" v-model="list.filters.company_id" :options="[{ value: '', label: 'Все компании' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" class="w-52" />
            <UiInput v-model="list.filters.from" type="date" label="С" class="w-40" />
            <UiInput v-model="list.filters.to" type="date" label="По" class="w-40" />
            <UiCheckbox v-model="needsEmployee" label="Требует сопоставления" class="pb-2" />
        </div>
        <DataTable
            :columns="columns"
            :rows="list.rows.value"
            :loading="list.loading.value"
            :error="list.error.value"
            empty-title="Звонков нет"
            :empty-description="auth.can('calls.upload') ? 'Загрузите записи на странице «Загрузка».' : undefined"
            @retry="list.load"
        >
            <template #cell-id="{ row }">
                <RouterLink :to="`/calls/${row.id}`" class="font-medium text-brand-700 hover:underline">#{{ row.id }}</RouterLink>
                <span class="block max-w-56 truncate text-[12px] text-muted" :title="row.original?.name ?? ''">{{ row.original?.name }}</span>
            </template>
            <template #cell-occurred_at="{ row }">
                {{ formatDateTime(row.occurred_at) }}
                <span v-if="!row.started_at" class="block text-[11px] text-muted">дата загрузки</span>
            </template>
            <template #cell-org="{ row }">
                {{ row.org.company }}
                <span class="block text-[12px] text-muted">{{ [row.org.dealership, row.org.department].filter(Boolean).join(' / ') }}</span>
            </template>
            <template #cell-employee="{ row }">
                <span v-if="row.org.employee">{{ row.org.employee }}</span>
                <UiBadge v-else tone="warning">не установлен</UiBadge>
            </template>
            <template #cell-direction="{ row }">{{ row.direction.label }}</template>
            <template #cell-duration="{ row }">{{ formatDuration(row.duration_ms) }}</template>
            <template #cell-status="{ row }">
                <UiBadge :tone="callStatusTone(row.status.code)">{{ row.status.label }}</UiBadge>
                <UiBadge v-if="row.duplicate_of" tone="warning" class="mt-1">дубль</UiBadge>
            </template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>
</template>
