<script setup lang="ts" generic="T extends { id: number | string }">
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiEmptyState from './UiEmptyState.vue';
import UiSpinner from './UiSpinner.vue';

export interface Column {
    key: string;
    label: string;
    class?: string;
}

/**
 * Таблица со всеми состояниями: загрузка, ошибка, пусто, данные.
 * Ячейки переопределяются слотами #cell-<key>.
 */
defineProps<{
    columns: Column[];
    rows: T[];
    loading?: boolean;
    error?: string | null;
    emptyTitle?: string;
    emptyDescription?: string;
    rowLink?: (row: T) => string | undefined;
}>();
const emit = defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="relative">
        <div v-if="error" class="p-4">
            <UiAlert tone="danger" title="Не удалось загрузить данные">
                {{ error }}
                <UiButton size="sm" class="mt-2" @click="emit('retry')">Повторить</UiButton>
            </UiAlert>
        </div>
        <template v-else>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-line text-[12px] text-muted">
                            <th v-for="c in columns" :key="c.key" scope="col" class="px-4 py-2.5 font-medium" :class="c.class">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody :class="loading && rows.length > 0 ? 'opacity-50' : undefined">
                        <tr v-for="row in rows" :key="row.id" class="border-b border-line last:border-0 hover:bg-brand-50/50">
                            <td v-for="(c, i) in columns" :key="c.key" class="px-4 py-2.5 align-top" :class="c.class">
                                <slot :name="`cell-${c.key}`" :row="row">
                                    <RouterLink v-if="i === 0 && rowLink?.(row)" :to="rowLink(row)!" class="font-medium text-brand-700 hover:underline">
                                        {{ (row as Record<string, unknown>)[c.key] }}
                                    </RouterLink>
                                    <template v-else>{{ (row as Record<string, unknown>)[c.key] ?? '—' }}</template>
                                </slot>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="loading && !rows.length" class="flex justify-center py-12 text-muted"><UiSpinner :size="22" /></div>
            <UiEmptyState v-else-if="!loading && !rows.length" :title="emptyTitle ?? 'Ничего не найдено'" :description="emptyDescription" />
        </template>
    </div>
</template>
