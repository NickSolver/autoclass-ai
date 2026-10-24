<script setup lang="ts">
import { workflowsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { WorkflowSummary } from '@/api/types';
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
const items = ref<WorkflowSummary[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const createOpen = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        items.value = (await workflowsApi.list()).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить процессы.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function create(body: Record<string, unknown>): Promise<void> {
    const created = await workflowsApi.create(body);
    await router.push(`/workflows/${created.id}`);
}

const columns = [
    { key: 'name', label: 'Процесс' },
    { key: 'company', label: 'Область' },
    { key: 'priority', label: 'Приоритет', class: 'w-28' },
    { key: 'version', label: 'Опубликована', class: 'w-36' },
    { key: 'state', label: 'Состояние', class: 'w-40' },
];
</script>

<template>
    <PageHeader
        title="Бизнес-процессы"
        description="Маршрут звонка после распознавания: условия, основной и дополнительные чек-листы, анализ, проверки. Для звонка выбирается процесс его компании, иначе общий; при равенстве — с меньшим приоритетом. Без опубликованного процесса действует правило по умолчанию."
    >
        <template #actions>
            <UiButton v-if="auth.can('workflow.design')" variant="primary" @click="createOpen = true">Создать процесс</UiButton>
        </template>
    </PageHeader>
    <UiCard :padded="false">
        <DataTable :columns="columns" :rows="items" :loading="loading" :error="error" empty-title="Процессов нет" empty-description="Звонки оцениваются по правилу по умолчанию." :row-link="(r) => `/workflows/${r.id}`" @retry="load">
            <template #cell-name="{ row }">
                <RouterLink :to="`/workflows/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ row.name }}</RouterLink>
                <span v-if="row.description" class="block max-w-xl text-[12px] text-muted">{{ row.description }}</span>
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
    <DesignCreateModal v-model:open="createOpen" title="Новый бизнес-процесс" permission="workflow.design" :action="create" />
</template>
