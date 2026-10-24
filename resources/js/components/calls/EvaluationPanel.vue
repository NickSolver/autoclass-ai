<script setup lang="ts">
import type { AnalysisResponse, EvaluationResultItem, EvidenceItem, WorkflowTrace } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { criterionStatusLabels, formatDateTime, formatTimecode } from '@/utils/format';

defineProps<{ analysis: AnalysisResponse }>();
const emit = defineEmits<{ seek: [ms: number, segmentId: number | null] }>();

const statusTone = (s: string) => (s === 'pass' ? 'success' : s === 'fail' ? 'danger' : s === 'insufficient_data' ? 'warning' : 'neutral');

function scoreColor(r: EvaluationResultItem): string {
    const v = r.score ?? r.provisional_score ?? 0;
    return v >= 80 ? 'text-emerald-600' : v >= 50 ? 'text-amber-600' : 'text-red-600';
}

function workflowCaption(w: WorkflowTrace): string {
    const name = w.fallback ? 'Правило по умолчанию' : `${w.name ?? 'Процесс'} · версия ${w.version}`;
    return `${name} · запуск ${formatDateTime(w.started_at)}`;
}

const decisionLabels: Record<string, string> = { confirmed: 'Подтверждено', corrected: 'Исправлено', rejected: 'Апелляция отклонена' };

function originLabel(r: EvaluationResultItem): string {
    if (r.origin === 'human') return 'исправлено человеком';
    return r.approval_state === 'human_confirmed' ? 'подтверждено человеком' : 'оценка ИИ';
}

function originTone(r: EvaluationResultItem): 'info' | 'success' | 'neutral' {
    return r.origin === 'human' ? 'info' : r.approval_state === 'human_confirmed' ? 'success' : 'neutral';
}

function evidenceLabel(e: EvidenceItem): string {
    if (e.type === 'quote') return `«${e.quote}»`;
    if (e.type === 'time_range') return `отрезок ${formatTimecode(e.start_ms)}–${formatTimecode(e.end_ms)}`;
    return e.type;
}
</script>

<template>
    <UiCard v-if="!analysis.current" title="Оценка">
        <p class="text-[13px] text-muted">Оценки пока нет.</p>
    </UiCard>
    <template v-else>
        <UiAlert v-if="analysis.current.status === 'failed'" tone="danger" title="Последний анализ не выполнен">{{ analysis.current.error_message }}</UiAlert>
        <UiAlert v-if="analysis.current.synthetic" tone="warning">
            Оценка построена по тестовой (синтетической) расшифровке — не используйте её для выводов о сотруднике.
        </UiAlert>

        <UiCard v-for="r in analysis.current.results" :key="r.id" :padded="false">
            <header class="flex flex-wrap items-start gap-4 border-b border-line px-5 py-4">
                <div class="grid size-20 shrink-0 place-items-center rounded-full border-4" :class="r.score !== null ? 'border-current ' + scoreColor(r) : 'border-dashed border-gray-300 text-muted'">
                    <span class="text-center leading-tight">
                        <span class="block text-[22px] font-bold">{{ r.score ?? '—' }}</span>
                        <span class="block text-[10px] text-muted">{{ r.score !== null ? 'из 100' : 'нет итога' }}</span>
                    </span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-[15px] font-bold">
                        {{ r.checklist.name }}
                        <UiBadge :tone="r.role === 'primary' ? 'info' : 'neutral'">{{ r.role === 'primary' ? 'основной' : 'дополнительный' }}</UiBadge>
                        <UiBadge v-if="r.checklist.is_demo" tone="warning">DEMO — не для кадровых выводов</UiBadge>
                    </p>
                    <p class="mt-1 flex flex-wrap gap-2">
                        <UiBadge :tone="r.business_status === 'full_assessment' ? 'success' : 'warning'">
                            {{ r.business_status === 'full_assessment' ? 'Полная оценка' : 'Требуется вмешательство человека' }}
                        </UiBadge>
                        <UiBadge v-if="r.critical_violation" tone="danger">критическое нарушение</UiBadge>
                        <UiBadge :tone="originTone(r)" data-testid="result-origin">{{ originLabel(r) }}</UiBadge>
                    </p>
                    <p v-if="r.score === null && r.provisional_score !== null" class="mt-1.5 text-[12px] text-muted">
                        Предварительно по разрешённым критериям: {{ r.provisional_score }} — для проверяющего, не для рейтингов.
                    </p>
                    <p v-if="r.origin === 'human'" class="mt-1.5 text-[12px] text-muted">
                        Ревизия {{ r.revision }}: {{ r.author ?? 'пользователь' }}, {{ formatDateTime(r.created_at) }} — {{ r.change_reason }}
                    </p>
                    <p v-if="r.summary" class="mt-1.5 text-[13px]">{{ r.summary }}</p>
                </div>
            </header>

            <ul v-if="r.issues.length" class="space-y-1 border-b border-line bg-amber-50/60 px-5 py-3 text-[12px] text-amber-900">
                <li v-for="(issue, i) in r.issues" :key="i">⚠ {{ issue }}</li>
            </ul>

            <table class="w-full text-left text-[13px]">
                <thead>
                    <tr class="border-b border-line text-[12px] text-muted">
                        <th class="px-5 py-2 font-medium">Критерий</th>
                        <th class="w-40 px-3 py-2 font-medium">Статус</th>
                        <th class="px-3 py-2 font-medium">Обоснование и доказательства</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in r.criteria" :key="c.id" class="border-b border-line align-top last:border-0" :class="!c.evidence_valid && 'bg-red-50/50'">
                        <td class="px-5 py-2.5">
                            {{ c.title }}
                            <span class="block text-[11px] text-muted">вес {{ c.weight }}<template v-if="c.required"> · обязательный</template><template v-if="c.critical"> · критический</template></span>
                        </td>
                        <td class="px-3 py-2.5">
                            <UiBadge :tone="statusTone(c.status)">{{ criterionStatusLabels[c.status] }}</UiBadge>
                            <span v-if="c.origin === 'human' && c.previous_status" class="mt-1 block text-[11px] text-muted">исправлено человеком, было: {{ criterionStatusLabels[c.previous_status] ?? c.previous_status }}</span>
                        </td>
                        <td class="px-3 py-2.5">
                            <p v-if="c.reason">{{ c.reason }}</p>
                            <ul class="mt-1 space-y-1">
                                <li v-for="(e, i) in c.evidence" :key="i" class="text-[12px]">
                                    <button
                                        v-if="e.is_valid && e.start_ms !== null"
                                        type="button"
                                        class="text-left text-brand-700 hover:underline"
                                        @click="emit('seek', e.start_ms, e.segment_id)"
                                    >
                                        {{ formatTimecode(e.start_ms) }} · {{ evidenceLabel(e) }}
                                    </button>
                                    <span v-else class="text-red-700">✕ {{ evidenceLabel(e) }} — {{ e.issue }}</span>
                                </li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>

            <details v-if="r.history.length > 1 || r.history.some((h) => h.decisions.length)" class="border-t border-line px-5 py-3 text-[13px]" data-testid="result-history">
                <summary class="cursor-pointer font-medium">История оценки ({{ r.history.length }} ред.)</summary>
                <ol class="mt-2 space-y-2">
                    <li v-for="h in r.history" :key="h.id">
                        <p>
                            <span class="font-medium">Ревизия {{ h.revision }}</span> ·
                            {{ h.origin === 'ai' ? 'оценка ИИ' : `исправил(а) ${h.author ?? 'пользователь'}` }} · {{ formatDateTime(h.created_at) }} · оценка {{ h.score ?? 'нет итога' }}
                        </p>
                        <p v-if="h.reason" class="text-muted">Причина: {{ h.reason }}</p>
                        <ul class="mt-1 space-y-1 pl-4">
                            <li v-for="(d, i) in h.decisions" :key="i" class="text-[12px]">
                                {{ decisionLabels[d.decision] }} ({{ d.source === 'appeal' ? 'апелляция' : 'проверка' }}) — {{ d.decider }}, {{ formatDateTime(d.created_at) }}: {{ d.reason }}
                                <span v-for="(ch, j) in d.changes" :key="j" class="block text-muted">
                                    {{ ch.title }}: {{ criterionStatusLabels[ch.from] ?? ch.from }} → {{ criterionStatusLabels[ch.to] ?? ch.to }} ({{ ch.comment }})
                                </span>
                            </li>
                        </ul>
                    </li>
                </ol>
            </details>

            <div v-if="r.recommendations.length || r.risks.length" class="grid gap-4 border-t border-line px-5 py-3 text-[13px] sm:grid-cols-2">
                <div v-if="r.recommendations.length">
                    <p class="font-semibold">Рекомендации</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5"><li v-for="(x, i) in r.recommendations" :key="i">{{ x }}</li></ul>
                </div>
                <div v-if="r.risks.length">
                    <p class="font-semibold">Риски</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5"><li v-for="(x, i) in r.risks" :key="i">{{ x }}</li></ul>
                </div>
            </div>
        </UiCard>

        <p class="text-[12px] text-muted">
            Анализ #{{ analysis.current.id }} от {{ formatDateTime(analysis.current.started_at) }} · провайдер {{ analysis.current.driver === 'mock' ? 'тестовый' : analysis.current.driver }}
            · шаблон запроса {{ analysis.current.prompt_version }}.
            <template v-for="(route, i) in analysis.current.routing" :key="i"><br />{{ route.checklist }} (версия {{ route.version }}): {{ route.reason }}</template>
        </p>
        <UiCard v-if="analysis.workflow" title="Бизнес-процесс" :description="workflowCaption(analysis.workflow)" data-testid="workflow-trace">
            <UiAlert v-if="analysis.workflow.error_message" tone="danger" class="mb-3">{{ analysis.workflow.error_message }}</UiAlert>
            <ol class="flex flex-wrap items-center gap-1.5 text-[12px]">
                <li v-for="(step, i) in analysis.workflow.steps" :key="i" class="flex items-center gap-1.5">
                    <span v-if="i" class="text-muted">→</span>
                    <span class="rounded border border-line px-1.5 py-0.5">{{ step.label }}<template v-if="step.branch === 'true'"> · да</template><template v-else-if="step.branch === 'false'"> · нет</template></span>
                </li>
            </ol>
        </UiCard>
    </template>
</template>
