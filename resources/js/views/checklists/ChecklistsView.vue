<script setup lang="ts">
import { checklistsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ChecklistSummary } from '@/api/types';
import DesignCreateModal from '@/components/design/DesignCreateModal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { useAuthStore } from '@/stores/auth';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();
const items = ref<ChecklistSummary[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const createOpen = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        items.value = (await checklistsApi.list()).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить чек-листы.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function create(body: Record<string, unknown>): Promise<void> {
    const created = await checklistsApi.create(body);
    await router.push(`/checklists/${created.id}`);
}

const columns = [
    { key: 'name', label: 'Чек-лист' },
    { key: 'company', label: 'Область' },
    { key: 'version', label: 'Опубликована', class: 'w-36' },
    { key: 'state', label: 'Состояние', class: 'w-40' },
];
</script>

<template>
    <PageHeader title="Чек-листы" description="Версионируемые критерии оценки. Опубликованная версия неизменяема: правки — в новом черновике, старые оценки не пересчитываются.">
        <template #actions>
            <UiButton v-if="auth.can('checklist.design')" variant="primary" @click="createOpen = true">Создать чек-лист</UiButton>
        </template>
    </PageHeader>
    <UiCard :padded="false">
        <DataTable :columns="columns" :rows="items" :loading="loading" :error="error" empty-title="Чек-листов нет" :row-link="(r) => `/checklists/${r.id}`" @retry="load">
            <template #cell-name="{ row }">
                <RouterLink :to="`/checklists/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ row.name }}</RouterLink>
                <UiBadge v-if="row.is_demo" tone="warning" class="ml-2">демо</UiBadge>
                <span class="block text-[12px] text-muted">{{ row.code }}</span>
            </template>
            <template #cell-company="{ row }">{{ row.company?.name ?? 'Вся группа' }}</template>
            <template #cell-version="{ row }">{{ row.published_version ? `v${row.published_version}` : '—' }}</template>
            <template #cell-state="{ row }">
                <UiBadge v-if="row.is_archived">в архиве</UiBadge>
                <UiBadge v-else-if="row.has_draft" tone="info">есть черновик</UiBadge>
                <UiBadge v-else-if="row.published_version" tone="success">действует</UiBadge>
            </template>
        </DataTable>
    </UiCard>
    <DesignCreateModal v-model:open="createOpen" title="Новый чек-лист" with-code permission="checklist.design" :action="create" />
</template>
