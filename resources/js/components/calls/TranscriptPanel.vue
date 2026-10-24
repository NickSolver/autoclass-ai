<script setup lang="ts">
import { reviewsApi } from '@/api';
import type { TranscriptResponse } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { formatDateTime, formatTimecode } from '@/utils/format';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ transcript: TranscriptResponse; highlight?: number | null; callId?: number; canEdit?: boolean }>();
const emit = defineEmits<{ seek: [ms: number]; changed: [] }>();
const roleLabels = { agent: 'Сотрудник', client: 'Клиент', unknown: 'Говорящий' } as const;
const { push } = useToasts();
const form = useForm();

// Исправление: правки копятся локально и сохраняются новой версией с причиной.
const editing = ref(false);
const drafts = reactive<Record<number, { text: string; speaker_role: string }>>({});
const reason = ref('');
const isLatest = computed(() => props.transcript.current?.version === props.transcript.versions[0]?.version);

function startEdit(): void {
    for (const s of props.transcript.current?.segments ?? []) drafts[s.id] = { text: s.text, speaker_role: s.speaker_role };
    reason.value = '';
    form.reset();
    editing.value = true;
}

const edits = computed(() =>
    (props.transcript.current?.segments ?? [])
        .filter((s) => drafts[s.id] && (drafts[s.id]!.text !== s.text || drafts[s.id]!.speaker_role !== s.speaker_role))
        .map((s) => ({ id: s.id, text: drafts[s.id]!.text, speaker_role: drafts[s.id]!.speaker_role })),
);

async function save(): Promise<void> {
    const current = props.transcript.current;
    if (!current || !props.callId) return;
    const saved = await form.submit(() => reviewsApi.correctTranscript(props.callId!, current.version, reason.value, edits.value));
    if (saved) {
        editing.value = false;
        push(`Сохранена версия ${saved.version}. Оценка не пересчитывается автоматически — при необходимости обработайте звонок повторно.`, 'info');
        emit('changed');
    }
}
</script>

<template>
    <UiCard title="Расшифровка" :padded="false" data-testid="transcript-panel">
        <template #actions>
            <UiBadge v-if="transcript.current?.is_synthetic" tone="warning">ТЕСТОВЫЕ ДАННЫЕ</UiBadge>
            <UiBadge v-if="transcript.current?.source === 'human'" tone="info">исправлена человеком</UiBadge>
            <span v-if="transcript.current" class="text-[12px] text-muted">версия {{ transcript.current.version }}</span>
            <UiButton v-if="canEdit && isLatest && transcript.current && !editing" size="sm" @click="startEdit">Исправить</UiButton>
        </template>
        <UiAlert v-if="transcript.current?.is_synthetic" tone="warning" class="m-4">
            Сформировано тестовым провайдером: это синтетический диалог, а не распознанная речь этой записи.
            Роли говорящих — гипотеза, не установление личности.
        </UiAlert>
        <p v-if="transcript.current?.source === 'human'" class="px-5 pt-3 text-[12px] text-muted">
            Исправил(а) {{ transcript.current.author ?? 'пользователь' }}, {{ formatDateTime(transcript.current.created_at) }}: {{ transcript.current.reason }}
        </p>
        <p v-if="!transcript.current" class="px-5 py-4 text-[13px] text-muted">Расшифровки пока нет.</p>

        <form v-else-if="editing" class="space-y-3 p-4" data-testid="transcript-editor" @submit.prevent="save">
            <UiAlert v-if="form.formError.value || Object.keys(form.errors.value).length" tone="danger">{{ form.formError.value ?? Object.values(form.errors.value)[0] }}</UiAlert>
            <ol class="max-h-[32rem] space-y-2 overflow-y-auto">
                <li v-for="s in transcript.current.segments" :key="s.id" class="flex gap-2 text-[13px]">
                    <span class="w-12 shrink-0 pt-2 font-mono text-[12px] text-muted">{{ formatTimecode(s.start_ms) }}</span>
                    <select v-if="drafts[s.id]" v-model="drafts[s.id]!.speaker_role" class="h-9 w-28 shrink-0 rounded-md border border-line bg-white px-1.5" :aria-label="`Роль, ${formatTimecode(s.start_ms)}`">
                        <option v-for="(label, value) in roleLabels" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <textarea v-if="drafts[s.id]" v-model="drafts[s.id]!.text" rows="2" maxlength="5000" class="flex-1 rounded-md border border-line px-2 py-1.5" :aria-label="`Текст, ${formatTimecode(s.start_ms)}`"></textarea>
                </li>
            </ol>
            <UiInput v-model="reason" label="Причина исправления" required :maxlength="500" />
            <div class="flex items-center gap-2">
                <UiButton type="submit" variant="primary" :loading="form.submitting.value" :disabled="!edits.length || !reason.trim()">Сохранить новую версию ({{ edits.length }})</UiButton>
                <UiButton @click="editing = false">Отмена</UiButton>
            </div>
        </form>

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
