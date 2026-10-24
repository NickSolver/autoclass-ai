<script setup lang="ts">
import { reviewsApi } from '@/api';
import type { AnalysisResponse, CriterionChange } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { formatDateTime } from '@/utils/format';
import { computed, reactive, ref } from 'vue';
import CriteriaChangeEditor from './CriteriaChangeEditor.vue';

/** Ручная проверка звонка: отправка, назначение, работа контролёра и решение по каждому чек-листу. */
const props = defineProps<{ callId: number; analysis: AnalysisResponse }>();
const emit = defineEmits<{ changed: [] }>();
const { push } = useToasts();
const form = useForm();

const review = computed(() => props.analysis.review);
const open = computed(() => review.value && ['pending', 'in_progress'].includes(review.value.status));
const results = computed(() => (props.analysis.current?.is_current_run ? props.analysis.current.results : []));

const statusLabels: Record<string, string> = { pending: 'ожидает', in_progress: 'в работе', resolved: 'решена', cancelled: 'отменена' };
const statusTones: Record<string, 'warning' | 'info' | 'success' | 'neutral'> = { pending: 'warning', in_progress: 'info', resolved: 'success', cancelled: 'neutral' };
const resolutionLabels: Record<string, string> = { confirmed: 'результат подтверждён', corrected: 'результат исправлен', cancelled: 'отменена' };

async function act(action: () => Promise<unknown>, message: string): Promise<boolean> {
    const done = await form.submit(action);
    if (done === undefined) return false;
    push(message);
    emit('changed');
    return true;
}

// Отправка на проверку.
const requestOpen = ref(false);
const requestReason = ref('');
async function request(): Promise<void> {
    if (await act(() => reviewsApi.request(props.callId, requestReason.value), 'Звонок отправлен на проверку.')) requestOpen.value = false;
}

// Назначение.
const assignOpen = ref(false);
const assignees = ref<{ value: number; label: string }[]>([]);
const assigneeId = ref<number | null>(null);
async function openAssign(): Promise<void> {
    if (!review.value) return;
    form.reset();
    assigneeId.value = review.value.assignee?.id ?? null;
    assignees.value = (await reviewsApi.assignees(review.value.id)).data.map((u) => ({ value: u.id, label: u.name }));
    assignOpen.value = true;
}
async function assign(): Promise<void> {
    const r = review.value;
    if (!r || !assigneeId.value) return;
    if (await act(() => reviewsApi.assign(r.id, Number(assigneeId.value), r.lock_version), 'Проверка назначена.')) assignOpen.value = false;
}

async function take(): Promise<void> {
    const r = review.value;
    if (r) await act(() => reviewsApi.take(r.id, r.lock_version), 'Проверка взята в работу.');
}

// Отмена.
const cancelOpen = ref(false);
const cancelReason = ref('');
async function cancel(): Promise<void> {
    const r = review.value;
    if (r && (await act(() => reviewsApi.cancel(r.id, r.lock_version, cancelReason.value), 'Проверка отменена.'))) cancelOpen.value = false;
}

// Решение.
const resolving = ref(false);
const decisions = reactive<Record<number, 'confirmed' | 'corrected'>>({});
const changes = reactive<Record<number, Record<number, CriterionChange>>>({});
const comment = ref('');

function startResolve(): void {
    form.reset();
    comment.value = '';
    for (const r of results.value) {
        decisions[r.id] = props.analysis.can.confirm ? 'confirmed' : 'corrected';
        changes[r.id] = {};
    }
    resolving.value = true;
}

const invalid = computed(() =>
    !comment.value.trim() ||
    results.value.some((r) => decisions[r.id] === 'corrected' && (!Object.keys(changes[r.id] ?? {}).length || Object.values(changes[r.id] ?? {}).some((c) => !c.comment.trim()))),
);

async function resolve(): Promise<void> {
    const r = review.value;
    if (!r) return;
    const body = results.value.map((res) => ({
        result_id: res.id,
        decision: decisions[res.id] ?? 'confirmed',
        changes: decisions[res.id] === 'corrected' ? Object.values(changes[res.id] ?? {}) : undefined,
    }));
    if (await act(() => reviewsApi.resolve(r.id, r.lock_version, comment.value, body), 'Проверка завершена.')) resolving.value = false;
}
</script>

<template>
    <UiCard title="Ручная проверка" data-testid="review-panel">
        <UiAlert v-if="form.formError.value || Object.keys(form.errors.value).length" tone="danger" class="mb-3">
            {{ form.formError.value ?? Object.values(form.errors.value)[0] }}
        </UiAlert>

        <div v-if="review" class="space-y-1.5 text-[13px]">
            <p class="flex flex-wrap items-center gap-2">
                <UiBadge :tone="statusTones[review.status]">{{ statusLabels[review.status] }}</UiBadge>
                <span class="text-muted">{{ review.source_label }} · {{ formatDateTime(review.created_at) }}</span>
            </p>
            <p>{{ review.reason }}</p>
            <p v-if="review.assignee" class="text-muted">Исполнитель: {{ review.assignee.name }}</p>
            <p v-if="review.resolution" class="text-muted">
                {{ resolutionLabels[review.resolution] }}<template v-if="review.resolver"> · {{ review.resolver }}</template>
                <template v-if="review.resolved_at"> · {{ formatDateTime(review.resolved_at) }}</template>
                <template v-if="review.resolution_comment">: {{ review.resolution_comment }}</template>
            </p>
        </div>
        <p v-else class="text-[13px] text-muted">Проверка не назначалась.</p>

        <div v-if="!resolving" class="mt-3 flex flex-wrap gap-2">
            <UiButton v-if="!open && analysis.can.request_review" size="sm" @click="form.reset(); requestReason = ''; requestOpen = true">Отправить на проверку</UiButton>
            <UiButton v-if="open && review?.can.take" size="sm" variant="primary" :loading="form.submitting.value" @click="take">Взять в работу</UiButton>
            <UiButton v-if="open && review?.can.resolve" size="sm" variant="primary" @click="startResolve">Вынести решение</UiButton>
            <UiButton v-if="open && review?.can.assign" size="sm" @click="openAssign">Назначить</UiButton>
            <UiButton v-if="open && review?.can.cancel" size="sm" variant="ghost" @click="form.reset(); cancelReason = ''; cancelOpen = true">Отменить</UiButton>
        </div>

        <form v-if="resolving" class="mt-4 space-y-4" data-testid="resolve-form" @submit.prevent="resolve">
            <UiAlert v-if="!results.length" tone="info">У звонка нет результатов оценки — решение касается только задачи.</UiAlert>
            <section v-for="r in results" :key="r.id" class="space-y-2 rounded-lg border border-line p-3">
                <p class="font-medium">{{ r.checklist.name }} <span class="text-muted">· {{ r.role === 'primary' ? 'основной' : 'дополнительный' }} · {{ r.score ?? 'нет итога' }}</span></p>
                <div class="flex flex-wrap gap-4 text-[13px]">
                    <label class="flex items-center gap-1.5">
                        <input v-model="decisions[r.id]" type="radio" value="confirmed" :disabled="!analysis.can.confirm" /> Подтвердить
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input v-model="decisions[r.id]" type="radio" value="corrected" :disabled="!analysis.can.correct" /> Исправить критерии
                    </label>
                </div>
                <CriteriaChangeEditor v-if="decisions[r.id] === 'corrected' && changes[r.id]" v-model="changes[r.id]!" :result="r" />
                <p v-if="decisions[r.id] === 'confirmed' && r.score === null" class="text-[12px] text-amber-700">
                    Подтверждение без правок оставит результат без итоговой оценки — он не войдёт в среднюю.
                </p>
            </section>
            <UiTextarea v-model="comment" label="Комментарий к решению" required :rows="2" :maxlength="2000" />
            <div class="flex gap-2">
                <UiButton type="submit" variant="primary" :loading="form.submitting.value" :disabled="invalid">Завершить проверку</UiButton>
                <UiButton @click="resolving = false">Отмена</UiButton>
            </div>
            <p class="text-[12px] text-muted">Исправление создаёт новую ревизию оценки; исходный результат ИИ сохраняется и виден в истории.</p>
        </form>

        <UiModal v-model:open="requestOpen" title="Отправить на проверку">
            <form id="review-request" @submit.prevent="request">
                <UiTextarea v-model="requestReason" label="Причина" required :rows="3" :maxlength="500" :error="form.errors.value.reason" />
            </form>
            <template #footer>
                <UiButton @click="requestOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="review-request" variant="primary" :loading="form.submitting.value">Отправить</UiButton>
            </template>
        </UiModal>

        <UiModal v-model:open="assignOpen" title="Назначить проверку">
            <UiSelect v-model="assigneeId" label="Контролёр" placeholder="Выберите пользователя" :options="assignees" :error="form.errors.value.assignee_id" />
            <p v-if="!assignees.length" class="mt-2 text-[12px] text-muted">Нет пользователей с правом решать проверки в этом подразделении.</p>
            <template #footer>
                <UiButton @click="assignOpen = false">Отмена</UiButton>
                <UiButton variant="primary" :disabled="!assigneeId" :loading="form.submitting.value" @click="assign">Назначить</UiButton>
            </template>
        </UiModal>

        <UiModal v-model:open="cancelOpen" title="Отменить проверку">
            <form id="review-cancel" @submit.prevent="cancel">
                <UiTextarea v-model="cancelReason" label="Причина отмены" required :rows="2" :maxlength="1000" :error="form.errors.value.reason" />
            </form>
            <template #footer>
                <UiButton @click="cancelOpen = false">Назад</UiButton>
                <UiButton type="submit" form="review-cancel" variant="danger" :loading="form.submitting.value">Отменить проверку</UiButton>
            </template>
        </UiModal>
    </UiCard>
</template>
