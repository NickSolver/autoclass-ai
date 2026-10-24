<script setup lang="ts">
import type { AnalyticsStats } from '@/api/types';
import { computed, ref } from 'vue';

/**
 * Динамика средней оценки (один ряд, шкала 0–100). Точки без итоговой оценки — разрыв линии, не ноль.
 * Наведение показывает дату, среднюю и объём выборки; есть табличный вид.
 */
const props = defineProps<{ points: (AnalyticsStats & { date: string })[]; unit: 'day' | 'week' }>();

const width = 720;
const height = 220;
const pad = { top: 12, right: 16, bottom: 28, left: 36 };
const innerW = width - pad.left - pad.right;
const innerH = height - pad.top - pad.bottom;
const showTable = ref(false);
const hover = ref<number | null>(null);

const x = (i: number) => pad.left + (props.points.length <= 1 ? innerW / 2 : (i * innerW) / (props.points.length - 1));
const y = (v: number) => pad.top + innerH - (v / 100) * innerH;

/** Отрезки линии между соседними точками с оценкой. */
const segments = computed(() => {
    const out: string[] = [];
    let current: string[] = [];
    props.points.forEach((p, i) => {
        if (p.average === null) {
            if (current.length > 1) out.push(current.join(' '));
            current = [];
            return;
        }
        current.push(`${current.length ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.average).toFixed(1)}`);
    });
    if (current.length > 1) out.push(current.join(' '));
    return out;
});

const labelEvery = computed(() => Math.max(1, Math.ceil(props.points.length / 8)));
const fmt = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });

function onMove(e: MouseEvent): void {
    const svg = e.currentTarget as SVGSVGElement;
    const rect = svg.getBoundingClientRect();
    const px = ((e.clientX - rect.left) / rect.width) * width;
    let best = 0;
    props.points.forEach((_, i) => {
        if (Math.abs(x(i) - px) < Math.abs(x(best) - px)) best = i;
    });
    hover.value = props.points.length ? best : null;
}

const hovered = computed(() => (hover.value !== null ? props.points[hover.value] : null));
</script>

<template>
    <div>
        <div class="mb-2 flex items-center justify-between">
            <p class="text-[12px] text-muted">Средняя оценка основного чек-листа по {{ unit === 'week' ? 'неделям' : 'дням' }}; n — звонков с итоговой оценкой.</p>
            <button type="button" class="text-[12px] text-brand-700 hover:underline" @click="showTable = !showTable">{{ showTable ? 'График' : 'Таблица' }}</button>
        </div>
        <p v-if="!points.length" class="py-8 text-center text-[13px] text-muted">Нет оценённых звонков за период.</p>
        <table v-else-if="showTable" class="w-full text-left text-[13px]">
            <thead>
                <tr class="border-b border-line text-[12px] text-muted">
                    <th class="py-1.5 font-medium">{{ unit === 'week' ? 'Неделя с' : 'Дата' }}</th>
                    <th class="py-1.5 font-medium">Средняя</th>
                    <th class="py-1.5 font-medium">С оценкой</th>
                    <th class="py-1.5 font-medium">Оценено</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in points" :key="p.date" class="border-b border-line last:border-0">
                    <td class="py-1.5">{{ fmt(p.date) }}</td>
                    <td class="py-1.5">{{ p.average ?? '—' }}</td>
                    <td class="py-1.5">{{ p.scored }}</td>
                    <td class="py-1.5">{{ p.evaluated }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="relative">
            <svg :viewBox="`0 0 ${width} ${height}`" class="h-auto w-full" role="img" aria-label="Динамика средней оценки" @mousemove="onMove" @mouseleave="hover = null">
                <g class="text-[11px]">
                    <template v-for="t in [0, 25, 50, 75, 100]" :key="t">
                        <line :x1="pad.left" :x2="width - pad.right" :y1="y(t)" :y2="y(t)" class="stroke-gray-200" stroke-width="1" />
                        <text :x="pad.left - 6" :y="y(t) + 4" text-anchor="end" class="fill-gray-500">{{ t }}</text>
                    </template>
                    <template v-for="(p, i) in points" :key="p.date">
                        <text v-if="i % labelEvery === 0" :x="x(i)" :y="height - 8" text-anchor="middle" class="fill-gray-500">{{ fmt(p.date) }}</text>
                    </template>
                </g>
                <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="pad.top" :y2="pad.top + innerH" class="stroke-gray-400" stroke-dasharray="3 3" />
                <path v-for="(d, i) in segments" :key="i" :d="d" fill="none" class="stroke-brand-600" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                <template v-for="(p, i) in points" :key="`m-${p.date}`">
                    <circle v-if="p.average !== null" :cx="x(i)" :cy="y(p.average)" :r="hover === i ? 5 : 4" class="fill-brand-600 stroke-white" stroke-width="2" />
                </template>
            </svg>
            <div
                v-if="hovered && hover !== null"
                class="pointer-events-none absolute top-0 rounded-md border border-line bg-white px-2.5 py-1.5 text-[12px] shadow"
                :style="{ left: `${(x(hover) / width) * 100}%`, transform: x(hover) > width / 2 ? 'translateX(calc(-100% - 8px))' : 'translateX(8px)' }"
            >
                <p class="font-medium">{{ unit === 'week' ? 'Неделя с ' : '' }}{{ fmt(hovered.date) }}</p>
                <p>Средняя: {{ hovered.average ?? 'нет итоговых оценок' }}</p>
                <p class="text-muted">n = {{ hovered.scored }} из {{ hovered.evaluated }} оценённых</p>
            </div>
        </div>
    </div>
</template>
