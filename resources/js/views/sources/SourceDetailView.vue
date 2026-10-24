<script setup lang="ts">
import { sourcesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { PageMeta, SourceConnectionDetail, SourceEventItem } from '@/api/types';
import SourceSettingsFields from '@/components/sources/SourceSettingsFields.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { formatDateTime } from '@/utils/format';
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps<{ id: number }>();
const { push } = useToasts();
const conn = ref<SourceConnectionDetail | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const form = reactive({ name: '', sync_interval_minutes: 15 as number | string | null, settings: {} as Record<string, string | number | null> });
const save = useForm();
const action = useForm();
const secret = ref('');
const webhookSecret = ref<string | null>(null);
let poll: ReturnType<typeof setTimeout> | undefined;

function fill(c: SourceConnectionDetail): void {
    conn.value = c;
    Object.assign(form, { name: c.name, sync_interval_minutes: c.sync_interval_minutes, settings: { ...c.settings } });
}

async function load(): Promise<void> {
    try {
        fill(await sourcesApi.show(props.id));
        await loadEvents();
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить подключение.';
    } finally {
        loading.value = false;
    }
    // Пока идёт синхронизация — обновляем журнал.
    clearTimeout(poll);
    if (conn.value?.runs[0]?.status === 'running') poll = setTimeout(load, 3000);
}
onMounted(load);
onBeforeUnmount(() => clearTimeout(poll));

async function saveSettings(): Promise<void> {
    const c = conn.value;
    if (!c) return;
    const saved = await save.submit(() => sourcesApi.update(c.id, { lock_version: c.lock_version, name: form.name, sync_interval_minutes: Number(form.sync_interval_minutes), settings: form.settings }));
    if (saved) {
        fill(saved);
        push('Настройки сохранены.');
    }
}

async function toggle(): Promise<void> {
    const c = conn.value;
    if (!c) return;
    const saved = await action.submit(() => sourcesApi.update(c.id, { lock_version: c.lock_version, is_enabled: !c.is_enabled }));
    if (saved) {
        fill(saved);
        push(saved.is_enabled ? 'Подключение включено.' : 'Подключение выключено.');
    }
}

async function test(): Promise<void> {
    const c = conn.value;
    if (!c) return;
    const res = await action.submit(() => sourcesApi.test(c.id));
    if (res) push(res.message, res.ok ? 'success' : 'danger');
}

async function sync(): Promise<void> {
    const c = conn.value;
    if (!c) return;
    const res = await action.submit(() => sourcesApi.sync(c.id));
    if (res) {
        push(res.message, 'info');
        await load();
    }
}

async function saveSecret(clear = false): Promise<void> {
    const c = conn.value;
    if (!c) return;
    const saved = await action.submit(() => sourcesApi.setSecret(c.id, clear ? null : secret.value));
    if (saved) {
        fill(saved);
        secret.value = '';
        push(clear ? 'Ключ удалён.' : 'Ключ сохранён.');
    }
}

async function regenerate(): Promise<void> {
    const c = conn.value;
    if (!c || (c.webhook?.has_secret && !window.confirm('Прежний секрет подписи перестанет действовать. Продолжить?'))) return;
    const res = await action.submit(() => sourcesApi.regenerateWebhookSecret(c.id));
    if (res) {
        fill(res.connection);
        webhookSecret.value = res.webhook_secret;
    }
}

// Журнал событий.
const events = ref<SourceEventItem[]>([]);
const eventsMeta = ref<PageMeta | null>(null);
const eventStatus = ref<string | null>(null);
const eventPage = ref(1);
async function loadEvents(): Promise<void> {
    const res = await sourcesApi.events(props.id, { status: eventStatus.value, page: eventPage.value });
    events.value = res.data;
    eventsMeta.value = res.meta;
}
watch(eventStatus, () => {
    eventPage.value = 1;
    loadEvents();
});
watch(eventPage, loadEvents);

const runStatus: Record<string, string> = { running: 'идёт', succeeded: 'успешно', partial: 'с ошибками', failed: 'ошибка' };
const runTone: Record<string, 'info' | 'success' | 'warning' | 'danger'> = { running: 'info', succeeded: 'success', partial: 'warning', failed: 'danger' };
const triggerLabels: Record<string, string> = { schedule: 'по расписанию', manual: 'вручную', webhook: 'событие' };
const eventLabels: Record<string, string> = { pending: 'в очереди', imported: 'принят', duplicate: 'дубль', without_recording: 'без записи', failed: 'ошибка' };
const eventTone: Record<string, 'info' | 'success' | 'neutral' | 'warning' | 'danger'> = { pending: 'info', imported: 'success', duplicate: 'neutral', without_recording: 'warning', failed: 'danger' };
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="loadError || !conn" tone="danger" title="Подключение недоступно">{{ loadError }}</UiAlert>
    <template v-else>
        <PageHeader :title="conn.name" :description="`${conn.driver_name} · ${conn.company.name}`">
            <template #actions>
                <UiBadge :tone="conn.is_enabled ? 'success' : 'neutral'">{{ conn.is_enabled ? 'включено' : 'выключено' }}</UiBadge>
                <UiButton :loading="action.submitting.value" @click="test">Проверить связь</UiButton>
                <UiButton v-if="conn.capabilities?.pull" :loading="action.submitting.value" @click="sync">Синхронизировать</UiButton>
                <UiButton :variant="conn.is_enabled ? 'secondary' : 'primary'" @click="toggle">{{ conn.is_enabled ? 'Выключить' : 'Включить' }}</UiButton>
            </template>
        </PageHeader>

        <UiAlert v-if="action.formError.value || Object.keys(action.errors.value).length" tone="danger" class="mb-4">
            {{ action.formError.value ?? Object.values(action.errors.value)[0] }}
        </UiAlert>
        <UiAlert v-if="conn.driver === 'mock'" tone="warning" class="mb-4">Тестовый источник: звонки синтетические, записи — тоны без речи. Не для реальной работы.</UiAlert>
        <UiAlert v-if="conn.last_error" tone="danger" title="Последняя синхронизация не выполнена" class="mb-4">{{ conn.last_error }}</UiAlert>

        <div class="grid gap-5 lg:grid-cols-2">
            <UiCard title="Настройки">
                <form class="space-y-4" @submit.prevent="saveSettings">
                    <UiAlert v-if="save.formError.value || save.errors.value.lock_version" tone="danger">{{ save.errors.value.lock_version ?? save.formError.value }}</UiAlert>
                    <UiInput v-model="form.name" label="Название" required :error="save.errors.value.name" />
                    <UiInput v-if="conn.capabilities?.pull" v-model="form.sync_interval_minutes" type="number" label="Интервал синхронизации, мин" hint="От 5 до 1440." :error="save.errors.value.sync_interval_minutes" />
                    <SourceSettingsFields v-model="form.settings" :fields="conn.fields" :errors="save.errors.value" />
                    <UiButton type="submit" variant="primary" :loading="save.submitting.value">Сохранить</UiButton>
                </form>
            </UiCard>

            <div class="space-y-5">
                <UiCard title="Ключ доступа к источнику" description="Хранится зашифрованным, обратно не показывается. Изменение — с подтверждением кодом.">
                    <p class="mb-3 text-[13px]">{{ conn.has_secret ? `Задан ${formatDateTime(conn.secret_set_at)}` : 'Не задан' }}</p>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="saveSecret()">
                        <UiInput v-model="secret" type="password" label="Новый ключ" autocomplete="off" class="min-w-56 flex-1" />
                        <UiButton type="submit" :disabled="!secret">Сохранить</UiButton>
                        <UiButton v-if="conn.has_secret" variant="ghost" @click="saveSecret(true)">Удалить</UiButton>
                    </form>
                </UiCard>

                <UiCard v-if="conn.webhook" title="Приём событий (webhook)" description="Источник отправляет события на этот адрес; тело подписывается секретом. Принимаются только у включённого подключения.">
                    <p class="mb-1 text-[12px] text-muted">Адрес</p>
                    <code class="block break-all rounded bg-gray-50 px-2 py-1.5 text-[12px]" data-testid="webhook-url">{{ conn.webhook.url }}</code>
                    <UiAlert v-if="webhookSecret" tone="warning" class="mt-3" title="Секрет подписи — показывается один раз">
                        <code class="break-all" data-testid="webhook-secret">{{ webhookSecret }}</code>
                    </UiAlert>
                    <UiButton class="mt-3" size="sm" @click="regenerate">{{ conn.webhook.has_secret ? 'Сменить секрет подписи' : 'Создать секрет подписи' }}</UiButton>
                </UiCard>
            </div>
        </div>

        <UiCard title="Синхронизации" :padded="false" class="mt-5">
            <p v-if="!conn.runs.length" class="px-5 py-4 text-[13px] text-muted">Синхронизаций ещё не было.</p>
            <table v-else class="w-full text-left text-[13px]" data-testid="sync-runs">
                <thead>
                    <tr class="border-b border-line text-[12px] text-muted">
                        <th class="px-5 py-2 font-medium">Начало</th>
                        <th class="px-3 py-2 font-medium">Запуск</th>
                        <th class="px-3 py-2 font-medium">Состояние</th>
                        <th class="px-3 py-2 text-right font-medium">Получено</th>
                        <th class="px-3 py-2 text-right font-medium">Новых</th>
                        <th class="px-3 py-2 text-right font-medium">Дублей</th>
                        <th class="px-3 py-2 text-right font-medium">Без записи</th>
                        <th class="px-5 py-2 text-right font-medium">Ошибок</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in conn.runs" :key="r.id" class="border-b border-line align-top last:border-0">
                        <td class="px-5 py-2">{{ formatDateTime(r.started_at) }}</td>
                        <td class="px-3 py-2">{{ triggerLabels[r.trigger] }}</td>
                        <td class="px-3 py-2">
                            <UiBadge :tone="runTone[r.status]">{{ runStatus[r.status] }}</UiBadge>
                            <span v-if="r.error_message" class="block text-[12px] text-red-700">{{ r.error_message }}</span>
                        </td>
                        <td class="px-3 py-2 text-right">{{ r.fetched }}</td>
                        <td class="px-3 py-2 text-right">{{ r.created }}</td>
                        <td class="px-3 py-2 text-right">{{ r.duplicates }}</td>
                        <td class="px-3 py-2 text-right">{{ r.without_recording }}</td>
                        <td class="px-5 py-2 text-right">{{ r.failed }}</td>
                    </tr>
                </tbody>
            </table>
        </UiCard>

        <UiCard title="События" :padded="false" class="mt-5">
            <div class="border-b border-line px-5 py-3">
                <UiSelect
                    v-model="eventStatus"
                    placeholder="Все события"
                    :options="Object.entries(eventLabels).map(([value, label]) => ({ value, label }))"
                    aria-label="Состояние события"
                    class="w-56"
                />
            </div>
            <p v-if="!events.length" class="px-5 py-4 text-[13px] text-muted">Событий нет.</p>
            <table v-else class="w-full text-left text-[13px]" data-testid="source-events">
                <tbody>
                    <tr v-for="e in events" :key="e.id" class="border-b border-line align-top last:border-0">
                        <td class="w-44 px-5 py-2">{{ formatDateTime(e.received_at) }}</td>
                        <td class="w-20 px-3 py-2 text-muted">{{ e.channel === 'push' ? 'событие' : 'выгрузка' }}</td>
                        <td class="px-3 py-2 font-mono text-[12px]">{{ e.external_id ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <UiBadge :tone="eventTone[e.status]">{{ eventLabels[e.status] }}</UiBadge>
                            <span v-if="e.error_message" class="block text-[12px] text-muted">{{ e.error_message }}</span>
                        </td>
                        <td class="w-32 px-5 py-2 text-right">
                            <RouterLink v-if="e.call_id" :to="`/calls/${e.call_id}`" class="text-brand-700 hover:underline">Звонок #{{ e.call_id }}</RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
            <UiPagination v-if="eventsMeta && eventsMeta.last_page > 1" :meta="eventsMeta" @change="(p) => (eventPage = p)" />
        </UiCard>
    </template>
</template>
