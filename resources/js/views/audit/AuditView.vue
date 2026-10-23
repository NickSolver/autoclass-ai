<script setup lang="ts">
import { auditApi } from '@/api';
import type { AuditEvent } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { auditActionLabel, formatDateTime } from '@/utils/format';
import { ref } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();
const list = usePaginatedList(auditApi.list, {
    action: '',
    subject_type: (route.query.subject_type as string | undefined) ?? '',
    subject_id: (route.query.subject_id as string | undefined) ?? '',
    from: '',
    to: '',
});

const actionOptions = [
    { value: '', label: 'Все события' },
    { value: 'auth.', label: 'Вход, выход, пароли' },
    { value: 'user.', label: 'Пользователи' },
    { value: 'role', label: 'Роли' },
    { value: 'company', label: 'Компании и группа' },
    { value: 'dealership.', label: 'Дилерские центры' },
    { value: 'department.', label: 'Отделы' },
    { value: 'employee', label: 'Сотрудники и назначения' },
];

const subjectLabels: Record<string, string> = {
    user: 'Пользователь', role: 'Роль', company: 'Компания', company_group: 'Группа', dealership: 'Дилерский центр',
    department: 'Отдел', employee: 'Сотрудник', employee_assignment: 'Назначение',
};

const columns = [
    { key: 'occurred_at', label: 'Время', class: 'w-36' },
    { key: 'action', label: 'Событие' },
    { key: 'subject', label: 'Объект' },
    { key: 'actor', label: 'Кто', class: 'w-40' },
    { key: 'details', label: '', class: 'w-24 text-right' },
];

const selected = ref<AuditEvent | null>(null);
const detailsOpen = ref(false);

function show(e: AuditEvent): void {
    selected.value = e;
    detailsOpen.value = true;
}

function isDiff(v: unknown): v is { old: unknown; new: unknown } {
    return typeof v === 'object' && v !== null && 'old' in v && 'new' in v;
}

function display(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
}
</script>

<template>
    <PageHeader title="Журнал аудита" description="Неизменяемая история действий в вашей области. Значения паролей и секретов в журнал не попадают." />
    <UiCard :padded="false">
        <div class="flex flex-wrap items-end gap-3 border-b border-line p-4">
            <UiSelect v-model="list.filters.action" :options="actionOptions" class="w-56" />
            <UiInput v-model="list.filters.from" type="date" label="С" class="w-40" />
            <UiInput v-model="list.filters.to" type="date" label="По" class="w-40" />
            <UiButton v-if="list.filters.subject_id" variant="ghost" @click="list.filters.subject_type = ''; list.filters.subject_id = ''">
                Сбросить фильтр по объекту ×
            </UiButton>
        </div>
        <DataTable :columns="columns" :rows="list.rows.value" :loading="list.loading.value" :error="list.error.value" empty-title="Событий нет" @retry="list.load">
            <template #cell-occurred_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.occurred_at) }}</span></template>
            <template #cell-action="{ row }">
                {{ auditActionLabel(row.action) }}
                <code class="block text-[11px] text-muted">{{ row.action }}</code>
            </template>
            <template #cell-subject="{ row }">
                <template v-if="row.subject_type">
                    <span class="text-muted">{{ subjectLabels[row.subject_type] ?? row.subject_type }}:</span> {{ row.subject_label ?? `#${row.subject_id}` }}
                </template>
                <span v-else class="text-muted">—</span>
            </template>
            <template #cell-actor="{ row }">{{ row.actor?.login ?? 'система' }}</template>
            <template #cell-details="{ row }">
                <UiButton v-if="row.changes || row.reason" size="sm" variant="ghost" @click="show(row)">Детали</UiButton>
            </template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>

    <UiModal v-model:open="detailsOpen" :title="selected ? auditActionLabel(selected.action) : ''" size="lg">
        <template v-if="selected">
            <dl class="mb-4 grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5 text-[13px]">
                <dt class="text-muted">Время</dt><dd>{{ formatDateTime(selected.occurred_at) }}</dd>
                <dt class="text-muted">Кто</dt><dd>{{ selected.actor?.login ?? 'система' }}</dd>
                <dt class="text-muted">IP</dt><dd>{{ selected.ip_address ?? '—' }}</dd>
                <template v-if="selected.reason"><dt class="text-muted">Причина</dt><dd>{{ selected.reason }}</dd></template>
            </dl>
            <div v-if="selected.changes" class="overflow-x-auto">
                <table class="w-full text-left text-[13px]">
                    <thead><tr class="border-b border-line text-[12px] text-muted"><th class="py-1.5 pr-3">Поле</th><th class="py-1.5 pr-3">Было</th><th class="py-1.5">Стало</th></tr></thead>
                    <tbody>
                        <tr v-for="(value, field) in selected.changes" :key="field" class="border-b border-line last:border-0">
                            <td class="py-1.5 pr-3 font-mono text-[12px]">{{ field }}</td>
                            <template v-if="isDiff(value)">
                                <td class="py-1.5 pr-3 text-muted">{{ display(value.old) }}</td>
                                <td class="py-1.5">{{ display(value.new) }}</td>
                            </template>
                            <td v-else colspan="2" class="py-1.5">{{ display(value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </UiModal>
</template>
