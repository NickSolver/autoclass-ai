<script setup lang="ts">
import { reviewsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { PageMeta, ReviewItem } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import { useToasts } from '@/composables/useToasts';
import { formatDateTime } from '@/utils/format';
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const { push } = useToasts();
const items = ref<ReviewItem[]>([]);
const counts = ref<{ pending: number; in_progress: number; mine: number } | null>(null);
const meta = ref<PageMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null });
const loading = ref(true);
const error = ref<string | null>(null);
const tab = ref<'open' | 'mine' | 'unassigned' | 'resolved'>('open');
const page = ref(1);

const tabs = [
    { key: 'open', label: 'Открытые' },
    { key: 'mine', label: 'Мои' },
    { key: 'unassigned', label: 'Без исполнителя' },
    { key: 'resolved', label: 'Решённые' },
] as const;

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await reviewsApi.queue({
            status: tab.value === 'resolved' ? 'resolved' : 'open',
            assignee: tab.value === 'mine' ? 'me' : tab.value === 'unassigned' ? 'unassigned' : null,
            page: page.value,
        });
        items.value = res.data;
        meta.value = res.meta;
        counts.value = res.counts;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить очередь.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(tab, () => {
    page.value = 1;
    load();
});
watch(page, load);

async function take(r: ReviewItem): Promise<void> {
    try {
        await reviewsApi.take(r.id, r.lock_version);
        push('Проверка взята в работу.');
        await router.push(`/calls/${r.call_id}`);
    } catch (e) {
        push(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Не удалось взять проверку.', 'danger');
        await load();
    }
}

const statusLabels: Record<string, string> = { pending: 'ожидает', in_progress: 'в работе', resolved: 'решена', cancelled: 'отменена' };
const columns = [
    { key: 'call', label: 'Звонок', class: 'w-40' },
    { key: 'org', label: 'Подразделение' },
    { key: 'reason', label: 'Причина' },
    { key: 'status', label: 'Состояние', class: 'w-44' },
    { key: 'actions', label: '', class: 'w-36' },
];
</script>

<template>
    <PageHeader title="Ручные проверки" description="Звонки, где оценка требует решения человека, задачи бизнес-процессов и отправленные вручную. Исправление создаёт новую ревизию — результат ИИ сохраняется." />
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <StatTile label="Ожидают" :value="counts?.pending ?? null" icon="inbox" :tone="counts?.pending ? 'amber' : 'blue'" />
        <StatTile label="В работе" :value="counts?.in_progress ?? null" icon="clock" tone="blue" />
        <StatTile label="Назначены мне" :value="counts?.mine ?? null" icon="user" tone="blue" />
    </div>
    <div class="mb-3 flex flex-wrap gap-2" role="tablist">
        <UiButton v-for="t in tabs" :key="t.key" size="sm" role="tab" :aria-selected="tab === t.key" :variant="tab === t.key ? 'primary' : 'secondary'" @click="tab = t.key">{{ t.label }}</UiButton>
    </div>
    <UiCard :padded="false">
        <DataTable :columns="columns" :rows="items" :loading="loading" :error="error" empty-title="Проверок нет" :row-link="(r) => `/calls/${r.call_id}`" @retry="load">
            <template #cell-call="{ row }">
                <RouterLink :to="`/calls/${row.call_id}`" class="font-medium text-brand-700 hover:underline">Звонок #{{ row.call_id }}</RouterLink>
                <span v-if="row.call" class="block text-[12px] text-muted">{{ formatDateTime(row.call.occurred_at) }}</span>
            </template>
            <template #cell-org="{ row }">
                {{ [row.call?.org.dealership, row.call?.org.department].filter(Boolean).join(' / ') || row.call?.org.company }}
                <span class="block text-[12px] text-muted">{{ row.call?.org.employee ?? 'сотрудник не установлен' }}</span>
            </template>
            <template #cell-reason="{ row }">
                <UiBadge class="mb-1">{{ row.source_label }}</UiBadge>
                <span class="block max-w-md text-[13px]">{{ row.reason }}</span>
            </template>
            <template #cell-status="{ row }">
                <UiBadge :tone="row.status === 'in_progress' ? 'info' : row.status === 'pending' ? 'warning' : 'success'">{{ statusLabels[row.status] }}</UiBadge>
                <span class="block text-[12px] text-muted">{{ row.assignee?.name ?? 'без исполнителя' }}</span>
            </template>
            <template #cell-actions="{ row }">
                <UiButton v-if="row.can.take" size="sm" variant="primary" @click.stop.prevent="take(row)">Взять</UiButton>
            </template>
        </DataTable>
        <UiPagination v-if="meta.last_page > 1" :meta="meta" @change="(p) => (page = p)" />
    </UiCard>
</template>
