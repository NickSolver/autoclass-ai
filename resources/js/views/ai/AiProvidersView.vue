<script setup lang="ts">
import { aiApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ProviderConfig, ProviderKindCode, ProvidersOverview } from '@/api/types';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import { useForm } from '@/composables/useForm';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { formatDateTime } from '@/utils/format';
import ProviderFormModal from './ProviderFormModal.vue';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();
const { push } = useToasts();
const canManage = computed(() => auth.can('ai.manage'));
const tab = ref<'providers' | 'log'>(canManage.value ? 'providers' : 'log');

const overview = ref<ProvidersOverview | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    if (!canManage.value) {
        loading.value = false;
        return;
    }
    error.value = null;
    try {
        overview.value = await aiApi.overview();
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить провайдеров.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const kinds: { code: ProviderKindCode; title: string; description: string }[] = [
    { code: 'asr', title: 'Распознавание речи', description: 'Превращает запись в текст с таймкодами и говорящими.' },
    { code: 'llm', title: 'Оценка разговора', description: 'Оценивает расшифровку по чек-листам; ответ проверяется сервером.' },
];
const byKind = (kind: ProviderKindCode) => overview.value?.data.filter((p) => p.kind === kind) ?? [];
const roleLabels = { primary: 'Основной', fallback: 'Резервный', inactive: 'Не используется' } as const;

// --- Форма ---
const formOpen = ref(false);
const formKind = ref<ProviderKindCode>('llm');
const editing = ref<ProviderConfig | null>(null);
function openForm(kind: ProviderKindCode, provider: ProviderConfig | null = null): void {
    formKind.value = kind;
    editing.value = provider;
    formOpen.value = true;
}

// --- Действия ---
const busy = ref<string | null>(null);
async function run(key: string, action: () => Promise<unknown>, success: string): Promise<void> {
    busy.value = key;
    try {
        await action();
        push(success);
        await load();
    } catch (e) {
        push(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.', 'danger');
    } finally {
        busy.value = null;
    }
}

const roleMessages = { primary: '— основной провайдер.', fallback: '— резервный провайдер.', inactive: 'отключён.' } as const;

function setRole(p: ProviderConfig, role: keyof typeof roleMessages): Promise<void> {
    return run(`${role}-${p.id}`, () => aiApi.setRole(p.id, role), `«${p.name}» ${roleMessages[role]}`);
}

async function testProvider(p: ProviderConfig): Promise<void> {
    busy.value = `test-${p.id}`;
    try {
        const r = await aiApi.test(p.id);
        push(r.ok ? `«${p.name}»: ${r.message}` : `«${p.name}»: ${r.message}`, r.ok ? 'success' : 'danger');
        await load();
    } finally {
        busy.value = null;
    }
}

// --- Ключ API ---
const secretOpen = ref(false);
const secretFor = ref<ProviderConfig | null>(null);
const secretValue = ref('');
const secretForm = useForm();
function openSecret(p: ProviderConfig): void {
    secretFor.value = p;
    secretValue.value = '';
    secretForm.reset();
    secretOpen.value = true;
}
async function saveSecret(): Promise<void> {
    const r = await secretForm.submit(() => aiApi.setSecret(secretFor.value!.id, secretValue.value));
    secretValue.value = '';
    if (r) {
        secretOpen.value = false;
        push('Ключ сохранён. Он хранится зашифрованным и больше не показывается.');
        await load();
    }
}

// --- Разрешение внешней обработки ---
const externalOpen = ref(false);
const externalError = ref<string>();
async function toggleExternal(reason: string): Promise<void> {
    const enable = !overview.value?.external_processing.enabled;
    busy.value = 'external';
    externalError.value = undefined;
    try {
        await aiApi.setExternalProcessing(enable, reason);
        externalOpen.value = false;
        push(enable ? 'Передача записей внешним провайдерам разрешена.' : 'Передача запрещена; внешние провайдеры отключены.');
        await load();
    } catch (e) {
        externalError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        busy.value = null;
    }
}

// --- Журнал ---
const log = usePaginatedList(aiApi.calls, { status: '' });
const logColumns = [
    { key: 'started_at', label: 'Время', class: 'w-36' },
    { key: 'provider', label: 'Провайдер' },
    { key: 'operation', label: 'Операция', class: 'w-32' },
    { key: 'call_id', label: 'Звонок', class: 'w-20' },
    { key: 'volume', label: 'Объём', class: 'w-36' },
    { key: 'cost', label: 'Стоимость', class: 'w-24' },
    { key: 'status', label: 'Результат' },
];
const operationLabels: Record<string, string> = { transcribe: 'Распознавание', evaluate: 'Оценка', test: 'Проверка связи' };
</script>

<template>
    <PageHeader title="ИИ-провайдеры" description="Сервисы распознавания речи и оценки разговоров. Настройка общая для всей группы компаний." />

    <div class="mb-5 flex gap-1 border-b border-line">
        <button v-if="canManage" type="button" class="-mb-px border-b-2 px-4 py-2 text-[13px] font-medium" :class="tab === 'providers' ? 'border-brand-600 text-brand-700' : 'border-transparent text-muted hover:text-ink'" @click="tab = 'providers'">Провайдеры</button>
        <button v-if="auth.can('ai.logs.view')" type="button" class="-mb-px border-b-2 px-4 py-2 text-[13px] font-medium" :class="tab === 'log' ? 'border-brand-600 text-brand-700' : 'border-transparent text-muted hover:text-ink'" @click="tab = 'log'">Журнал вызовов</button>
    </div>

    <template v-if="tab === 'providers'">
        <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
        <UiAlert v-else-if="error" tone="danger">{{ error }}</UiAlert>
        <div v-else-if="overview" class="space-y-5">
            <UiCard title="Передача записей внешним провайдерам">
                <template #actions>
                    <UiBadge :tone="overview.external_processing.enabled ? 'warning' : 'success'">
                        {{ overview.external_processing.enabled ? 'Разрешена' : 'Запрещена' }}
                    </UiBadge>
                    <UiButton size="sm" :variant="overview.external_processing.enabled ? 'secondary' : 'primary'" @click="externalError = undefined; externalOpen = true">
                        {{ overview.external_processing.enabled ? 'Запретить' : 'Разрешить' }}
                    </UiButton>
                </template>
                <p class="text-[13px] text-muted">
                    Пока передача запрещена, внешний сервис (например, DeepSeek) нельзя сделать активным — записи и расшифровки
                    не покидают портал. Разрешайте только после юридической проверки: основания обработки, согласие на запись,
                    договор с провайдером, место хранения данных (ТЗ, разд. 12).
                </p>
                <p v-if="overview.external_processing.changed_at" class="mt-2 text-[12px] text-muted">
                    Изменено {{ formatDateTime(overview.external_processing.changed_at) }}. Причина: {{ overview.external_processing.reason }}
                </p>
            </UiCard>

            <UiCard v-for="kind in kinds" :key="kind.code" :title="kind.title" :description="kind.description" :padded="false">
                <template #actions>
                    <UiButton size="sm" variant="primary" @click="openForm(kind.code)">Добавить</UiButton>
                </template>
                <p v-if="!byKind(kind.code).length" class="px-5 py-4 text-[13px] text-muted">
                    Провайдер не настроен — звонки останавливаются на статусе «Ожидает {{ kind.code === 'asr' ? 'распознавания' : 'оценки' }}».
                </p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="p in byKind(kind.code)" :key="p.id" class="flex flex-wrap items-start gap-4 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 font-semibold">
                                {{ p.name }}
                                <UiBadge :tone="p.role === 'primary' ? 'success' : p.role === 'fallback' ? 'info' : 'neutral'">{{ roleLabels[p.role] }}</UiBadge>
                                <UiBadge v-if="p.external" tone="warning">внешний</UiBadge>
                                <UiBadge v-else>тестовый</UiBadge>
                            </p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                <template v-if="p.external">{{ p.endpoint }} · модель {{ p.model }} · ключ {{ p.secret_set ? `задан ${formatDateTime(p.secret_set_at)}` : 'не задан' }}</template>
                                <template v-else>режим: {{ overview.mock_scenarios[p.kind].find((s) => s.code === p.settings.mock_scenario)?.name }}</template>
                            </p>
                            <p v-if="p.stats" class="mt-1 text-[12px] text-muted">
                                За 30 дней: вызовов {{ p.stats.calls_30d }}, ошибок {{ p.stats.errors_30d }}<template v-if="p.stats.cost_30d !== null">, затраты {{ p.stats.cost_30d }} ₽</template><template v-if="p.stats.avg_duration_ms !== null">, в среднем {{ (p.stats.avg_duration_ms / 1000).toFixed(1) }} с</template>
                            </p>
                            <p v-if="p.stats?.last_error" class="mt-1 text-[12px] text-red-700">Последняя ошибка: {{ p.stats.last_error.error_message }}</p>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <UiButton size="sm" :loading="busy === `test-${p.id}`" @click="testProvider(p)">Проверить</UiButton>
                            <UiButton v-if="p.external" size="sm" @click="openSecret(p)">{{ p.secret_set ? 'Сменить ключ' : 'Задать ключ' }}</UiButton>
                            <UiButton size="sm" @click="openForm(kind.code, p)">Изменить</UiButton>
                            <UiButton v-if="p.role !== 'primary'" size="sm" variant="primary" :loading="busy === `primary-${p.id}`"
                                      @click="setRole(p, 'primary')">
                                Сделать основным
                            </UiButton>
                            <UiButton v-if="p.role !== 'fallback'" size="sm" variant="ghost" :loading="busy === `fallback-${p.id}`"
                                      @click="setRole(p, 'fallback')">
                                Резервный
                            </UiButton>
                            <UiButton v-if="p.role !== 'inactive'" size="sm" variant="ghost" :loading="busy === `inactive-${p.id}`"
                                      @click="setRole(p, 'inactive')">
                                Отключить
                            </UiButton>
                        </div>
                    </li>
                </ul>
            </UiCard>

            <UiAlert tone="info">
                У DeepSeek и других OpenAI-совместимых сервисов нет распознавания речи — для него нужен отдельный провайдер.
                Пока распознавание выполняет тестовый провайдер, внешней модели уходит только его синтетический диалог, а не речь из ваших записей.
            </UiAlert>
        </div>
    </template>

    <UiCard v-else :padded="false">
        <p class="border-b border-line px-5 py-3 text-[12px] text-muted">Только метаданные вызовов: содержимое записей, расшифровок и ответов моделей сюда не попадает.</p>
        <DataTable :columns="logColumns" :rows="log.rows.value" :loading="log.loading.value" :error="log.error.value" empty-title="Вызовов ещё не было" @retry="log.load">
            <template #cell-started_at="{ row }">{{ formatDateTime(row.started_at) }}</template>
            <template #cell-provider="{ row }">{{ row.provider ?? row.driver }}<span v-if="row.model" class="block text-[12px] text-muted">{{ row.model }}</span></template>
            <template #cell-operation="{ row }">{{ operationLabels[row.operation] ?? row.operation }}<span v-if="row.attempt > 1" class="block text-[12px] text-muted">попытка {{ row.attempt }}</span></template>
            <template #cell-call_id="{ row }"><RouterLink v-if="row.call_id" :to="`/calls/${row.call_id}`" class="text-brand-700 hover:underline">#{{ row.call_id }}</RouterLink><span v-else>—</span></template>
            <template #cell-volume="{ row }">
                <template v-if="row.audio_seconds !== null">{{ Math.round(row.audio_seconds) }} с аудио</template>
                <template v-else-if="row.tokens_in !== null">{{ row.tokens_in }} / {{ row.tokens_out }} ток.</template>
                <template v-else>—</template>
                <span class="block text-[12px] text-muted">{{ (row.duration_ms / 1000).toFixed(1) }} с</span>
            </template>
            <template #cell-cost="{ row }">{{ row.cost !== null ? `${row.cost} ₽` : '—' }}</template>
            <template #cell-status="{ row }">
                <UiBadge :tone="row.status === 'ok' ? 'success' : 'danger'">{{ row.status === 'ok' ? 'Успешно' : 'Ошибка' }}</UiBadge>
                <span v-if="row.error_message" class="mt-1 block max-w-md text-[12px] text-red-700">{{ row.error_message }}</span>
            </template>
        </DataTable>
        <UiPagination v-if="log.meta.value" :meta="log.meta.value" @change="log.goTo" />
    </UiCard>

    <ProviderFormModal v-if="overview" v-model:open="formOpen" :overview="overview" :kind="formKind" :provider="editing" @saved="load" />

    <UiModal v-model:open="secretOpen" :title="`Ключ API — ${secretFor?.name ?? ''}`">
        <form id="secret-form" class="space-y-4" @submit.prevent="saveSecret">
            <UiAlert tone="info">Ключ хранится в зашифрованном виде и больше не показывается — ни здесь, ни в журналах.</UiAlert>
            <UiAlert v-if="secretForm.formError.value" tone="danger">{{ secretForm.formError.value }}</UiAlert>
            <UiInput v-model="secretValue" type="password" label="Ключ API" autocomplete="off" required :error="secretForm.errors.value.secret" />
        </form>
        <template #footer>
            <UiButton @click="secretOpen = false">Отмена</UiButton>
            <UiButton type="submit" form="secret-form" variant="primary" :loading="secretForm.submitting.value" :disabled="secretValue.length < 8">Сохранить ключ</UiButton>
        </template>
    </UiModal>

    <ConfirmDialog
        v-if="overview"
        v-model:open="externalOpen"
        :title="overview.external_processing.enabled ? 'Запретить передачу внешним провайдерам?' : 'Разрешить передачу внешним провайдерам?'"
        :message="overview.external_processing.enabled
            ? 'Внешние провайдеры будут сразу отключены; звонки остановятся до включения внутреннего или тестового провайдера.'
            : 'Расшифровки звонков начнут передаваться выбранному внешнему сервису. Укажите основание: юридическая проверка, договор, согласия.'"
        :confirm-label="overview.external_processing.enabled ? 'Запретить' : 'Разрешить'"
        :danger="!overview.external_processing.enabled"
        require-reason
        :loading="busy === 'external'"
        :error="externalError"
        @confirm="toggleExternal"
    />
</template>
