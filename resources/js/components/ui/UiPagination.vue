<script setup lang="ts">
import type { PageMeta } from '@/api/types';

defineProps<{ meta: PageMeta }>();
const emit = defineEmits<{ change: [page: number] }>();
</script>

<template>
    <nav v-if="meta.total > 0" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-[13px] text-muted" aria-label="Страницы">
        <span>{{ meta.from }}–{{ meta.to }} из {{ meta.total }}</span>
        <div v-if="meta.last_page > 1" class="flex items-center gap-1">
            <button type="button" class="rounded-md border border-line bg-white px-2.5 py-1 disabled:opacity-50" :disabled="meta.current_page <= 1" @click="emit('change', meta.current_page - 1)">
                Назад
            </button>
            <span class="px-2">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <button type="button" class="rounded-md border border-line bg-white px-2.5 py-1 disabled:opacity-50" :disabled="meta.current_page >= meta.last_page" @click="emit('change', meta.current_page + 1)">
                Вперёд
            </button>
        </div>
    </nav>
</template>
