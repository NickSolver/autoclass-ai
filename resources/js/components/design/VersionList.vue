<script setup lang="ts">
import type { VersionStatus } from '@/api/types';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { formatDateTime } from '@/utils/format';

/** Версии чек-листа или процесса: черновик, опубликованная, архивные. */
defineProps<{ versions: { id: number; version: number; status: VersionStatus; published_at: string | null }[]; selectedId: number | null }>();
const emit = defineEmits<{ select: [id: number] }>();

const labels: Record<VersionStatus, string> = { draft: 'черновик', published: 'действует', archived: 'архив' };
const tones: Record<VersionStatus, 'info' | 'success' | 'neutral'> = { draft: 'info', published: 'success', archived: 'neutral' };
</script>

<template>
    <UiCard title="Версии" :padded="false">
        <ul class="divide-y divide-line">
            <li v-for="v in versions" :key="v.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left hover:bg-gray-50"
                    :class="v.id === selectedId ? 'bg-brand-50' : ''"
                    :aria-current="v.id === selectedId ? 'true' : undefined"
                    @click="emit('select', v.id)"
                >
                    <span class="font-medium">v{{ v.version }}</span>
                    <UiBadge :tone="tones[v.status]">{{ labels[v.status] }}</UiBadge>
                    <span v-if="v.published_at" class="ml-auto text-[11px] text-muted">{{ formatDateTime(v.published_at) }}</span>
                </button>
            </li>
        </ul>
    </UiCard>
</template>
