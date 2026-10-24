<script setup lang="ts">
import { analyticsApi, type Query } from '@/api';
import { ApiError } from '@/api/http';
import type { AnalyticsReport, AnalyticsStats } from '@/api/types';
import TrendChart from '@/components/analytics/TrendChart.vue';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const today = new Date();
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n: number) => iso(new Date(today.getFullYear(), today.getMonth(), today.getDate() - n));

const filters = reactive({ from: daysAgo(29), to: iso(today), unit: null as OrgUnitValue | null, direction: null as string | null, group_by: 'company' });
const report = ref<AnalyticsReport | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const query = computed<Query>(() => ({
    from: filters.from,
    to: filters.to,
    company_id: filters.unit?.type === 'company' ? filters.unit.id : null,
    dealership_id: filters.unit?.type === 'dealership' ? filters.unit.id : null,
    department_id: filters.unit?.type === 'department' ? filters.unit.id : null,
    direction: filters.direction,
    group_by: filters.group_by,
}));

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        report.value = await analyticsApi.report(query.value);
    } catch (e) {
        error.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Не удалось загрузить аналитику.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [filters.unit, filters.direction, filters.group_by], load);

function preset(days: number): void {
    filters.from = daysAgo(days - 1);
    filters.to = iso(today);
    load();
}

const groupOptions = [
    { value: 'company', label: 'Компании' },
    { value: 'dealership', label: 'Дилерские центры' },
    { value: 'department', label: 'Отделы' },
    { value: 'employee', label: 'Сотрудники' },
];
const directionOptions = [
    { value: 'incoming', label: 'Входящие' },
    { value: 'outgoing', label: 'Исходящие' },
];
const fmtPeriod = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('ru-RU');
const share = (s: AnalyticsStats) => (s.critical_share === null ? '—' : `${s.critical_share}%`);
const money = (v: number | null, cur: string) => (v === null ? 'нет данных' : `${v.toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ${cur === 'RUB' ? '₽' : cur}`);
const seconds = (ms: number | null) => (ms === null ? '—' : `${(ms / 1000).toFixed(1)} с`);
const hasDemo = computed(() => !!report.value && [...report.value.checklists.primary, ...report.value.checklists.additional].some((c) => c.is_demo));
</script>

<template>
    <PageHeader title="Аналитика" description="Показатели по итоговым результатам основного чек-листа. Звонки без итоговой оценки показаны отдельно и в среднюю не входят.">
        <template #actions>
            <a v-if="report?.can.export" :href="analyticsApi.exportUrl(query)" download><UiButton>Выгрузить CSV</UiButton></a>
        </template>
    </PageHeader>

    <UiCard class="mb-5">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
            <UiInput v-model="filters.from" type="date" label="С" class="w-40" />
            <UiInput v-model="filters.to" type="date" label="По" class="w-40" />
            <div class="flex gap-1 pb-0.5">
                <UiButton size="sm" variant="ghost" @click="preset(7)">7 дн.</UiButton>
                <UiButton size="sm" variant="ghost" @click="preset(30)">30 дн.</UiButton>
                <UiButton size="sm" variant="ghost" @click="preset(90)">90 дн.</UiButton>
            </div>
            <OrgUnitSelect v-model="filters.unit" permission="analytics.view" label="Подразделение" placeholder="Вся доступная область" class="w-64" />
            <UiSelect v-model="filters.direction" label="Направление" placeholder="Все" :options="directionOptions" class="w-36" />
            <UiButton type="submit" variant="primary">Показать</UiButton>
        </form>
    </UiCard>

    <div v-if="loading && !report" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="error" tone="danger">{{ error }}</UiAlert>
    <template v-else-if="report">
        <p class="mb-3 text-[12px] text-muted" data-testid="analytics-period">
            Период {{ fmtPeriod(report.period.from) }} — {{ fmtPeriod(report.period.to) }} (часовой пояс {{ report.period.timezone }}) · звонков {{ report.volume.total }} ·
            оценено {{ report.quality.evaluated }} · с итоговой оценкой {{ report.quality.scored }}
        </p>
        <UiAlert v-if="hasDemo" tone="warning" class="mb-4">В выборке есть оценки по DEMO-чек-листам — не используйте их для кадровых выводов.</UiAlert>

        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Звонков за период" :value="report.volume.total" icon="phone" />
            <StatTile label="Средняя оценка (основной)" :value="report.quality.average" icon="chart" tone="green" data-testid="kpi-average" />
            <StatTile label="Без итоговой оценки" :value="report.quality.unscored" icon="alert" :tone="report.quality.unscored ? 'amber' : 'blue'" />
            <StatTile label="Критические нарушения, %" :value="report.quality.critical_share" icon="ban" :tone="report.quality.critical ? 'red' : 'blue'" />
        </div>

        <div class="mb-5 grid gap-5 lg:grid-cols-3">
            <UiCard title="Объём" description="Знаменатели показателей.">
                <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-[13px]">
                    <dt>Всего звонков</dt><dd class="text-right font-medium">{{ report.volume.total }}</dd>
                    <dt class="text-muted">исключены</dt><dd class="text-right">{{ report.volume.excluded }}</dd>
                    <dt class="text-muted">ошибка обработки</dt><dd class="text-right">{{ report.volume.failed }}</dd>
                    <dt class="text-muted">в обработке</dt><dd class="text-right">{{ report.volume.in_progress }}</dd>
                    <dt class="text-muted">обработаны без оценки (по процессу)</dt><dd class="text-right">{{ report.volume.completed_without_evaluation }}</dd>
                    <dt>Оценено (основной чек-лист)</dt><dd class="text-right font-medium">{{ report.quality.evaluated }}</dd>
                    <dt class="text-muted">с итоговой оценкой — n средней</dt><dd class="text-right" data-testid="kpi-scored">{{ report.quality.scored }}</dd>
                    <dt class="text-muted">без итоговой оценки</dt><dd class="text-right">{{ report.quality.unscored }}</dd>
                </dl>
            </UiCard>
            <UiCard title="Итоговость" description="Кто поставил итоговую оценку.">
                <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-[13px]" data-testid="kpi-finality">
                    <dt>Оценка ИИ без проверки</dt><dd class="text-right">{{ report.quality.finality.ai }}</dd>
                    <dt>Подтверждено человеком</dt><dd class="text-right">{{ report.quality.finality.human_confirmed }}</dd>
                    <dt>Исправлено человеком</dt><dd class="text-right">{{ report.quality.finality.human_corrected }}</dd>
                </dl>
            </UiCard>
            <UiCard title="Проверки и апелляции" description="По звонкам периода.">
                <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-[13px]">
                    <dt>Проверок ожидает</dt><dd class="text-right">{{ report.review.pending }}</dd>
                    <dt>Проверок в работе</dt><dd class="text-right">{{ report.review.in_progress }}</dd>
                    <dt>Проверок решено</dt><dd class="text-right">{{ report.review.resolved }}</dd>
                    <dt>Апелляций открыто</dt><dd class="text-right">{{ report.review.appeals_open }}</dd>
                    <dt>принято / отклонено</dt><dd class="text-right">{{ report.review.appeals_accepted }} / {{ report.review.appeals_rejected }}</dd>
                </dl>
            </UiCard>
        </div>

        <UiCard title="Динамика" class="mb-5">
            <TrendChart :points="report.dynamics.points" :unit="report.dynamics.unit" />
        </UiCard>

        <UiCard :padded="false" class="mb-5">
            <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                <h2 class="text-[15px] font-semibold">По подразделениям</h2>
                <UiSelect v-model="filters.group_by" :options="groupOptions" aria-label="Разрез" class="w-48" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]" data-testid="breakdown">
                    <thead>
                        <tr class="border-b border-line text-[12px] text-muted">
                            <th class="px-5 py-2 font-medium">Подразделение</th>
                            <th class="px-3 py-2 text-right font-medium">Звонков</th>
                            <th class="px-3 py-2 text-right font-medium">Оценено</th>
                            <th class="px-3 py-2 text-right font-medium">С оценкой (n)</th>
                            <th class="px-3 py-2 text-right font-medium">Средняя</th>
                            <th class="px-5 py-2 text-right font-medium">Крит. нарушения</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!report.breakdown.rows.length"><td colspan="6" class="px-5 py-4 text-center text-muted">Нет звонков за период.</td></tr>
                        <tr v-for="r in report.breakdown.rows" :key="String(r.id)" class="border-b border-line last:border-0">
                            <td class="px-5 py-2">{{ r.name }}</td>
                            <td class="px-3 py-2 text-right">{{ r.calls }}</td>
                            <td class="px-3 py-2 text-right">{{ r.evaluated }}</td>
                            <td class="px-3 py-2 text-right">{{ r.scored }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ r.average ?? '—' }}</td>
                            <td class="px-5 py-2 text-right">{{ share(r) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </UiCard>

        <div class="mb-5 grid gap-5 lg:grid-cols-2">
            <UiCard v-for="role in (['primary', 'additional'] as const)" :key="role" :title="role === 'primary' ? 'Основные чек-листы' : 'Дополнительные чек-листы'" :description="role === 'additional' ? 'Не входят в главную среднюю оценку.' : 'По версиям: изменение чек-листа — новая версия.'" :padded="false">
                <table class="w-full text-left text-[13px]">
                    <thead>
                        <tr class="border-b border-line text-[12px] text-muted">
                            <th class="px-5 py-2 font-medium">Чек-лист</th>
                            <th class="px-3 py-2 text-right font-medium">Оценено</th>
                            <th class="px-3 py-2 text-right font-medium">n</th>
                            <th class="px-5 py-2 text-right font-medium">Средняя</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!report.checklists[role].length"><td colspan="4" class="px-5 py-4 text-center text-muted">Нет оценок.</td></tr>
                        <tr v-for="c in report.checklists[role]" :key="`${c.checklist_id}-${c.version}`" class="border-b border-line last:border-0">
                            <td class="px-5 py-2">{{ c.name }} <span class="text-muted">v{{ c.version }}</span> <UiBadge v-if="c.is_demo" tone="warning">DEMO</UiBadge></td>
                            <td class="px-3 py-2 text-right">{{ c.evaluated }}</td>
                            <td class="px-3 py-2 text-right">{{ c.scored }}</td>
                            <td class="px-5 py-2 text-right font-medium">{{ c.average ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </UiCard>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <UiCard title="Стоимость и скорость ИИ" description="Вызовы провайдеров по звонкам периода.">
                <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-[13px]">
                    <dt>Запросов к провайдерам</dt><dd class="text-right">{{ report.cost.requests }}</dd>
                    <dt>Стоимость</dt><dd class="text-right">{{ money(report.cost.cost, report.cost.currency) }}</dd>
                    <dt class="text-muted">запросов без тарифа (не считаются нулём)</dt><dd class="text-right">{{ report.cost.unpriced_requests }}</dd>
                    <dt>Распознавание, среднее время</dt><dd class="text-right">{{ seconds(report.cost.avg_transcribe_ms) }}</dd>
                    <dt>Оценка, среднее время</dt><dd class="text-right">{{ seconds(report.cost.avg_evaluate_ms) }}</dd>
                </dl>
            </UiCard>
            <UiCard title="Конверсия" description="Договорённость из разговора и подтверждение во внешней системе — разные показатели.">
                <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-[13px]">
                    <dt>Договорённость в разговоре</dt><dd class="text-right text-muted">нет данных</dd>
                    <dt>Подтверждено в CRM</dt><dd class="text-right text-muted">нет данных (CRM не подключена)</dd>
                </dl>
            </UiCard>
        </div>

        <p class="mt-5 text-[12px] text-muted">
            Методика: итоговый результат — последняя ревизия действующего анализа (оценка ИИ, подтверждённая или исправленная человеком).
            Средняя — по основному чек-листу и только по результатам с числовой оценкой; «Недостаточно данных» не превращается в 0.
            Исключённые звонки не оцениваются. Доля критических нарушений — от звонков с итоговой оценкой.
        </p>
    </template>
</template>
