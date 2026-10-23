<script setup lang="ts">
import type { MfaSummary } from '@/api/types';
import MfaSetupFlow from '@/components/mfa/MfaSetupFlow.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import { useAuthStore } from '@/stores/auth';
import { formatDateTime } from '@/utils/format';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();

async function done(summary: MfaSummary): Promise<void> {
    auth.setMfa(summary);
    await router.replace('/');
}

async function logout(): Promise<void> {
    await auth.logout();
    await router.replace({ name: 'login' });
}
</script>

<template>
    <h1 class="text-lg font-semibold">Двухфакторная аутентификация</h1>
    <UiAlert v-if="auth.me?.mfa.status === 'setup_required'" tone="warning" class="mt-3">
        Для вашей роли второй фактор обязателен. Без него работа в портале недоступна.
    </UiAlert>
    <p v-else-if="auth.me?.mfa.grace_until" class="mt-1 text-[13px] text-muted">
        Подключите до {{ formatDateTime(auth.me.mfa.grace_until) }} — после этого без второго фактора войти будет нельзя.
    </p>
    <MfaSetupFlow class="mt-4" @done="done" />
    <UiButton v-if="auth.me?.mfa.status === 'setup_required'" variant="ghost" block class="mt-2" @click="logout">Выйти</UiButton>
    <RouterLink v-else to="/" class="mt-3 block text-center text-[13px] text-brand-700 hover:underline">Позже</RouterLink>
</template>
