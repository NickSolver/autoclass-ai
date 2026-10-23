<script setup lang="ts">
import { orgApi } from '@/api';
import type { Company, CompanyGroup } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useForm } from '@/composables/useForm';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { computed, onMounted, reactive, ref } from 'vue';

const auth = useAuthStore();
const { push } = useToasts();
const list = usePaginatedList(orgApi.companies.list, { q: '', is_active: '' as '' | '1' | '0' });
const columns = [
    { key: 'name', label: 'Компания' },
    { key: 'code', label: 'Код', class: 'w-32' },
    { key: 'units', label: 'Центров / отделов', class: 'w-40' },
    { key: 'status', label: 'Статус', class: 'w-28' },
    { key: 'actions', label: '', class: 'w-24 text-right' },
];

// Создавать компании можно только на уровне группы: кнопка видна, если такие группы есть.
const groups = ref<CompanyGroup[]>([]);
const manageableGroups = computed(() => groups.value.filter((g) => g.can.update));
onMounted(async () => {
    if (auth.can('organization.manage')) groups.value = (await orgApi.groups.list().catch(() => ({ data: [] }))).data;
});

const modalOpen = ref(false);
const editing = ref<Company | null>(null);
const form = reactive({ group_id: null as number | null, code: '', name: '', legal_name: '', is_active: true });
const { submitting, errors, formError, submit, reset } = useForm();

function openCreate(): void {
    editing.value = null;
    Object.assign(form, { group_id: manageableGroups.value[0]?.id ?? null, code: '', name: '', legal_name: '', is_active: true });
    reset();
    modalOpen.value = true;
}

function openEdit(c: Company): void {
    editing.value = c;
    Object.assign(form, { group_id: c.group_id, code: c.code, name: c.name, legal_name: c.legal_name ?? '', is_active: c.is_active });
    reset();
    modalOpen.value = true;
}

async function save(): Promise<void> {
    const body = { code: form.code, name: form.name, legal_name: form.legal_name || null, is_active: form.is_active };
    const result = await submit(() =>
        editing.value ? orgApi.companies.update(editing.value.id, body) : orgApi.companies.create({ ...body, group_id: form.group_id }),
    );
    if (result) {
        modalOpen.value = false;
        push(editing.value ? 'Компания сохранена.' : 'Компания создана.');
        await list.load();
    }
}
</script>

<template>
    <PageHeader title="Компании" description="Юридические лица группы. Удаление не предусмотрено — неиспользуемые компании деактивируются.">
        <template #actions>
            <UiButton v-if="manageableGroups.length" variant="primary" @click="openCreate">Добавить компанию</UiButton>
        </template>
    </PageHeader>

    <UiCard :padded="false">
        <div class="flex flex-wrap gap-3 border-b border-line p-4">
            <UiInput v-model="list.filters.q" placeholder="Поиск по названию или коду" class="w-72" />
            <UiSelect v-model="list.filters.is_active" :options="[{ value: '', label: 'Все статусы' }, { value: '1', label: 'Активные' }, { value: '0', label: 'Неактивные' }]" class="w-44" />
        </div>
        <DataTable :columns="columns" :rows="list.rows.value" :loading="list.loading.value" :error="list.error.value" empty-title="Компаний нет" @retry="list.load">
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
                <span v-if="row.legal_name" class="block text-[12px] text-muted">{{ row.legal_name }}</span>
            </template>
            <template #cell-code="{ row }"><code class="text-[12px]">{{ row.code }}</code></template>
            <template #cell-units="{ row }">
                <RouterLink :to="{ path: '/org/dealerships', query: { company_id: row.id } }" class="text-brand-700 hover:underline">{{ row.dealerships_count }}</RouterLink>
                / {{ row.departments_count }}
            </template>
            <template #cell-status="{ row }">
                <UiBadge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Активна' : 'Неактивна' }}</UiBadge>
            </template>
            <template #cell-actions="{ row }">
                <UiButton v-if="row.can.update" size="sm" variant="ghost" @click="openEdit(row)">Изменить</UiButton>
            </template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>

    <UiModal v-model:open="modalOpen" :title="editing ? 'Компания' : 'Новая компания'">
        <form id="company-form" class="space-y-4" @submit.prevent="save">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <UiSelect
                v-if="!editing && manageableGroups.length > 1"
                v-model="form.group_id"
                label="Группа компаний"
                required
                :options="manageableGroups.map((g) => ({ value: g.id, label: g.name }))"
                :error="errors.group_id"
            />
            <UiInput v-model="form.name" label="Название" required :error="errors.name" />
            <UiInput v-model="form.legal_name" label="Юридическое название" :error="errors.legal_name" />
            <UiInput v-model="form.code" label="Код" required :error="errors.code" hint="Латиница, цифры, дефис; уникален в группе." />
            <UiCheckbox v-model="form.is_active" label="Активна" />
        </form>
        <template #footer>
            <UiButton @click="modalOpen = false">Отмена</UiButton>
            <UiButton type="submit" form="company-form" variant="primary" :loading="submitting">Сохранить</UiButton>
        </template>
    </UiModal>
</template>
