<script setup lang="ts">
import { rolesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Role } from '@/api/types';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { useAuthStore } from '@/stores/auth';
import { scopeLabel } from '@/utils/format';
import { onMounted, ref } from 'vue';

const auth = useAuthStore();
const roles = ref<Role[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        roles.value = (await rolesApi.list()).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить роли.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const columns = [
    { key: 'name', label: 'Роль' },
    { key: 'scopes', label: 'Назначается на уровень' },
    { key: 'permissions', label: 'Прав', class: 'w-20' },
    { key: 'assignments_count', label: 'Назначений', class: 'w-28' },
];
</script>

<template>
    <PageHeader title="Роли и права" description="Роль — набор прав. Права действуют в области, на которую роль назначена пользователю. Изменять роли может только суперадминистратор.">
        <template #actions>
            <RouterLink v-if="auth.me?.can.manage_roles" to="/roles/new"><UiButton variant="primary">Создать роль</UiButton></RouterLink>
        </template>
    </PageHeader>
    <UiCard :padded="false">
        <DataTable :columns="columns" :rows="roles" :loading="loading" :error="error" empty-title="Ролей нет" @retry="load">
            <template #cell-name="{ row }">
                <RouterLink :to="`/roles/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ row.name }}</RouterLink>
                <UiBadge v-if="row.is_system" class="ml-2">шаблон</UiBadge>
                <span v-if="row.description" class="block max-w-xl text-[12px] text-muted">{{ row.description }}</span>
            </template>
            <template #cell-scopes="{ row }">{{ row.allowed_scope_types.map(scopeLabel).join(', ') }}</template>
            <template #cell-permissions="{ row }">{{ row.permissions?.length ?? 0 }}</template>
        </DataTable>
    </UiCard>
</template>
