<script setup lang="ts">
import { callsApi, employeesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Call, Employee } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { callStatusTone, formatBytes, formatDateTime, formatDuration } from '@/utils/format';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps<{ id: number }>();
const auth = useAuthStore();
const { push } = useToasts();
const call = ref<Call | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
let poll: ReturnType<typeof setTimeout> | undefined;

const inProgress = computed(() => ['received', 'queued', 'preparing', 'transcribing', 'classifying', 'evaluating'].includes(call.value?.status.code ?? ''));

async function load(silent = false): Promise<void> {
    if (!silent) loading.value = true;
    error.value = null;
    try {
        call.value = await callsApi.show(props.id);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить звонок.';
    } finally {
        loading.value = false;
    }
    // Пока звонок обрабатывается — обновляем статус (реальные этапы, без процентов).
    clearTimeout(poll);
    if (inProgress.value) poll = setTimeout(() => load(true), 3000);
}
onMounted(load);
onBeforeUnmount(() => clearTimeout(poll));

const placeLabel = computed(() => [call.value?.org.company, call.value?.org.dealership, call.value?.org.department].filter(Boolean).join(' / '));
const stepLabels: Record<string, string> = { prepare: 'Подготовка аудио' };

// --- Действия ---
const busy = ref<string | null>(null);

async function reprocess(): Promise<void> {
    busy.value = 'reprocess';
    try {
        call.value = await callsApi.reprocess(props.id);
        push('Обработка запущена повторно.');
        await load(true);
    } catch (e) {
        push(e instanceof ApiError ? e.message : 'Ошибка.', 'danger');
    } finally {
        busy.value = null;
    }
}

const excludeOpen = ref(false);
const excludeError = ref<string>();
async function exclude(reason: string): Promise<void> {
    busy.value = 'exclude';
    excludeError.value = undefined;
    try {
        call.value = await callsApi.exclude(props.id, reason);
        excludeOpen.value = false;
        push('Звонок исключён из обработки. Запись сохранена.');
    } catch (e) {
        excludeError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        busy.value = null;
    }
}

// --- Исправление сведений ---
const editOpen = ref(false);
const unit = ref<OrgUnitValue | null>(null);
const employees = ref<Employee[]>([]);
const form = reactive({ employee_id: null as number | null, direction: 'unknown', started_at: '', note: '' });
const edit = useForm();

function toLocalInput(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

async function loadEmployees(value: OrgUnitValue | null): Promise<void> {
    employees.value = [];
    if (!auth.can('employees.view') || !call.value) return;
    const filter = value
        ? { [`${value.type}_id`]: value.id }
        : call.value.department_id
          ? { department_id: call.value.department_id }
          : call.value.dealership_id
            ? { dealership_id: call.value.dealership_id }
            : { company_id: call.value.company_id };
    employees.value = (await employeesApi.list({ ...filter, is_active: 1, per_page: 100 }).catch(() => ({ data: [] as Employee[] }))).data;
}

function openEdit(): void {
    if (!call.value) return;
    unit.value = null;
    Object.assign(form, {
        employee_id: call.value.employee_id,
        direction: call.value.direction.code,
        started_at: toLocalInput(call.value.started_at),
        note: call.value.note ?? '',
    });
    edit.reset();
    void loadEmployees(null);
    editOpen.value = true;
}
watch(unit, (v) => {
    form.employee_id = null;
    void loadEmployees(v);
});

const employeeOptions = computed(() => {
    const options = employees.value.map((e) => ({ value: e.id, label: e.full_name }));
    const current = call.value;
    if (current?.employee_id && !unit.value && !options.some((o) => o.value === current.employee_id)) {
        options.unshift({ value: current.employee_id, label: current.org.employee ?? `Сотрудник #${current.employee_id}` });
    }
    return options;
});

async function saveEdit(): Promise<void> {
    const body: Record<string, unknown> = {
        employee_id: form.employee_id,
        direction: form.direction,
        started_at: form.started_at ? new Date(form.started_at).toISOString() : null,
        started_at_tz: form.started_at ? Intl.DateTimeFormat().resolvedOptions().timeZone : null,
        note: form.note || null,
    };
    if (unit.value) Object.assign(body, { company_id: null, dealership_id: null, department_id: null, [`${unit.value.type}_id`]: unit.value.id });
    const result = await edit.submit(() => callsApi.update(props.id, body));
    if (result) {
        call.value = result;
        editOpen.value = false;
        push('Сведения звонка сохранены.');
    }
}
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="error" tone="danger" title="Звонок недоступен">{{ error }}</UiAlert>
    <template v-else-if="call">
        <PageHeader :title="`Звонок #${call.id}`" :description="call.original?.name ?? undefined">
            <template #actions>
                <UiBadge :tone="callStatusTone(call.status.code)">
                    <UiSpinner v-if="inProgress" :size="12" />{{ call.status.label }}
                </UiBadge>
                <a v-if="call.can.download" :href="callsApi.audioUrl(call.id, true)" download>
                    <UiButton><AppIcon name="download" :size="16" /> Скачать</UiButton>
                </a>
                <UiButton v-if="call.can.reprocess && !inProgress" :loading="busy === 'reprocess'" @click="reprocess">
                    <AppIcon name="refresh" :size="16" /> {{ call.status.code === 'excluded' ? 'Вернуть в обработку' : 'Обработать повторно' }}
                </UiButton>
                <UiButton v-if="call.can.exclude && call.status.code !== 'excluded'" variant="ghost" @click="excludeError = undefined; excludeOpen = true">
                    <AppIcon name="ban" :size="16" /> Исключить
                </UiButton>
            </template>
        </PageHeader>

        <UiAlert v-if="call.status.code === 'failed'" tone="danger" title="Обработка не выполнена" class="mb-5">
            {{ call.last_error ?? 'Причина не указана.' }}
            <template v-if="call.can.reprocess"> Можно запустить обработку повторно — запись сохранена.</template>
        </UiAlert>
        <UiAlert v-if="call.excluded" tone="warning" title="Исключён из обработки" class="mb-5">
            {{ formatDateTime(call.excluded.at) }}. Причина: {{ call.excluded.reason }}
        </UiAlert>
        <UiAlert v-if="call.duplicate_of" tone="warning" class="mb-5">
            Такой же файл уже загружался ранее
            <RouterLink v-if="call.duplicate_of.id" :to="`/calls/${call.duplicate_of.id}`" class="font-medium underline">(звонок #{{ call.duplicate_of.id }})</RouterLink>
            <template v-else>(в подразделении вне вашей области)</template>. Загружен повторно с подтверждением.
        </UiAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <UiCard title="Запись">
                    <audio v-if="call.can.play" :src="callsApi.audioUrl(call.id)" controls preload="metadata" class="w-full">
                        Браузер не поддерживает воспроизведение.
                    </audio>
                    <p v-else class="text-[13px] text-muted">Нет права прослушивать записи.</p>
                    <dl v-if="call.original" class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[13px] sm:grid-cols-4">
                        <div><dt class="text-muted">Формат</dt><dd>{{ call.original.format.toUpperCase() }}<template v-if="call.original.codec"> · {{ call.original.codec }}</template></dd></div>
                        <div><dt class="text-muted">Длительность</dt><dd>{{ formatDuration(call.original.duration_ms) }}</dd></div>
                        <div><dt class="text-muted">Размер</dt><dd>{{ formatBytes(call.original.size_bytes) }}</dd></div>
                        <div><dt class="text-muted">Каналы / частота</dt><dd>{{ call.original.channels ?? '—' }} / {{ call.original.sample_rate ? `${call.original.sample_rate / 1000} кГц` : '—' }}</dd></div>
                    </dl>
                </UiCard>

                <UiCard title="Расшифровка и оценка">
                    <div class="flex items-start gap-3 text-[13px] text-muted">
                        <AppIcon name="lock" class="mt-0.5" />
                        <p>
                            Распознавание речи и оценка по чек-листам ещё не подключены (этапы C и D).
                            <template v-if="call.normalized">Запись уже подготовлена к распознаванию: моно, {{ (call.normalized.sample_rate ?? 0) / 1000 }} кГц.</template>
                        </p>
                    </div>
                </UiCard>

                <UiCard title="История обработки" :padded="false">
                    <p v-if="!call.attempts?.length" class="px-5 py-4 text-[13px] text-muted">Обработка ещё не начиналась.</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="a in call.attempts" :key="a.id" class="flex flex-wrap items-start gap-3 px-5 py-3 text-[13px]">
                            <UiBadge :tone="a.status === 'succeeded' ? 'success' : a.status === 'failed' ? 'danger' : 'info'">
                                {{ a.status === 'succeeded' ? 'Успешно' : a.status === 'failed' ? 'Ошибка' : 'Выполняется' }}
                            </UiBadge>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ stepLabels[a.step] ?? a.step }} — попытка {{ a.attempt }}</p>
                                <p class="text-muted">{{ formatDateTime(a.started_at) }}<template v-if="a.finished_at"> → {{ formatDateTime(a.finished_at) }}</template></p>
                                <p v-if="a.error_message" class="mt-0.5 text-red-700">{{ a.error_message }}</p>
                            </div>
                        </li>
                    </ul>
                </UiCard>
            </div>

            <UiCard title="Сведения">
                <template #actions>
                    <UiButton v-if="call.can.update" size="sm" @click="openEdit">Изменить</UiButton>
                </template>
                <dl class="space-y-2.5 text-[13px]">
                    <div>
                        <dt class="text-muted">Дата звонка</dt>
                        <dd>{{ call.started_at ? formatDateTime(call.started_at) : 'не указана' }}<span v-if="call.started_at_tz" class="text-muted"> ({{ call.started_at_tz }})</span></dd>
                    </div>
                    <div><dt class="text-muted">Направление</dt><dd>{{ call.direction.label }}</dd></div>
                    <div><dt class="text-muted">Подразделение</dt><dd>{{ placeLabel }}</dd></div>
                    <div>
                        <dt class="text-muted">Сотрудник</dt>
                        <dd v-if="call.org.employee">{{ call.org.employee }}<span v-if="call.org.employee_position" class="block text-muted">{{ call.org.employee_position }}</span></dd>
                        <dd v-else><UiBadge tone="warning">не установлен</UiBadge></dd>
                    </div>
                    <p class="text-[11px] text-muted">Названия и должность — на момент приёма ({{ formatDateTime(call.org.captured_at) }}); последующие изменения оргструктуры их не меняют.</p>
                    <div v-if="call.note"><dt class="text-muted">Примечание</dt><dd class="whitespace-pre-line">{{ call.note }}</dd></div>
                    <div><dt class="text-muted">Источник</dt><dd>{{ call.source?.name }}</dd></div>
                    <div><dt class="text-muted">Загрузил</dt><dd>{{ call.uploader?.name ?? '—' }}, {{ formatDateTime(call.created_at) }}</dd></div>
                </dl>
            </UiCard>
        </div>

        <UiModal v-model:open="editOpen" title="Сведения звонка">
            <form id="call-edit-form" class="space-y-4" @submit.prevent="saveEdit">
                <UiAlert v-if="edit.formError.value" tone="danger">{{ edit.formError.value }}</UiAlert>
                <OrgUnitSelect v-model="unit" permission="calls.upload" :types="['company', 'dealership', 'department']" label="Перенести в подразделение" placeholder="Не менять"
                               :hint="`Сейчас: ${placeLabel}`" :error="edit.errors.value.company_id || edit.errors.value.department_id" />
                <UiSelect v-model="form.employee_id" label="Сотрудник" placeholder="Не установлен" :options="employeeOptions" :disabled="!auth.can('employees.view')" :error="edit.errors.value.employee_id" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiSelect v-model="form.direction" label="Направление" :options="[{ value: 'unknown', label: 'Не указано' }, { value: 'incoming', label: 'Входящий' }, { value: 'outgoing', label: 'Исходящий' }]" />
                    <UiInput v-model="form.started_at" type="datetime-local" label="Дата и время" :error="edit.errors.value.started_at" />
                </div>
                <UiTextarea v-model="form.note" label="Примечание" :rows="2" :maxlength="2000" />
            </form>
            <template #footer>
                <UiButton @click="editOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="call-edit-form" variant="primary" :loading="edit.submitting.value">Сохранить</UiButton>
            </template>
        </UiModal>

        <ConfirmDialog
            v-model:open="excludeOpen"
            title="Исключить звонок из обработки?"
            message="Звонок не будет распознаваться и оцениваться. Запись и история сохраняются; вернуть звонок можно повторной обработкой."
            confirm-label="Исключить"
            danger
            require-reason
            :loading="busy === 'exclude'"
            :error="excludeError"
            @confirm="exclude"
        />
    </template>
</template>
