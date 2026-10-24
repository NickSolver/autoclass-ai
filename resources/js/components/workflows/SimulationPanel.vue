<script setup lang="ts">
import type { SimulationResult, WorkflowCatalog } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { computed, reactive, ref } from 'vue';

/**
 * Симуляция без побочных эффектов: на данных звонка или на заданных значениях полей.
 * Анализ не выполняется — поля его результата задаются здесь.
 */
const props = defineProps<{
    catalog: WorkflowCatalog;
    result: SimulationResult | null;
    running: boolean;
    error: string | null;
    nodeLabel: (id: string) => string;
}>();
const emit = defineEmits<{ run: [body: { input: Record<string, unknown>; call_id: number | null }] }>();

const mode = ref<'fields' | 'call'>('fields');
const callId = ref<string | number | null>('');
const input = reactive<Record<string, string | number | null>>({ direction: 'incoming', business_status: 'full_assessment' });

const fields = computed(() =>
    Object.entries(props.catalog.fields).filter(([, f]) => mode.value === 'fields' || f.phase === 'after_analysis'),
);

function options(code: string): { value: string | number; label: string }[] {
    const f = props.catalog.fields[code];
    if (!f) return [];
    if (f.type === 'bool') return [{ value: 'true', label: 'да' }, { value: 'false', label: 'нет' }];
    if (f.type === 'enum') return Object.entries(f.values ?? {}).map(([value, label]) => ({ value, label }));
    if (f.type === 'org' && f.org) return props.catalog.org[f.org].map((o) => ({ value: o.id, label: o.name }));
    return [];
}

function run(): void {
    const values = Object.fromEntries(Object.entries(input).filter(([, v]) => v !== null && v !== ''));
    emit('run', { input: values, call_id: mode.value === 'call' && callId.value ? Number(callId.value) : null });
}

const checklistName = (id: number | null) => props.catalog.checklists.find((c) => c.id === id)?.name ?? (id ? `#${id}` : '—');
</script>

<template>
    <div class="space-y-4" data-testid="simulation">
        <div class="flex gap-2">
            <UiButton size="sm" :variant="mode === 'fields' ? 'primary' : 'secondary'" @click="mode = 'fields'">Значения полей</UiButton>
            <UiButton size="sm" :variant="mode === 'call' ? 'primary' : 'secondary'" @click="mode = 'call'">Данные звонка</UiButton>
        </div>
        <form class="space-y-2" @submit.prevent="run">
            <UiInput v-if="mode === 'call'" v-model="callId" label="Номер звонка" inputmode="numeric" required />
            <p v-if="mode === 'call'" class="text-[12px] text-muted">Поля звонка берутся из него; ниже — результат анализа (анализ не запускается).</p>
            <template v-for="[code, f] in fields" :key="code">
                <UiSelect v-if="options(code).length" v-model="input[code]" :label="f.label" placeholder="не задано" :options="options(code)" />
                <UiInput v-else v-model="input[code]" type="number" :label="f.label" />
            </template>
            <UiButton type="submit" variant="primary" :loading="running">Запустить симуляцию</UiButton>
        </form>

        <UiAlert v-if="error" tone="danger">{{ error }}</UiAlert>
        <div v-if="result" class="space-y-3 border-t border-line pt-3" data-testid="simulation-result">
            <UiAlert v-if="result.errors.length" tone="warning">Процесс не прошёл проверку ({{ result.errors.length }}). Опубликовать его нельзя.</UiAlert>
            <UiAlert v-if="result.error" tone="danger">{{ result.error }}</UiAlert>
            <div>
                <p class="mb-1 text-[13px] font-medium">Путь</p>
                <ol class="list-decimal space-y-0.5 pl-5 text-[13px]">
                    <li v-for="(step, i) in result.trace" :key="i">
                        {{ nodeLabel(step.node_id) }}
                        <span v-if="step.branch === 'true'" class="text-emerald-700">→ да</span>
                        <span v-else-if="step.branch === 'false'" class="text-amber-700">→ нет</span>
                    </li>
                </ol>
            </div>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
                <dt class="text-muted">Основной</dt>
                <dd data-testid="sim-primary">{{ checklistName(result.plan.primary_checklist_id) }}</dd>
                <dt class="text-muted">Дополнительные</dt>
                <dd data-testid="sim-additional">{{ result.plan.additional_checklist_ids.map(checklistName).join(', ') || '—' }}</dd>
                <dt class="text-muted">Анализ</dt>
                <dd>{{ result.plan.analysis_requested ? 'запускается' : 'не запускается' }}</dd>
                <dt class="text-muted">Проверка</dt>
                <dd data-testid="sim-review">
                    <UiBadge v-if="result.plan.review_reasons.length" tone="warning">{{ result.plan.review_reasons.join('; ') }}</UiBadge>
                    <span v-else>не нужна</span>
                </dd>
                <dt class="text-muted">Метки</dt>
                <dd>{{ result.plan.labels.join(', ') || '—' }}</dd>
                <dt class="text-muted">Приоритет</dt>
                <dd>{{ catalog.priorities[result.plan.priority ?? 'normal'] ?? result.plan.priority }}</dd>
            </dl>
        </div>
    </div>
</template>
