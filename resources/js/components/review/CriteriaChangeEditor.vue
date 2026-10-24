<script setup lang="ts">
import type { CriterionChange, EvaluationResultItem } from '@/api/types';
import { criterionStatusLabels } from '@/utils/format';

/**
 * Правка статусов критериев одного результата. В модель попадают только изменённые критерии,
 * у каждого — обязательный комментарий (причина правки).
 */
const props = defineProps<{ result: EvaluationResultItem }>();
const changes = defineModel<Record<number, CriterionChange>>({ required: true });

const statuses = Object.entries(criterionStatusLabels) as [CriterionChange['status'], string][];

function setStatus(id: number, status: CriterionChange['status']): void {
    const original = props.result.criteria.find((c) => c.id === id)?.status;
    const next = { ...changes.value };
    if (status === original) delete next[id];
    else next[id] = { criterion_result_id: id, status, comment: next[id]?.comment ?? '' };
    changes.value = next;
}

function setComment(id: number, comment: string): void {
    const current = changes.value[id];
    if (current) changes.value = { ...changes.value, [id]: { ...current, comment } };
}
</script>

<template>
    <ul class="divide-y divide-line rounded-lg border border-line" data-testid="criteria-editor">
        <li v-for="c in result.criteria" :key="c.id" class="space-y-2 px-3 py-2" :class="changes[c.id] ? 'bg-amber-50/60' : ''">
            <div class="flex flex-wrap items-center gap-2">
                <span class="min-w-0 flex-1 text-[13px]">
                    {{ c.title }}
                    <span class="block text-[11px] text-muted">сейчас: {{ criterionStatusLabels[c.status] }}</span>
                </span>
                <select
                    :value="changes[c.id]?.status ?? c.status"
                    class="h-8 rounded-md border border-line bg-white px-2 text-[13px]"
                    :aria-label="`Статус: ${c.title}`"
                    @change="setStatus(c.id, ($event.target as HTMLSelectElement).value as CriterionChange['status'])"
                >
                    <option v-for="[value, label] in statuses" :key="value" :value="value">{{ label }}</option>
                </select>
            </div>
            <input
                v-if="changes[c.id]"
                :value="changes[c.id]?.comment"
                class="h-8 w-full rounded-md border border-line px-2 text-[13px]"
                :class="!changes[c.id]?.comment.trim() ? 'border-red-400' : ''"
                :aria-label="`Причина изменения: ${c.title}`"
                placeholder="Причина изменения (обязательно)"
                maxlength="1000"
                @input="setComment(c.id, ($event.target as HTMLInputElement).value)"
            />
        </li>
    </ul>
</template>
