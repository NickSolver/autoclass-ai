<script setup lang="ts">
import { appealsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { AppealItem, PageMeta } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import { formatDateTime } from '@/utils/format';
import { onMounted, ref, watch } from 'vue';

const items = ref<AppealItem[]>([]);
const meta = ref<PageMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null });
const loading = ref(true);
const error = ref<string | null>(null);
const tab = ref<'active' | 'author' | 'assignee' | 'all'>('active');
const page = ref(1);

const tabs = [
    { key: 'active', label: 'Активные' },
    { key: 'author', label: 'Поданные мной' },
    { key: 'assignee', label: 'На моём рассмотрении' },
    { key: 'all', label: 'Все' },
] as const;

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await appealsApi.list({
            status: tab.value === 'active' ? 'active' : 'all',
            mine: tab.value === 'author' || tab.value === 'assignee' ? tab.value : null,
            page: page.value,
        });
        items.value = res.data;
        meta.value = res.meta;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить апелляции.';
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

const statusLabels: Record<AppealItem['status'], string> = { open: 'подана', in_review: 'на рассмотрении', accepted: 'принята', rejected: 'отклонена', withdrawn: 'отозвана' };
const statusTones: Record<AppealItem['status'], 'warning' | 'info' | 'success' | 'danger' | 'neutral'> = { open: 'warning', in_review: 'info', accepted: 'success', rejected: 'danger', withdrawn: 'neutral' };
const columns = [
    { key: 'call', label: 'Звонок', class: 'w-36' },
    { key: 'result', label: 'Оценка' },
    { key: 'reason', label: 'Довод' },
    { key: 'status', label: 'Состояние', class: 'w-48' },
];
</script>

<template>
    <PageHeader title="Апелляции" description="Обращения на пересмотр оценки. Решает независимый контролёр: не автор, не сотрудник звонка и не тот, кто выставил оспариваемую оценку. Решение и его причина видны в истории оценки." />
    <div class="mb-3 flex flex-wrap gap-2" role="tablist">
        <UiButton v-for="t in tabs" :key="t.key" size="sm" role="tab" :aria-selected="tab === t.key" :variant="tab === t.key ? 'primary' : 'secondary'" @click="tab = t.key">{{ t.label }}</UiButton>
    </div>
    <UiCard :padded="false">
        <DataTable :columns="columns" :rows="items" :loading="loading" :error="error" empty-title="Апелляций нет" :row-link="(r) => `/calls/${r.call_id}`" @retry="load">
            <template #cell-call="{ row }">
                <RouterLink :to="`/calls/${row.call_id}`" class="font-medium text-brand-700 hover:underline">Звонок #{{ row.call_id }}</RouterLink>
                <span class="block text-[12px] text-muted">{{ formatDateTime(row.created_at) }}</span>
            </template>
            <template #cell-result="{ row }">
                {{ row.result.checklist }} <span class="text-muted">v{{ row.result.version }}</span>
                <span class="block text-[12px] text-muted">оценка {{ row.result.score ?? 'нет итога' }}</span>
            </template>
            <template #cell-reason="{ row }">
                <span class="block max-w-md text-[13px]">{{ row.reason }}</span>
                <span class="text-[12px] text-muted">{{ row.author }}</span>
            </template>
            <template #cell-status="{ row }">
                <UiBadge :tone="statusTones[row.status]">{{ statusLabels[row.status] }}</UiBadge>
                <span class="block text-[12px] text-muted">{{ row.assignee?.name ?? 'не назначена' }}</span>
            </template>
        </DataTable>
        <UiPagination v-if="meta.last_page > 1" :meta="meta" @change="(p) => (page = p)" />
    </UiCard>
</template>
