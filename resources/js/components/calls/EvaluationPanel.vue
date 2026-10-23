<script setup lang="ts">
import type { AnalysisResponse, EvaluationResultItem, EvidenceItem } from '@/api/types';
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
                    </p>
                    <p v-if="r.score === null && r.provisional_score !== null" class="mt-1.5 text-[12px] text-muted">
                        Предварительно по разрешённым критериям: {{ r.provisional_score }} — для проверяющего, не для рейтингов.
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
                        <td class="px-3 py-2.5"><UiBadge :tone="statusTone(c.status)">{{ criterionStatusLabels[c.status] }}</UiBadge></td>
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
    </template>
</template>
