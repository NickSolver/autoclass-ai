<script setup lang="ts">
import { orgApi, rolesApi, usersApi } from '@/api';
import type { Company, Role, User } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useForm } from '@/composables/useForm';
import { usePaginatedList } from '@/composables/usePaginatedList';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { formatDateTime } from '@/utils/format';
import { generateTemporaryPassword } from './tempPassword';
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();
const { push } = useToasts();
const list = usePaginatedList(usersApi.list, { q: '', company_id: '', role_id: '', is_active: '' as '' | '1' | '0' });
const companies = ref<Company[]>([]);
const roles = ref<Role[]>([]);
onMounted(async () => {
    [companies.value, roles.value] = await Promise.all([
        orgApi.companies.list({ per_page: 100 }).then((r) => r.data).catch(() => []),
        rolesApi.list().then((r) => r.data).catch(() => []),
    ]);
});

const columns = [
    { key: 'name', label: 'Пользователь' },
    { key: 'home', label: 'Подразделение' },
    { key: 'roles', label: 'Роли' },
    { key: 'last_login_at', label: 'Последний вход', class: 'w-36' },
    { key: 'status', label: 'Статус', class: 'w-32' },
];

function homeLabel(u: User): string {
    return [u.home.company?.name, u.home.dealership?.name, u.home.department?.name].filter(Boolean).join(' / ') || (u.home.group?.name ?? '—');
}

// Создание
const createOpen = ref(false);
const home = ref<OrgUnitValue | null>(null);
const form = reactive({ login: '', name: '', email: '', password: '' });
const { submitting, errors, formError, submit, reset } = useForm();

function openCreate(): void {
    Object.assign(form, { login: '', name: '', email: '', password: generateTemporaryPassword() });
    home.value = null;
    reset();
    createOpen.value = true;
}

async function create(): Promise<void> {
    const homeIds = home.value ? { [`${home.value.type}_id`]: home.value.id } : {};
    const user = await submit(() => usersApi.create({ ...form, email: form.email || null, ...homeIds }));
    if (user) {
        createOpen.value = false;
        push(`Пользователь ${user.login} создан. Передайте ему временный пароль — при входе его потребуется сменить.`);
        await router.push(`/users/${user.id}`);
    }
}
</script>

<template>
    <PageHeader title="Пользователи" description="Учётные записи портала. Публичной регистрации нет — пользователей заводят администраторы в своей области.">
        <template #actions>
            <UiButton v-if="auth.can('users.create')" variant="primary" @click="openCreate">Создать пользователя</UiButton>
        </template>
    </PageHeader>

    <UiCard :padded="false">
        <div class="flex flex-wrap gap-3 border-b border-line p-4">
            <UiInput v-model="list.filters.q" placeholder="Имя, логин или e-mail" class="w-64" />
            <UiSelect v-model="list.filters.company_id" :options="[{ value: '', label: 'Все компании' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" class="w-52" />
            <UiSelect v-model="list.filters.role_id" :options="[{ value: '', label: 'Все роли' }, ...roles.map((r) => ({ value: String(r.id), label: r.name }))]" class="w-56" />
            <UiSelect v-model="list.filters.is_active" :options="[{ value: '', label: 'Все статусы' }, { value: '1', label: 'Активные' }, { value: '0', label: 'Отключённые' }]" class="w-40" />
        </div>
        <DataTable :columns="columns" :rows="list.rows.value" :loading="list.loading.value" :error="list.error.value" empty-title="Пользователи не найдены" @retry="list.load">
            <template #cell-name="{ row }">
                <RouterLink :to="`/users/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ row.name }}</RouterLink>
                <span class="block text-[12px] text-muted">{{ row.login }}</span>
            </template>
            <template #cell-home="{ row }">{{ homeLabel(row) }}</template>
            <template #cell-roles="{ row }">
                <div class="flex flex-wrap gap-1">
                    <UiBadge v-for="s in row.role_scopes" :key="s.id" tone="info">{{ s.role.name }}</UiBadge>
                    <span v-if="!row.role_scopes?.length" class="text-muted">нет ролей</span>
                </div>
            </template>
            <template #cell-last_login_at="{ row }">{{ formatDateTime(row.last_login_at) }}</template>
            <template #cell-status="{ row }">
                <UiBadge v-if="!row.is_active" tone="danger">Отключён</UiBadge>
                <UiBadge v-else-if="row.must_change_password" tone="warning">Временный пароль</UiBadge>
                <UiBadge v-else tone="success">Активен</UiBadge>
            </template>
        </DataTable>
        <UiPagination v-if="list.meta.value" :meta="list.meta.value" @change="list.goTo" />
    </UiCard>

    <UiModal v-model:open="createOpen" title="Новый пользователь">
        <form id="user-form" class="space-y-4" @submit.prevent="create">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <UiInput v-model="form.name" label="Имя" required :error="errors.name" />
            <div class="grid gap-4 sm:grid-cols-2">
                <UiInput v-model="form.login" label="Логин" required autocomplete="off" :error="errors.login" hint="Латиница, цифры, точка, дефис." />
                <UiInput v-model="form.email" label="E-mail" type="email" :error="errors.email" hint="Для восстановления пароля." />
            </div>
            <OrgUnitSelect v-model="home" permission="users.create" label="Подразделение" required
                           :error="errors.group_id || errors.company_id || errors.dealership_id || errors.department_id"
                           hint="Определяет, какие администраторы управляют учётной записью. Роли назначаются отдельно." />
            <UiInput v-model="form.password" label="Временный пароль" required autocomplete="new-password" :error="errors.password"
                     hint="Сообщите пользователю защищённым способом. При первом входе его потребуется сменить." />
        </form>
        <template #footer>
            <UiButton @click="createOpen = false">Отмена</UiButton>
            <UiButton type="submit" form="user-form" variant="primary" :loading="submitting">Создать</UiButton>
        </template>
    </UiModal>
</template>
