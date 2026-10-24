<script setup lang="ts">
import { sourcesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { SourceConnectionSummary, SourceDriver } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import SourceSettingsFields from '@/components/sources/SourceSettingsFields.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import { useForm } from '@/composables/useForm';
import { formatDateTime } from '@/utils/format';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const items = ref<SourceConnectionSummary[]>([]);
const drivers = ref<SourceDriver[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const [list, catalog] = await Promise.all([sourcesApi.list(), sourcesApi.drivers()]);
        items.value = list.data;
        drivers.value = catalog.data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить источники.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

// Подключение.
const createOpen = ref(false);
const form = reactive({ driver: '', name: '', unit: null as OrgUnitValue | null, settings: {} as Record<string, string | number | null> });
const { submitting, errors, formError, submit, reset } = useForm();
const driver = computed(() => drivers.value.find((d) => d.code === form.driver) ?? null);

function openCreate(code: string): void {
    const d = drivers.value.find((x) => x.code === code);
    if (!d?.available) return;
    reset();
    Object.assign(form, { driver: code, name: d.name, unit: null, settings: code === 'mock' ? { scenario: 'normal', calls_per_batch: 3, total_calls: 10, extensions: '' } : {} });
    createOpen.value = true;
}

async function create(): Promise<void> {
    const unit = form.unit;
    const created = await submit(() =>
        sourcesApi.create({
            driver: form.driver,
            name: form.name,
            company_id: unit?.type === 'company' ? unit.id : null,
            dealership_id: unit?.type === 'dealership' ? unit.id : null,
            department_id: unit?.type === 'department' ? unit.id : null,
            settings: form.settings,
        }),
    );
    if (created) await router.push(`/sources/${created.id}`);
}

const columns = [
    { key: 'name', label: 'Подключение' },
    { key: 'company', label: 'Компания' },
    { key: 'state', label: 'Состояние', class: 'w-40' },
    { key: 'sync', label: 'Последняя синхронизация', class: 'w-64' },
];
</script>

<template>
    <PageHeader title="Источники звонков" description="Подключаемые модули: звонки и записи поступают автоматически, без дублей (источник + внешний ID). Реальные адаптеры подключаются после получения документации и тестового доступа." />

    <div class="mb-5 grid gap-3 md:grid-cols-3">
        <UiCard v-for="d in drivers" :key="d.code" :title="d.name">
            <p class="mb-3 text-[13px] text-muted">{{ d.description }}</p>
            <UiBadge v-if="!d.available" tone="warning">ждёт документации</UiBadge>
            <UiButton v-else size="sm" variant="primary" @click="openCreate(d.code)">Подключить</UiButton>
        </UiCard>
    </div>

    <UiCard :padded="false">
        <DataTable :columns="columns" :rows="items" :loading="loading" :error="error" empty-title="Подключений нет" empty-description="Звонки поступают только через ручную загрузку." :row-link="(r) => `/sources/${r.id}`" @retry="load">
            <template #cell-name="{ row }">
                <RouterLink :to="`/sources/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ row.name }}</RouterLink>
                <span class="block text-[12px] text-muted">{{ row.driver_name }}</span>
            </template>
            <template #cell-company="{ row }">{{ row.company.name }}</template>
            <template #cell-state="{ row }">
                <UiBadge :tone="row.is_enabled ? 'success' : 'neutral'">{{ row.is_enabled ? 'включено' : 'выключено' }}</UiBadge>
            </template>
            <template #cell-sync="{ row }">
                {{ row.last_sync_at ? formatDateTime(row.last_sync_at) : 'не выполнялась' }}
                <span v-if="row.last_error" class="block text-[12px] text-red-700">{{ row.last_error }}</span>
            </template>
        </DataTable>
    </UiCard>

    <UiModal v-model:open="createOpen" :title="`Подключить: ${driver?.name ?? ''}`">
        <form id="source-create" class="space-y-4" @submit.prevent="create">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <UiInput v-model="form.name" label="Название" required :error="errors.name" />
            <OrgUnitSelect
                v-model="form.unit"
                permission="integrations.manage"
                :types="['company', 'dealership', 'department']"
                label="Куда поступают звонки"
                required
                hint="Компания обязательна. Если указан отдел, все звонки идут в него; иначе — в отдел сотрудника по добавочному."
                :error="errors.company_id || errors.department_id"
            />
            <SourceSettingsFields v-if="driver" v-model="form.settings" :fields="driver.fields" :errors="errors" />
            <p class="text-[12px] text-muted">Подключение создаётся выключенным: проверьте связь и включите его на странице подключения.</p>
        </form>
        <template #footer>
            <UiButton @click="createOpen = false">Отмена</UiButton>
            <UiButton type="submit" form="source-create" variant="primary" :loading="submitting">Создать</UiButton>
        </template>
    </UiModal>
</template>
