<script setup lang="ts">
import { authApi } from '@/api';
import { ApiError } from '@/api/http';
import type { SessionInfo } from '@/api/types';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { formatDateTime, scopeTypeLabels } from '@/utils/format';
import MfaProfileCard from '@/components/mfa/MfaProfileCard.vue';
import ChangePasswordForm from './ChangePasswordForm.vue';
import { onMounted, ref } from 'vue';

const auth = useAuthStore();
const { push } = useToasts();
const sessions = ref<SessionInfo[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const busy = ref<string | null>(null);

async function loadSessions(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        sessions.value = (await authApi.sessions()).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить сессии.';
    } finally {
        loading.value = false;
    }
}

async function terminate(id: string): Promise<void> {
    busy.value = id;
    try {
        await authApi.terminateSession(id);
        push('Сессия завершена.');
        await loadSessions();
    } catch (e) {
        push(e instanceof ApiError ? e.message : 'Ошибка.', 'danger');
    } finally {
        busy.value = null;
    }
}

async function terminateOthers(): Promise<void> {
    busy.value = 'others';
    try {
        const { terminated } = await authApi.terminateOtherSessions();
        push(terminated ? `Завершено сессий: ${terminated}.` : 'Других сессий нет.');
        await loadSessions();
    } finally {
        busy.value = null;
    }
}

onMounted(loadSessions);
</script>

<template>
    <PageHeader title="Профиль" :description="auth.user?.login" />
    <div class="grid gap-5 lg:grid-cols-2">
        <UiCard title="Учётная запись">
            <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2 text-[13px]">
                <dt class="text-muted">Имя</dt><dd>{{ auth.user?.name }}</dd>
                <dt class="text-muted">Логин</dt><dd>{{ auth.user?.login }}</dd>
                <dt class="text-muted">E-mail</dt><dd>{{ auth.user?.email ?? '—' }}</dd>
                <dt class="text-muted">Подразделение</dt><dd>{{ auth.user?.home }}</dd>
                <dt class="text-muted">Сотрудник</dt><dd>{{ auth.user?.employee?.full_name ?? 'не связан' }}</dd>
                <dt class="text-muted">Последний вход</dt><dd>{{ formatDateTime(auth.user?.last_login_at) }}</dd>
            </dl>
        </UiCard>

        <UiCard title="Смена пароля">
            <ChangePasswordForm @changed="push('Пароль изменён. Остальные сессии завершены.'); loadSessions()" />
        </UiCard>

        <MfaProfileCard />

        <UiCard title="Роли и права" description="Права действуют только в указанных подразделениях.">
            <ul v-if="auth.me?.role_assignments.length" class="space-y-2 text-[13px]">
                <li v-for="(a, i) in auth.me.role_assignments" :key="i" class="flex flex-wrap items-baseline gap-x-2">
                    <span class="font-medium">{{ a.role }}</span>
                    <span class="text-muted">— {{ scopeTypeLabels[a.scope_type] }}: {{ a.scope_label }}</span>
                </li>
            </ul>
            <p v-else class="text-[13px] text-muted">Роли не назначены.</p>
            <details v-if="auth.me?.permissions.length" class="mt-4 text-[13px]">
                <summary class="cursor-pointer text-brand-700">Все права ({{ auth.me.permissions.length }})</summary>
                <ul class="mt-2 flex flex-wrap gap-1.5">
                    <li v-for="p in auth.me.permissions" :key="p"><UiBadge>{{ p }}</UiBadge></li>
                </ul>
            </details>
        </UiCard>

        <UiCard title="Активные сессии">
            <template #actions>
                <UiButton size="sm" :loading="busy === 'others'" :disabled="sessions.length < 2" @click="terminateOthers">Завершить остальные</UiButton>
            </template>
            <div v-if="loading" class="flex justify-center py-6 text-muted"><UiSpinner /></div>
            <UiAlert v-else-if="error" tone="danger">{{ error }}</UiAlert>
            <ul v-else class="divide-y divide-line text-[13px]">
                <li v-for="s in sessions" :key="s.id" class="flex items-start gap-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate">{{ s.user_agent ?? 'Неизвестный браузер' }}</p>
                        <p class="text-muted">{{ s.ip_address ?? '—' }} · активность {{ formatDateTime(s.last_activity) }}</p>
                    </div>
                    <UiBadge v-if="s.is_current" tone="success">Текущая</UiBadge>
                    <UiButton v-else size="sm" variant="ghost" :loading="busy === s.id" @click="terminate(s.id)">Завершить</UiButton>
                </li>
            </ul>
        </UiCard>
    </div>
</template>
