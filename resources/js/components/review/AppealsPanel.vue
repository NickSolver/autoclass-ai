<script setup lang="ts">
import { appealsApi } from '@/api';
import type { AnalysisResponse, AppealItem, CriterionChange } from '@/api/types';
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
import { computed, ref } from 'vue';
import CriteriaChangeEditor from './CriteriaChangeEditor.vue';

/** Апелляции по звонку: подача, назначение независимому контролёру, решение. */
const props = defineProps<{ callId: number; analysis: AnalysisResponse }>();
const emit = defineEmits<{ changed: [] }>();
const { push } = useToasts();
const form = useForm();

type AppealStatus = AppealItem['status'];
const statusLabels: Record<AppealStatus, string> = { open: 'подана', in_review: 'на рассмотрении', accepted: 'принята', rejected: 'отклонена', withdrawn: 'отозвана' };
const statusTones: Record<AppealStatus, 'warning' | 'info' | 'success' | 'danger' | 'neutral'> = { open: 'warning', in_review: 'info', accepted: 'success', rejected: 'danger', withdrawn: 'neutral' };

const results = computed(() => (props.analysis.current?.is_current_run ? props.analysis.current.results : []));
const resultOptions = computed(() => results.value.map((r) => ({ value: r.id, label: `${r.checklist.name} · ${r.role === 'primary' ? 'основной' : 'дополнительный'} · ${r.score ?? 'нет итога'}` })));

async function act(action: () => Promise<unknown>, message: string): Promise<boolean> {
    const done = await form.submit(action);
    if (done === undefined) return false;
    push(message);
    emit('changed');
    return true;
}

function take(a: AppealItem): Promise<boolean> {
    return act(() => appealsApi.take(a.id, a.lock_version), 'Апелляция взята на рассмотрение.');
}

function withdraw(a: AppealItem): Promise<boolean> {
    return act(() => appealsApi.withdraw(a.id, a.lock_version), 'Апелляция отозвана.');
}

// Подача.
const createOpen = ref(false);
const resultId = ref<number | null>(null);
const reason = ref('');
function openCreate(): void {
    form.reset();
    resultId.value = results.value.find((r) => r.role === 'primary')?.id ?? null;
    reason.value = '';
    createOpen.value = true;
}
async function create(): Promise<void> {
    if (resultId.value && (await act(() => appealsApi.create(props.callId, Number(resultId.value), reason.value), 'Апелляция подана.'))) createOpen.value = false;
}

// Назначение.
const assignFor = ref<AppealItem | null>(null);
const assignees = ref<{ value: number; label: string }[]>([]);
const assigneeId = ref<number | null>(null);
async function openAssign(a: AppealItem): Promise<void> {
    form.reset();
    assigneeId.value = a.assignee?.id ?? null;
    assignees.value = (await appealsApi.assignees(a.id)).data.map((u) => ({ value: u.id, label: u.name }));
    assignFor.value = a;
}
async function assign(): Promise<void> {
    const a = assignFor.value;
    if (a && assigneeId.value && (await act(() => appealsApi.assign(a.id, Number(assigneeId.value), a.lock_version), 'Апелляция назначена.'))) assignFor.value = null;
}

// Решение.
const resolveFor = ref<AppealItem | null>(null);
const decision = ref<'accepted' | 'rejected'>('rejected');
const changes = ref<Record<number, CriterionChange>>({});
const comment = ref('');
/** Действующая ревизия того же чек-листа — её и исправляет принятая апелляция. */
const target = computed(() => {
    const a = resolveFor.value;
    return a ? results.value.find((r) => r.role === a.result.role && r.checklist.name === a.result.checklist && r.checklist.version === a.result.version) ?? null : null;
});
function openResolve(a: AppealItem): void {
    form.reset();
    decision.value = 'rejected';
    changes.value = {};
    comment.value = '';
    resolveFor.value = a;
}
const invalid = computed(() => !comment.value.trim() || (decision.value === 'accepted' && (!Object.keys(changes.value).length || Object.values(changes.value).some((c) => !c.comment.trim()))));
async function resolve(): Promise<void> {
    const a = resolveFor.value;
    if (!a) return;
    const ok = await act(
        () => appealsApi.resolve(a.id, a.lock_version, decision.value, comment.value, decision.value === 'accepted' ? Object.values(changes.value) : []),
        decision.value === 'accepted' ? 'Апелляция принята, оценка исправлена.' : 'Апелляция отклонена.',
    );
    if (ok) resolveFor.value = null;
}
</script>

<template>
    <UiCard v-if="analysis.appeals.length || analysis.can.appeal" title="Апелляции" data-testid="appeals-panel">
        <UiAlert v-if="form.formError.value || Object.keys(form.errors.value).length" tone="danger" class="mb-3">
            {{ form.formError.value ?? Object.values(form.errors.value)[0] }}
        </UiAlert>
        <ul v-if="analysis.appeals.length" class="space-y-3">
            <li v-for="a in analysis.appeals" :key="a.id" class="space-y-1 rounded-lg border border-line p-3 text-[13px]" data-testid="appeal">
                <p class="flex flex-wrap items-center gap-2">
                    <UiBadge :tone="statusTones[a.status]">{{ statusLabels[a.status] }}</UiBadge>
                    <span class="text-muted">{{ a.result.checklist }} · {{ a.author }} · {{ formatDateTime(a.created_at) }}</span>
                </p>
                <p>{{ a.reason }}</p>
                <p v-if="a.assignee" class="text-muted">Рассматривает: {{ a.assignee.name }}</p>
                <p v-if="a.resolution_comment" class="text-muted">Решение{{ a.resolver ? ` (${a.resolver})` : '' }}: {{ a.resolution_comment }}</p>
                <p v-if="a.conflict && (a.status === 'open' || a.status === 'in_review')" class="text-[12px] text-muted">{{ a.conflict }}</p>
                <div class="flex flex-wrap gap-2 pt-1">
                    <UiButton v-if="a.can.take" size="sm" variant="primary" :loading="form.submitting.value" @click="take(a)">Взять на рассмотрение</UiButton>
                    <UiButton v-if="a.can.resolve" size="sm" variant="primary" @click="openResolve(a)">Вынести решение</UiButton>
                    <UiButton v-if="a.can.assign" size="sm" @click="openAssign(a)">Назначить</UiButton>
                    <UiButton v-if="a.can.withdraw" size="sm" variant="ghost" @click="withdraw(a)">Отозвать</UiButton>
                </div>
            </li>
        </ul>
        <p v-else class="text-[13px] text-muted">Апелляций нет.</p>
        <UiButton v-if="analysis.can.appeal && results.length" size="sm" class="mt-3" @click="openCreate">Подать апелляцию</UiButton>

        <UiModal v-model:open="createOpen" title="Апелляция на оценку">
            <form id="appeal-create" class="space-y-3" @submit.prevent="create">
                <UiSelect v-model="resultId" label="Оценка" :options="resultOptions" :error="form.errors.value.result_id" />
                <UiTextarea v-model="reason" label="С чем вы не согласны" required :rows="4" :maxlength="2000" hint="Не меньше 10 символов. Укажите момент разговора, если можно." :error="form.errors.value.reason" />
            </form>
            <template #footer>
                <UiButton @click="createOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="appeal-create" variant="primary" :loading="form.submitting.value">Подать</UiButton>
            </template>
        </UiModal>

        <UiModal :open="!!assignFor" title="Назначить апелляцию" @update:open="(v) => !v && (assignFor = null)">
            <UiSelect v-model="assigneeId" label="Контролёр" placeholder="Выберите пользователя" :options="assignees" :error="form.errors.value.assignee_id" />
            <p class="mt-2 text-[12px] text-muted">В списке — только независимые: не автор, не сотрудник звонка и не тот, кто выставил оспариваемую оценку.</p>
            <template #footer>
                <UiButton @click="assignFor = null">Отмена</UiButton>
                <UiButton variant="primary" :disabled="!assigneeId" :loading="form.submitting.value" @click="assign">Назначить</UiButton>
            </template>
        </UiModal>

        <UiModal :open="!!resolveFor" title="Решение по апелляции" size="lg" @update:open="(v) => !v && (resolveFor = null)">
            <form id="appeal-resolve" class="space-y-3" @submit.prevent="resolve">
                <div class="flex gap-4 text-[13px]">
                    <label class="flex items-center gap-1.5"><input v-model="decision" type="radio" value="rejected" /> Отклонить</label>
                    <label class="flex items-center gap-1.5" :class="!analysis.can.correct || !target ? 'opacity-50' : ''">
                        <input v-model="decision" type="radio" value="accepted" :disabled="!analysis.can.correct || !target" /> Принять и исправить оценку
                    </label>
                </div>
                <CriteriaChangeEditor v-if="decision === 'accepted' && target" v-model="changes" :result="target" />
                <UiTextarea v-model="comment" label="Обоснование решения" required :rows="3" :maxlength="2000" />
            </form>
            <template #footer>
                <UiButton @click="resolveFor = null">Отмена</UiButton>
                <UiButton type="submit" form="appeal-resolve" variant="primary" :disabled="invalid" :loading="form.submitting.value">Вынести решение</UiButton>
            </template>
        </UiModal>
    </UiCard>
</template>
