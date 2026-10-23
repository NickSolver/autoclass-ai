<script setup lang="ts">
import type { TranscriptResponse } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { formatTimecode } from '@/utils/format';

defineProps<{ transcript: TranscriptResponse; highlight?: number | null }>();
const emit = defineEmits<{ seek: [ms: number] }>();
const roleLabels = { agent: 'Сотрудник', client: 'Клиент', unknown: 'Говорящий' } as const;
</script>

<template>
    <UiCard title="Расшифровка" :padded="false">
        <template #actions>
            <UiBadge v-if="transcript.current?.is_synthetic" tone="warning">ТЕСТОВЫЕ ДАННЫЕ</UiBadge>
            <span v-if="transcript.current" class="text-[12px] text-muted">версия {{ transcript.current.version }}</span>
        </template>
        <UiAlert v-if="transcript.current?.is_synthetic" tone="warning" class="m-4">
            Сформировано тестовым провайдером: это синтетический диалог, а не распознанная речь этой записи.
            Роли говорящих — гипотеза, не установление личности.
        </UiAlert>
        <p v-if="!transcript.current" class="px-5 py-4 text-[13px] text-muted">Расшифровки пока нет.</p>
        <ol v-else class="max-h-[32rem] divide-y divide-line overflow-y-auto">
            <li
                v-for="s in transcript.current.segments"
                :id="`segment-${s.id}`"
                :key="s.id"
                class="flex gap-3 px-5 py-2.5 text-[13px]"
                :class="highlight === s.id && 'bg-amber-50'"
            >
                <button type="button" class="w-12 shrink-0 text-left font-mono text-[12px] text-brand-700 hover:underline" :title="`Перейти к ${formatTimecode(s.start_ms)}`" @click="emit('seek', s.start_ms)">
                    {{ formatTimecode(s.start_ms) }}
                </button>
                <span class="w-24 shrink-0 font-medium" :class="s.speaker_role === 'agent' ? 'text-brand-700' : 'text-ink'">{{ roleLabels[s.speaker_role] }}</span>
                <span class="flex-1">{{ s.text }}</span>
            </li>
        </ol>
    </UiCard>
</template>
