<script setup lang="ts">
import { orgApi } from '@/api';
import { ApiError } from '@/api/http';
import type { OrgNode } from '@/api/types';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import OrgTreeNode from './OrgTreeNode.vue';
import { onMounted, ref } from 'vue';

const tree = ref<OrgNode[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        tree.value = (await orgApi.tree()).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить оргструктуру.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <PageHeader title="Оргструктура" description="Группа компаний → компании → дилерские центры → отделы. Показаны подразделения в вашей области доступа." />
    <UiCard>
        <div v-if="loading" class="flex justify-center py-10 text-muted"><UiSpinner :size="22" /></div>
        <UiAlert v-else-if="error" tone="danger">{{ error }}</UiAlert>
        <UiEmptyState v-else-if="!tree.length" title="Подразделений нет" description="В вашей области доступа нет подразделений." />
        <template v-else>
            <ul class="space-y-1">
                <OrgTreeNode v-for="node in tree" :key="`${node.type}:${node.id}`" :node="node" :depth="0" />
            </ul>
            <p class="mt-4 flex items-center gap-2 text-[12px] text-muted">
                <UiBadge>контекст</UiBadge> — подразделение-родитель, на которое у вас нет прав; показано для ориентации.
            </p>
        </template>
    </UiCard>
</template>
