<script setup lang="ts">
import { auditApi, employeesApi, rolesApi, usersApi } from '@/api';
import { ApiError } from '@/api/http';
import type { AuditEvent, Employee, Role, ScopeTypeCode, User, UserRoleScope } from '@/api/types';
import OrgUnitSelect, { type OrgUnitValue } from '@/components/org/OrgUnitSelect.vue';
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
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { auditActionLabel, formatDateTime, scopeLabel, scopeTypeLabels } from '@/utils/format';
import { generateTemporaryPassword } from './tempPassword';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps<{ id: number }>();
const auth = useAuthStore();
const { push } = useToasts();
const user = ref<User | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const events = ref<AuditEvent[]>([]);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        user.value = await usersApi.show(props.id);
        if (auth.can('audit.view')) {
            events.value = (await auditApi.list({ subject_type: 'user', subject_id: props.id, per_page: 10 }).catch(() => ({ data: [] }))).data;
        }
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить пользователя.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const homeLabel = computed(() => {
    const h = user.value?.home;
    return [h?.group?.name, h?.company?.name, h?.dealership?.name, h?.department?.name].filter(Boolean).join(' / ');
});
const isSelf = computed(() => user.value?.id === auth.user?.id);

// --- Изменение данных ---
const editOpen = ref(false);
const editForm = reactive({ name: '', login: '', email: '' });
const newHome = ref<OrgUnitValue | null>(null);
const employeeQuery = ref('');
const employeeOptions = ref<Employee[]>([]);
const employeeId = ref<number | null>(null);
const edit = useForm();

function openEdit(): void {
    if (!user.value) return;
    Object.assign(editForm, { name: user.value.name, login: user.value.login, email: user.value.email ?? '' });
    newHome.value = null;
    employeeId.value = user.value.employee?.id ?? null;
    employeeOptions.value = [];
    employeeQuery.value = '';
    edit.reset();
    editOpen.value = true;
}

let employeeTimer: ReturnType<typeof setTimeout> | undefined;
watch(employeeQuery, (q) => {
    clearTimeout(employeeTimer);
    if (q.trim().length < 2) return;
    employeeTimer = setTimeout(async () => {
        employeeOptions.value = (await employeesApi.list({ q, per_page: 20, is_active: 1 }).catch(() => ({ data: [] }))).data;
    }, 300);
});

const employeeSelectOptions = computed(() => {
    const current = user.value?.employee;
    const options = employeeOptions.value.map((e) => ({ value: e.id, label: `${e.full_name}${e.user && e.user.id !== user.value?.id ? ' (уже связан)' : ''}`, disabled: !!e.user && e.user.id !== user.value?.id }));
    if (current && !options.some((o) => o.value === current.id)) options.unshift({ value: current.id, label: current.full_name, disabled: false });
    return options;
});

async function saveEdit(): Promise<void> {
    const body: Record<string, unknown> = { ...editForm, email: editForm.email || null, employee_id: employeeId.value };
    if (newHome.value) Object.assign(body, { group_id: null, company_id: null, dealership_id: null, department_id: null, [`${newHome.value.type}_id`]: newHome.value.id });
    const result = await edit.submit(() => usersApi.update(props.id, body));
    if (result) {
        user.value = result;
        editOpen.value = false;
        push('Данные пользователя сохранены.');
    }
}

// --- Роли ---
const roles = ref<Role[]>([]);
const grantOpen = ref(false);
const grantRoleId = ref<number | null>(null);
const grantScopeType = ref<ScopeTypeCode | null>(null);
const grantUnit = ref<OrgUnitValue | null>(null);
const grant = useForm();
const selectedRole = computed(() => roles.value.find((r) => r.id === grantRoleId.value));

async function openGrant(): Promise<void> {
    grant.reset();
    grantRoleId.value = null;
    grantScopeType.value = null;
    grantUnit.value = null;
    grantOpen.value = true;
    if (!roles.value.length) roles.value = (await rolesApi.list()).data;
}

watch(grantRoleId, () => {
    grantScopeType.value = selectedRole.value?.allowed_scope_types.length === 1 ? selectedRole.value.allowed_scope_types[0]! : null;
    grantUnit.value = null;
});
watch(grantScopeType, () => (grantUnit.value = null));

async function saveGrant(): Promise<void> {
    if (!grantRoleId.value || !grantScopeType.value) return;
    const result = await grant.submit(() =>
        usersApi.grantRole(props.id, {
            role_id: grantRoleId.value!,
            scope_type: grantScopeType.value!,
            scope_id: grantScopeType.value === 'own' ? null : (grantUnit.value?.id ?? null),
        }),
    );
    if (result) {
        user.value = result;
        grantOpen.value = false;
        push('Роль назначена.');
    }
}

const revoking = ref<UserRoleScope | null>(null);
const revokeOpen = ref(false);
const revokeBusy = ref(false);
const revokeError = ref<string>();

async function confirmRevoke(): Promise<void> {
    if (!revoking.value) return;
    revokeBusy.value = true;
    revokeError.value = undefined;
    try {
        user.value = await usersApi.revokeRole(props.id, revoking.value.id);
        revokeOpen.value = false;
        push('Назначение роли отозвано.');
    } catch (e) {
        revokeError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        revokeBusy.value = false;
    }
}

// --- Статус, пароль, сессии ---
const statusOpen = ref(false);
const statusBusy = ref(false);
const statusError = ref<string>();

async function confirmStatus(reason: string): Promise<void> {
    if (!user.value) return;
    statusBusy.value = true;
    statusError.value = undefined;
    try {
        user.value = await usersApi.setStatus(props.id, !user.value.is_active, reason || undefined);
        statusOpen.value = false;
        push(user.value.is_active ? 'Пользователь включён.' : 'Пользователь отключён, его сессии завершены.');
    } catch (e) {
        statusError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        statusBusy.value = false;
    }
}

const passwordOpen = ref(false);
const tempPassword = ref('');
const pwd = useForm();

function openPassword(): void {
    tempPassword.value = generateTemporaryPassword();
    pwd.reset();
    passwordOpen.value = true;
}

async function savePassword(): Promise<void> {
    const result = await pwd.submit(() => usersApi.setPassword(props.id, tempPassword.value));
    if (result) {
        passwordOpen.value = false;
        push(result.message);
        await load();
    }
}

const mfaResetOpen = ref(false);
const mfaResetBusy = ref(false);
const mfaResetError = ref<string>();

async function confirmMfaReset(reason: string): Promise<void> {
    mfaResetBusy.value = true;
    mfaResetError.value = undefined;
    try {
        user.value = await usersApi.resetMfa(props.id, reason);
        mfaResetOpen.value = false;
        push('Двухфакторная аутентификация сброшена, сессии пользователя завершены.');
    } catch (e) {
        mfaResetError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        mfaResetBusy.value = false;
    }
}

const sessionsBusy = ref(false);
async function terminateSessions(): Promise<void> {
    sessionsBusy.value = true;
    try {
        const { terminated } = await usersApi.terminateSessions(props.id);
        push(terminated ? `Завершено сессий: ${terminated}.` : 'Активных сессий нет.');
    } catch (e) {
        push(e instanceof ApiError ? e.message : 'Ошибка.', 'danger');
    } finally {
        sessionsBusy.value = false;
    }
}
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="error" tone="danger" title="Учётная запись недоступна">{{ error }}</UiAlert>
    <template v-else-if="user">
        <PageHeader :title="user.name" :description="user.login">
            <template #actions>
                <UiBadge v-if="!user.is_active" tone="danger">Отключён</UiBadge>
                <UiBadge v-else-if="user.must_change_password" tone="warning">Временный пароль</UiBadge>
                <UiBadge v-else tone="success">Активен</UiBadge>
            </template>
        </PageHeader>

        <UiAlert v-if="!user.can.update" tone="info" class="mb-5">
            Учётная запись доступна только для просмотра: она или её роли выходят за пределы вашей области управления.
        </UiAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <UiCard title="Роли" description="Права действуют только в указанной области и ниже по структуре.">
                    <template #actions>
                        <UiButton v-if="user.can.update" size="sm" variant="primary" @click="openGrant">Назначить роль</UiButton>
                    </template>
                    <p v-if="!user.role_scopes?.length" class="text-[13px] text-muted">Ролей нет — пользователь не имеет доступа ни к одному разделу.</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="s in user.role_scopes" :key="s.id" class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ s.role.name }}</p>
                                <p class="text-[13px] text-muted">{{ scopeTypeLabels[s.scope_type] }}: {{ s.scope_label }}</p>
                            </div>
                            <UiButton v-if="s.can_revoke" size="sm" variant="ghost" @click="revoking = s; revokeError = undefined; revokeOpen = true">Отозвать</UiButton>
                        </li>
                    </ul>
                </UiCard>

                <UiCard v-if="auth.can('audit.view')" title="Последние события">
                    <p v-if="!events.length" class="text-[13px] text-muted">Событий нет.</p>
                    <ul v-else class="space-y-2 text-[13px]">
                        <li v-for="e in events" :key="e.id" class="flex gap-3">
                            <span class="w-32 shrink-0 text-muted">{{ formatDateTime(e.occurred_at) }}</span>
                            <span class="flex-1">{{ auditActionLabel(e.action) }}<span v-if="e.actor" class="text-muted"> — {{ e.actor.login }}</span></span>
                        </li>
                    </ul>
                    <RouterLink :to="{ path: '/audit', query: { subject_type: 'user', subject_id: user.id } }" class="mt-3 inline-block text-[13px] text-brand-700 hover:underline">Весь журнал →</RouterLink>
                </UiCard>
            </div>

            <div class="space-y-5">
                <UiCard title="Данные">
                    <template #actions>
                        <UiButton v-if="user.can.update" size="sm" @click="openEdit">Изменить</UiButton>
                    </template>
                    <dl class="space-y-2 text-[13px]">
                        <div><dt class="text-muted">E-mail</dt><dd class="break-all">{{ user.email ?? '—' }}</dd></div>
                        <div><dt class="text-muted">Подразделение</dt><dd>{{ homeLabel }}</dd></div>
                        <div>
                            <dt class="text-muted">Сотрудник</dt>
                            <dd>
                                <RouterLink v-if="user.employee" :to="`/employees/${user.employee.id}`" class="text-brand-700 hover:underline">{{ user.employee.full_name }}</RouterLink>
                                <span v-else class="text-muted">не связан</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">Двухфакторная аутентификация</dt>
                            <dd><UiBadge :tone="user.mfa_enabled ? 'success' : 'neutral'">{{ user.mfa_enabled ? 'подключена' : 'не подключена' }}</UiBadge></dd>
                        </div>
                        <div><dt class="text-muted">Последний вход</dt><dd>{{ formatDateTime(user.last_login_at) }}</dd></div>
                        <div><dt class="text-muted">Создан</dt><dd>{{ formatDateTime(user.created_at) }}</dd></div>
                        <div v-if="user.disabled_at"><dt class="text-muted">Отключён</dt><dd>{{ formatDateTime(user.disabled_at) }}</dd></div>
                    </dl>
                </UiCard>

                <UiCard v-if="user.can.update || user.can.disable" title="Безопасность">
                    <div class="flex flex-col gap-2">
                        <UiButton v-if="user.can.update" @click="openPassword">Задать временный пароль</UiButton>
                        <UiButton v-if="user.can.update" :loading="sessionsBusy" @click="terminateSessions">Завершить все сессии</UiButton>
                        <UiButton v-if="user.can.reset_mfa && user.mfa_enabled" @click="mfaResetError = undefined; mfaResetOpen = true">Сбросить двухфакторную аутентификацию</UiButton>
                        <UiButton v-if="user.can.disable" :variant="user.is_active ? 'danger' : 'primary'" @click="statusError = undefined; statusOpen = true">
                            {{ user.is_active ? 'Отключить учётную запись' : 'Включить учётную запись' }}
                        </UiButton>
                        <p v-else-if="isSelf" class="text-[12px] text-muted">Собственную учётную запись отключить нельзя.</p>
                    </div>
                </UiCard>
            </div>
        </div>

        <!-- Изменение данных -->
        <UiModal v-model:open="editOpen" title="Данные пользователя">
            <form id="user-edit-form" class="space-y-4" @submit.prevent="saveEdit">
                <UiAlert v-if="edit.formError.value" tone="danger">{{ edit.formError.value }}</UiAlert>
                <UiInput v-model="editForm.name" label="Имя" required :error="edit.errors.value.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiInput v-model="editForm.login" label="Логин" required :error="edit.errors.value.login" />
                    <UiInput v-model="editForm.email" label="E-mail" type="email" :error="edit.errors.value.email" />
                </div>
                <OrgUnitSelect v-model="newHome" permission="users.update" label="Перенести в подразделение" placeholder="Не менять"
                               :hint="`Сейчас: ${homeLabel}`" :error="edit.errors.value.company_id || edit.errors.value.department_id" />
                <div class="space-y-2">
                    <UiInput v-model="employeeQuery" label="Связанный сотрудник" placeholder="Начните вводить фамилию…" />
                    <UiSelect v-model="employeeId" placeholder="Не связан" :options="employeeSelectOptions" :error="edit.errors.value.employee_id" />
                </div>
            </form>
            <template #footer>
                <UiButton @click="editOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="user-edit-form" variant="primary" :loading="edit.submitting.value">Сохранить</UiButton>
            </template>
        </UiModal>

        <!-- Назначение роли -->
        <UiModal v-model:open="grantOpen" title="Назначить роль">
            <form id="grant-form" class="space-y-4" @submit.prevent="saveGrant">
                <UiAlert v-if="grant.formError.value" tone="danger">{{ grant.formError.value }}</UiAlert>
                <UiSelect v-model="grantRoleId" label="Роль" required placeholder="Выберите роль" :options="roles.map((r) => ({ value: r.id, label: r.name }))" :error="grant.errors.value.role_id" />
                <p v-if="selectedRole?.description" class="text-[12px] text-muted">{{ selectedRole.description }}</p>
                <UiSelect
                    v-if="selectedRole"
                    v-model="grantScopeType"
                    label="Уровень области"
                    required
                    placeholder="Выберите уровень"
                    :options="selectedRole.allowed_scope_types.map((t) => ({ value: t, label: scopeLabel(t) }))"
                    :error="grant.errors.value.scope_type"
                />
                <OrgUnitSelect
                    v-if="grantScopeType && grantScopeType !== 'own'"
                    :key="grantScopeType"
                    v-model="grantUnit"
                    permission="users.update"
                    :types="[grantScopeType]"
                    label="Подразделение"
                    required
                    :error="grant.errors.value.scope_id"
                />
                <p v-if="grantScopeType === 'own'" class="text-[12px] text-muted">Только записи сотрудника, связанного с этой учётной записью.</p>
                <p class="text-[12px] text-muted">Назначить можно только роль, все права которой есть у вас в выбранной области.</p>
            </form>
            <template #footer>
                <UiButton @click="grantOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="grant-form" variant="primary" :loading="grant.submitting.value"
                          :disabled="!grantRoleId || !grantScopeType || (grantScopeType !== 'own' && !grantUnit)">
                    Назначить
                </UiButton>
            </template>
        </UiModal>

        <!-- Временный пароль -->
        <UiModal v-model:open="passwordOpen" title="Временный пароль">
            <form id="password-form" class="space-y-4" @submit.prevent="savePassword">
                <UiAlert tone="warning">Все сессии пользователя будут завершены. При входе он должен будет сменить пароль.</UiAlert>
                <UiAlert v-if="pwd.formError.value" tone="danger">{{ pwd.formError.value }}</UiAlert>
                <UiInput v-model="tempPassword" label="Временный пароль" required autocomplete="new-password" :error="pwd.errors.value.password" />
            </form>
            <template #footer>
                <UiButton @click="passwordOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="password-form" variant="primary" :loading="pwd.submitting.value">Установить</UiButton>
            </template>
        </UiModal>

        <ConfirmDialog
            v-model:open="mfaResetOpen"
            title="Сбросить двухфакторную аутентификацию?"
            message="Используйте, если пользователь потерял телефон и резервные коды. Его сессии будут завершены, при следующем входе он подключит MFA заново. Убедитесь, что обращается именно владелец учётной записи."
            confirm-label="Сбросить"
            danger
            require-reason
            :loading="mfaResetBusy"
            :error="mfaResetError"
            @confirm="confirmMfaReset"
        />
        <ConfirmDialog
            v-model:open="statusOpen"
            :title="user.is_active ? 'Отключить учётную запись?' : 'Включить учётную запись?'"
            :message="user.is_active ? 'Пользователь потеряет доступ немедленно, все его сессии будут завершены.' : 'Пользователь снова сможет войти в портал.'"
            :confirm-label="user.is_active ? 'Отключить' : 'Включить'"
            :danger="user.is_active"
            :require-reason="user.is_active"
            :loading="statusBusy"
            :error="statusError"
            @confirm="confirmStatus"
        />
        <ConfirmDialog
            v-model:open="revokeOpen"
            title="Отозвать роль?"
            :message="revoking ? `${revoking.role.name} — ${scopeTypeLabels[revoking.scope_type]}: ${revoking.scope_label}` : ''"
            confirm-label="Отозвать"
            danger
            :loading="revokeBusy"
            :error="revokeError"
            @confirm="confirmRevoke"
        />
    </template>
</template>
