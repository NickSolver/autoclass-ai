<script setup lang="ts">
import { callsApi, employeesApi, type UploadMeta } from '@/api';
import { ApiError } from '@/api/http';
import type { Employee, UploadSettings } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useAuthStore } from '@/stores/auth';
import { formatBytes } from '@/utils/format';
import { computed, onMounted, reactive, ref, watch } from 'vue';

type ItemStatus = 'waiting' | 'uploading' | 'done' | 'duplicate' | 'error';

interface QueueItem {
    /** Ключ идемпотентности: один на файл, повторно используется при повторе — второй звонок не создаётся. */
    key: string;
    file: File;
    status: ItemStatus;
    progress: number;
    message: string | null;
    callId: number | null;
    duplicateId: number | null;
    confirmDuplicate: boolean;
    /** Файл уже отправлялся на сервер — повтор безопасен благодаря ключу. */
    attempted: boolean;
}

const auth = useAuthStore();
const settings = ref<UploadSettings | null>(null);
const settingsError = ref<string | null>(null);
const items = ref<QueueItem[]>([]);
const dragOver = ref(false);
const running = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

const unit = ref<OrgUnitValue | null>(null);
const employees = ref<Employee[]>([]);
const meta = reactive({ employee_id: null as number | null, direction: 'unknown' as string, started_at: '', note: '' });

onMounted(async () => {
    try {
        settings.value = await callsApi.uploadSettings();
    } catch (e) {
        settingsError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить настройки.';
    }
});

// Сотрудники выбранного подразделения (если есть право видеть сотрудников).
watch(unit, async (value) => {
    meta.employee_id = null;
    employees.value = [];
    if (!value || !auth.can('employees.view')) return;
    const filter = { [`${value.type}_id`]: value.id, is_active: 1, per_page: 100 };
    employees.value = (await employeesApi.list(filter).catch(() => ({ data: [] as Employee[] }))).data;
});

const counts = computed(() => ({
    total: items.value.length,
    pending: items.value.filter((i) => i.status === 'waiting' || i.status === 'uploading').length,
    done: items.value.filter((i) => i.status === 'done').length,
    attention: items.value.filter((i) => i.status === 'error' || i.status === 'duplicate').length,
}));

const canStart = computed(() => !!unit.value && !running.value && items.value.some((i) => i.status === 'waiting'));

function addFiles(list: FileList | null): void {
    if (!list || !settings.value) return;
    const allowed = settings.value.formats;
    const maxBytes = settings.value.max_file_size_mb * 1024 * 1024;
    for (const file of Array.from(list)) {
        if (items.value.length >= settings.value.max_files_per_batch) break;
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
        // Предварительная проверка для удобства; окончательная — на сервере по содержимому файла.
        const problem = !allowed.includes(ext)
            ? `Формат .${ext} не поддерживается.`
            : file.size > maxBytes
              ? `Больше ${settings.value.max_file_size_mb} МБ.`
              : null;
        items.value.push({
            key: crypto.randomUUID(),
            file,
            status: problem ? 'error' : 'waiting',
            progress: 0,
            message: problem,
            callId: null,
            duplicateId: null,
            confirmDuplicate: false,
            attempted: false,
        });
    }
}

function onDrop(e: DragEvent): void {
    dragOver.value = false;
    addFiles(e.dataTransfer?.files ?? null);
}

function remove(item: QueueItem): void {
    items.value = items.value.filter((i) => i !== item);
}

function uploadMeta(): UploadMeta {
    return {
        [`${unit.value!.type}_id`]: unit.value!.id,
        employee_id: meta.employee_id,
        direction: meta.direction,
        started_at: meta.started_at ? new Date(meta.started_at).toISOString() : null,
        started_at_tz: meta.started_at ? Intl.DateTimeFormat().resolvedOptions().timeZone : null,
        note: meta.note || null,
    };
}

async function uploadOne(item: QueueItem, data: UploadMeta): Promise<void> {
    item.status = 'uploading';
    item.attempted = true;
    item.progress = 0;
    item.message = null;
    try {
        const call = await callsApi.upload(item.file, data, item.key, item.confirmDuplicate, (p) => (item.progress = p));
        item.status = 'done';
        item.callId = call.id;
        item.message = call.status.label;
    } catch (e) {
        if (e instanceof ApiError && e.code === 'duplicate_audio') {
            item.status = 'duplicate';
            item.duplicateId = ((e.data.duplicate as { id?: number | null } | undefined)?.id ?? null);
            item.message = e.message;
        } else {
            item.status = 'error';
            item.message = e instanceof ApiError ? (e.field('file') ?? Object.values(e.errors)[0]?.[0] ?? e.message) : 'Не удалось загрузить.';
        }
    }
}

/** Загрузка очереди: два файла параллельно, остальные ждут. */
async function start(): Promise<void> {
    if (!unit.value) return;
    running.value = true;
    const data = uploadMeta();
    const queue = items.value.filter((i) => i.status === 'waiting');
    const worker = async () => {
        for (let item = queue.shift(); item; item = queue.shift()) await uploadOne(item, data);
    };
    await Promise.all([worker(), worker()]);
    running.value = false;
}

function confirmDuplicate(item: QueueItem): void {
    item.confirmDuplicate = true;
    item.status = 'waiting';
    void start();
}

function retry(item: QueueItem): void {
    item.status = 'waiting';
    void start();
}

function clearDone(): void {
    items.value = items.value.filter((i) => i.status !== 'done');
}

const statusTone: Record<ItemStatus, 'neutral' | 'info' | 'success' | 'warning' | 'danger'> = {
    waiting: 'neutral', uploading: 'info', done: 'success', duplicate: 'warning', error: 'danger',
};
const statusLabel: Record<ItemStatus, string> = {
    waiting: 'Ожидает', uploading: 'Загрузка', done: 'Загружен', duplicate: 'Возможный дубль', error: 'Ошибка',
};
</script>

<template>
    <PageHeader title="Загрузка звонков" description="Записи сохраняются в закрытом хранилище и проходят подготовку к анализу.">
        <template #actions>
            <RouterLink to="/calls"><UiButton>К списку звонков</UiButton></RouterLink>
        </template>
    </PageHeader>

    <UiAlert v-if="settingsError" tone="danger" class="mb-5">{{ settingsError }}</UiAlert>

    <div class="grid gap-5 xl:grid-cols-[1fr_20rem]">
        <div class="space-y-5">
            <!-- Зона загрузки -->
            <UiCard :padded="false">
                <div
                    class="m-4 flex flex-col items-center gap-4 rounded-xl border-2 border-dashed px-6 py-8 text-center transition-colors sm:flex-row sm:text-left"
                    :class="dragOver ? 'border-brand-500 bg-brand-50' : 'border-brand-200 bg-brand-50/40'"
                    @dragover.prevent="dragOver = true"
                    @dragleave.prevent="dragOver = false"
                    @drop.prevent="onDrop"
                >
                    <span class="grid size-16 shrink-0 place-items-center rounded-full bg-white text-brand-600 shadow-sm"><AppIcon name="upload" :size="30" /></span>
                    <div class="flex-1">
                        <p class="text-[17px] font-semibold text-ink">Перетащите файлы сюда</p>
                        <p class="text-[13px] text-muted">или нажмите «Выбрать файлы»</p>
                        <p v-if="settings" class="mt-2 text-[12px] text-muted">
                            Поддерживаются: {{ settings.formats.map((f) => f.toUpperCase()).join(', ') }} (до {{ settings.max_file_size_mb }} МБ на файл,
                            до {{ settings.max_files_per_batch }} файлов за раз, длительность до {{ settings.max_duration_minutes }} мин)
                        </p>
                    </div>
                    <div>
                        <input ref="fileInput" type="file" multiple accept=".mp3,.wav,.m4a,audio/mpeg,audio/wav,audio/mp4" class="hidden" @change="addFiles(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''" />
                        <UiButton variant="primary" :disabled="!settings" @click="fileInput?.click()">Выбрать файлы</UiButton>
                    </div>
                </div>
            </UiCard>

            <!-- Параметры -->
            <UiCard title="Параметры" description="Применяются ко всем файлам в очереди. Сотрудника и дату можно уточнить позже в карточке звонка.">
                <div class="grid gap-4 md:grid-cols-2">
                    <OrgUnitSelect v-model="unit" permission="calls.upload" :types="['company', 'dealership', 'department']" label="Подразделение" required />
                    <UiSelect
                        v-model="meta.employee_id"
                        label="Сотрудник"
                        placeholder="Не установлен — сопоставить позже"
                        :disabled="!unit || !auth.can('employees.view')"
                        :options="employees.map((e) => ({ value: e.id, label: e.full_name }))"
                        :hint="!auth.can('employees.view') ? 'Нет права просматривать сотрудников — звонок попадёт в «Требует сопоставления».' : undefined"
                    />
                    <UiSelect
                        v-model="meta.direction"
                        label="Направление"
                        :options="[{ value: 'unknown', label: 'Не указано' }, { value: 'incoming', label: 'Входящий' }, { value: 'outgoing', label: 'Исходящий' }]"
                    />
                    <UiInput v-model="meta.started_at" type="datetime-local" label="Дата и время звонка" hint="Если не указано — используется дата загрузки." />
                    <UiTextarea v-model="meta.note" label="Примечание" :rows="2" :maxlength="2000" class="md:col-span-2" />
                </div>
            </UiCard>

            <!-- Очередь -->
            <UiCard title="Файлы" :description="items.length ? undefined : 'Файлы ещё не выбраны.'" :padded="false">
                <template #actions>
                    <UiButton v-if="counts.done" size="sm" variant="ghost" @click="clearDone">Убрать загруженные</UiButton>
                    <UiButton size="sm" variant="primary" :loading="running" :disabled="!canStart" @click="start">
                        Загрузить{{ counts.pending ? ` (${counts.pending})` : '' }}
                    </UiButton>
                </template>
                <p v-if="items.length && !unit" class="px-5 pt-3 text-[13px] text-amber-800">Выберите подразделение в параметрах.</p>
                <ul v-if="items.length" class="divide-y divide-line">
                    <li v-for="item in items" :key="item.key" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <AppIcon name="phone" class="text-muted" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ item.file.name }}</p>
                            <p class="text-[12px] text-muted">{{ formatBytes(item.file.size) }}<template v-if="item.message"> · {{ item.message }}</template></p>
                            <div v-if="item.status === 'uploading'" class="mt-1.5 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-brand-50" role="progressbar" :aria-valuenow="item.progress">
                                <div class="h-full rounded-full bg-brand-500 transition-all" :style="{ width: `${item.progress}%` }"></div>
                            </div>
                        </div>
                        <UiBadge :tone="statusTone[item.status]">{{ item.status === 'uploading' ? `${item.progress}%` : statusLabel[item.status] }}</UiBadge>
                        <RouterLink v-if="item.callId" :to="`/calls/${item.callId}`" class="text-[13px] text-brand-700 hover:underline">Звонок #{{ item.callId }}</RouterLink>
                        <template v-if="item.status === 'duplicate'">
                            <RouterLink v-if="item.duplicateId" :to="`/calls/${item.duplicateId}`" class="text-[13px] text-brand-700 hover:underline">Открыть #{{ item.duplicateId }}</RouterLink>
                            <UiButton size="sm" @click="confirmDuplicate(item)">Загрузить всё равно</UiButton>
                        </template>
                        <UiButton v-if="item.status === 'error' && item.attempted" size="sm" @click="retry(item)">Повторить</UiButton>
                        <button v-if="item.status !== 'uploading' && item.status !== 'done'" type="button" class="rounded p-1 text-muted hover:bg-gray-100" aria-label="Убрать из очереди" @click="remove(item)">
                            <AppIcon name="x" :size="16" />
                        </button>
                    </li>
                </ul>
            </UiCard>
        </div>

        <!-- Сводка по текущей загрузке -->
        <div class="space-y-3">
            <StatTile label="Файлов в очереди" :value="counts.total" icon="log" />
            <StatTile label="Загружается / ожидает" :value="counts.pending" icon="clock" />
            <StatTile label="Загружено" :value="counts.done" icon="check" tone="green" />
            <StatTile label="Требует внимания" :value="counts.attention" icon="alert" :tone="counts.attention ? 'red' : 'blue'" />
        </div>
    </div>
</template>
