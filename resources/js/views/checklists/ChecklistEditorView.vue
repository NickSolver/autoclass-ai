<script setup lang="ts">
import { checklistsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ChecklistChange, ChecklistCriterion, ChecklistDetail, ChecklistTestResult } from '@/api/types';
import VersionList from '@/components/design/VersionList.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { criterionStatusLabels } from '@/utils/format';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps<{ id: number }>();
const { push } = useToasts();
const detail = ref<ChecklistDetail | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const form = reactive({ zero_on_critical: false, notes: '', criteria: [] as ChecklistCriterion[] });
const dirty = ref(false);
const { submitting, errors, formError, submit } = useForm();
const publishing = ref(false);

const selected = computed(() => detail.value?.selected ?? null);
const editable = computed(() => selected.value?.status === 'draft' && !!detail.value?.can.design);
const totalWeight = computed(() => form.criteria.reduce((sum, c) => sum + (Number(c.weight) || 0), 0));

function fill(d: ChecklistDetail): void {
    detail.value = d;
    const v = d.selected;
    Object.assign(form, {
        zero_on_critical: v?.zero_on_critical ?? false,
        notes: v?.notes ?? '',
        criteria: (v?.criteria ?? []).map((c) => ({ ...c })),
    });
    dirty.value = false;
}

async function load(version?: number): Promise<void> {
    loading.value = !detail.value;
    loadError.value = null;
    try {
        fill(await checklistsApi.show(props.id, version));
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить чек-лист.';
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());

function touch(): void {
    dirty.value = true;
}

function addCriterion(): void {
    form.criteria.push({ code: `criterion_${form.criteria.length + 1}`, title: '', description: null, ai_hint: null, weight: 10, is_required: true, is_critical: false, applicability: null });
    touch();
}

function removeCriterion(i: number): void {
    form.criteria.splice(i, 1);
    touch();
}

function move(i: number, delta: number): void {
    const j = i + delta;
    if (j < 0 || j >= form.criteria.length) return;
    const [item] = form.criteria.splice(i, 1);
    if (item) form.criteria.splice(j, 0, item);
    touch();
}

async function save(): Promise<boolean> {
    const v = selected.value;
    if (!v) return false;
    const saved = await submit(() =>
        checklistsApi.saveDraft(props.id, v.id, {
            lock_version: v.lock_version,
            zero_on_critical: form.zero_on_critical,
            notes: form.notes || null,
            criteria: form.criteria.map((c) => ({ ...c, weight: Number(c.weight) })),
        }),
    );
    if (!saved) return false;
    await load(saved.id);
    push('Черновик сохранён.');
    return true;
}

async function publish(): Promise<void> {
    const v = selected.value;
    if (!v) return;
    publishing.value = true;
    try {
        if (dirty.value && !(await save())) return;
        await submit(async () => {
            await checklistsApi.publish(props.id, v.id);
            await load(v.id);
            push(`Версия ${v.version} опубликована. Новые звонки оцениваются по ней.`);
        });
    } finally {
        publishing.value = false;
    }
}

async function newDraft(): Promise<void> {
    await submit(async () => {
        const draft = await checklistsApi.newDraft(props.id);
        await load(draft.id);
        push('Создан черновик — копия опубликованной версии.');
    });
}

// Сравнение версий.
const compareOpen = ref(false);
const compareFrom = ref<number | null>(null);
const compareTo = ref<number | null>(null);
const changes = ref<ChecklistChange[] | null>(null);
const comparing = ref(false);
const versionOptions = computed(() => (detail.value?.versions ?? []).map((v) => ({ value: v.id, label: `v${v.version} · ${statusLabel[v.status]}` })));

function openCompare(): void {
    const versions = detail.value?.versions ?? [];
    compareTo.value = selected.value?.id ?? null;
    compareFrom.value = versions.find((v) => v.id !== compareTo.value)?.id ?? null;
    changes.value = null;
    compareOpen.value = true;
}

async function runCompare(): Promise<void> {
    if (!compareFrom.value || !compareTo.value) return;
    comparing.value = true;
    try {
        changes.value = (await checklistsApi.compare(props.id, compareFrom.value, compareTo.value)).changes;
    } finally {
        comparing.value = false;
    }
}

const changeLabels: Record<ChecklistChange['change'], string> = { added: 'добавлен', removed: 'удалён', changed: 'изменён' };
const fieldLabels: Record<string, string> = {
    title: 'Название', description: 'Описание', ai_hint: 'Подсказка ИИ', weight: 'Вес', is_required: 'Обязательный', is_critical: 'Критический', applicability: 'Применимость',
};

function changedFields(c: ChecklistChange): { field: string; before: unknown; after: unknown }[] {
    const keys = Object.keys(fieldLabels);
    return keys
        .filter((k) => JSON.stringify(c.before?.[k as keyof ChecklistCriterion] ?? null) !== JSON.stringify(c.after?.[k as keyof ChecklistCriterion] ?? null))
        .map((k) => ({ field: fieldLabels[k] ?? k, before: c.before?.[k as keyof ChecklistCriterion], after: c.after?.[k as keyof ChecklistCriterion] }));
}

function show(value: unknown): string {
    if (value === true) return 'да';
    if (value === false) return 'нет';
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

// Пробная оценка на звонке.
const testOpen = ref(false);
const testCallId = ref<string | number | null>('');
const testResult = ref<ChecklistTestResult | null>(null);
const testForm = useForm();

async function runTest(): Promise<void> {
    const v = selected.value;
    if (!v) return;
    testResult.value = null;
    if (editable.value && dirty.value && !(await save())) return;
    testResult.value = (await testForm.submit(() => checklistsApi.test(props.id, v.id, Number(testCallId.value)))) ?? null;
}

const statusLabel: Record<string, string> = { draft: 'черновик', published: 'опубликована', archived: 'архив' };
const businessStatus: Record<string, string> = { full_assessment: 'Полная оценка', human_intervention_required: 'Требуется вмешательство человека' };
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="loadError || !detail" tone="danger" title="Чек-лист недоступен">{{ loadError }}</UiAlert>
    <template v-else>
        <PageHeader :title="detail.name" :description="`${detail.code} · ${detail.company?.name ?? 'Вся группа'}`">
            <template #actions>
                <UiBadge v-if="detail.is_demo" tone="warning">демо</UiBadge>
                <UiButton v-if="(detail.versions.length ?? 0) > 1" @click="openCompare">Сравнить версии</UiButton>
                <UiButton v-if="detail.can.design && selected?.criteria.length" @click="testResult = null; testOpen = true">Проверить на звонке</UiButton>
                <UiButton v-if="detail.can.design && !detail.has_draft" @click="newDraft">Новый черновик</UiButton>
                <UiButton v-if="editable" :loading="submitting && !publishing" :disabled="!dirty" @click="save">Сохранить черновик</UiButton>
                <UiButton v-if="selected?.status === 'draft' && detail.can.publish" variant="primary" :loading="publishing" @click="publish">Опубликовать</UiButton>
            </template>
        </PageHeader>

        <UiAlert v-if="formError || errors.lock_version || errors.version || errors.criteria" tone="danger" class="mb-5">
            {{ errors.lock_version || errors.version || errors.criteria || formError }}
        </UiAlert>
        <UiAlert v-if="selected && selected.status !== 'draft'" tone="info" class="mb-5">
            Версия {{ selected.version }} {{ selected.status === 'published' ? 'опубликована' : 'в архиве' }} и не изменяется.
            {{ detail.can.design ? (detail.has_draft ? 'Правки — в черновике.' : 'Для правок создайте новый черновик.') : '' }}
        </UiAlert>
        <UiAlert v-else-if="selected && !detail.can.publish && detail.can.design" tone="info" class="mb-5">Публикует пользователь с правом «Публикация чек-листов».</UiAlert>

        <div class="grid gap-5 lg:grid-cols-[220px_1fr]">
            <VersionList :versions="detail.versions" :selected-id="selected?.id ?? null" @select="(id) => load(id)" />

            <div v-if="selected" class="space-y-5">
                <UiCard title="Настройки версии">
                    <fieldset :disabled="!editable" class="space-y-3">
                        <UiCheckbox v-model="form.zero_on_critical" label="Обнулять оценку при критическом нарушении" hint="Иначе критическое нарушение только отмечается." @update:model-value="touch" />
                        <UiTextarea v-model="form.notes" label="Что изменилось в версии" :rows="2" :maxlength="2000" @update:model-value="touch" />
                    </fieldset>
                </UiCard>

                <UiCard :title="`Критерии (${form.criteria.length})`" :description="`Сумма весов: ${totalWeight}. Оценка — доля весов выполненных применимых критериев.`">
                    <p v-if="!form.criteria.length" class="py-4 text-center text-muted">Критериев нет.</p>
                    <ol class="space-y-3">
                        <li v-for="(c, i) in form.criteria" :key="i" class="rounded-lg border border-line p-3" data-testid="criterion">
                            <fieldset :disabled="!editable" class="grid gap-3 md:grid-cols-[1fr_160px_100px]">
                                <UiInput v-model="c.title" label="Критерий" required :error="errors[`criteria.${i}.title`]" @update:model-value="touch" />
                                <UiInput v-model="c.code" label="Код" required :error="errors[`criteria.${i}.code`]" @update:model-value="touch" />
                                <UiInput v-model.number="c.weight" type="number" label="Вес" required :error="errors[`criteria.${i}.weight`]" @update:model-value="touch" />
                                <UiTextarea v-model="c.description" label="Описание для проверяющего" :rows="2" class="md:col-span-3" @update:model-value="touch" />
                                <UiTextarea v-model="c.ai_hint" label="Подсказка для ИИ" :rows="2" hint="Что считать выполнением." class="md:col-span-3" @update:model-value="touch" />
                                <UiInput v-model="c.applicability" label="Когда применим" placeholder="Например: только если клиент спросил о цене" class="md:col-span-3" @update:model-value="touch" />
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 md:col-span-3">
                                    <UiCheckbox v-model="c.is_required" label="Обязательный" @update:model-value="touch" />
                                    <UiCheckbox v-model="c.is_critical" label="Критический" @update:model-value="touch" />
                                    <span v-if="editable" class="ml-auto flex gap-1">
                                        <UiButton size="sm" variant="ghost" :disabled="i === 0" aria-label="Выше" @click="move(i, -1)">↑</UiButton>
                                        <UiButton size="sm" variant="ghost" :disabled="i === form.criteria.length - 1" aria-label="Ниже" @click="move(i, 1)">↓</UiButton>
                                        <UiButton size="sm" variant="ghost" @click="removeCriterion(i)">Удалить</UiButton>
                                    </span>
                                </div>
                            </fieldset>
                        </li>
                    </ol>
                    <UiButton v-if="editable" class="mt-3" @click="addCriterion">Добавить критерий</UiButton>
                </UiCard>
            </div>
        </div>

        <UiModal v-model:open="compareOpen" title="Сравнение версий" size="lg">
            <div class="flex flex-wrap items-end gap-3">
                <UiSelect v-model="compareFrom" label="Было" :options="versionOptions" class="w-48" />
                <UiSelect v-model="compareTo" label="Стало" :options="versionOptions" class="w-48" />
                <UiButton variant="primary" :loading="comparing" @click="runCompare">Сравнить</UiButton>
            </div>
            <p v-if="changes && !changes.length" class="mt-4 text-muted">Критерии не отличаются.</p>
            <ul v-if="changes?.length" class="mt-4 space-y-3">
                <li v-for="c in changes" :key="c.code" class="rounded-lg border border-line p-3">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ c.after?.title ?? c.before?.title }}</span>
                        <span class="text-[12px] text-muted">{{ c.code }}</span>
                        <UiBadge :tone="c.change === 'added' ? 'success' : c.change === 'removed' ? 'danger' : 'info'">{{ changeLabels[c.change] }}</UiBadge>
                    </div>
                    <table v-if="c.change === 'changed'" class="mt-2 w-full text-[13px]">
                        <tr v-for="f in changedFields(c)" :key="f.field">
                            <td class="w-40 py-0.5 text-muted">{{ f.field }}</td>
                            <td class="py-0.5 line-through decoration-red-400">{{ show(f.before) }}</td>
                            <td class="py-0.5">{{ show(f.after) }}</td>
                        </tr>
                    </table>
                </li>
            </ul>
        </UiModal>

        <UiModal v-model:open="testOpen" title="Проверка на звонке" size="lg">
            <p class="mb-3 text-muted">Оценка версии v{{ selected?.version }} по последней расшифровке звонка. Результат не сохраняется в звонок; запрос к провайдеру оценки выполняется.</p>
            <form class="flex items-end gap-3" @submit.prevent="runTest">
                <UiInput v-model="testCallId" label="Номер звонка" inputmode="numeric" required class="w-40" :error="testForm.errors.value.call_id" />
                <UiButton type="submit" variant="primary" :loading="testForm.submitting.value">Оценить</UiButton>
            </form>
            <UiAlert v-if="testForm.formError.value" tone="danger" class="mt-3">{{ testForm.formError.value }}</UiAlert>
            <div v-if="testResult" class="mt-4 space-y-3" data-testid="test-result">
                <UiAlert v-if="testResult.synthetic" tone="warning">Расшифровка тестовая (синтетическая) — результат только для проверки настройки.</UiAlert>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-[22px] font-semibold">{{ testResult.score.score ?? testResult.score.provisional_score ?? '—' }}</span>
                    <UiBadge :tone="testResult.score.business_status === 'full_assessment' ? 'success' : 'warning'">{{ businessStatus[testResult.score.business_status] ?? testResult.score.business_status }}</UiBadge>
                    <UiBadge v-if="testResult.score.critical_violation" tone="danger">критическое нарушение</UiBadge>
                </div>
                <p v-if="testResult.summary">{{ testResult.summary }}</p>
                <ul class="divide-y divide-line rounded-lg border border-line">
                    <li v-for="(c, i) in testResult.criteria" :key="i" class="px-3 py-2">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ c.title }}</span>
                            <UiBadge class="ml-auto">{{ criterionStatusLabels[c.status] ?? c.status }}</UiBadge>
                        </div>
                        <p v-if="c.reason" class="text-[13px] text-muted">{{ c.reason }}</p>
                        <p v-for="issue in c.issues" :key="issue" class="text-[12px] text-amber-700">{{ issue }}</p>
                    </li>
                </ul>
            </div>
        </UiModal>
    </template>
</template>
