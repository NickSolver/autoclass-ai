<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const layout = computed(() => (route.meta.layout === 'auth' || route.meta.public ? AuthLayout : AppLayout));

// Сервер сообщил о завершении сессии (401) или требовании сменить пароль — уводим на нужный экран.
watch(
    () => [auth.isAuthenticated, auth.me?.mfa.status, auth.user?.must_change_password] as const,
    ([authenticated, mfa, mustChange]) => {
        if (!auth.loaded || route.meta.public) return;
        if (!authenticated) router.replace({ name: 'login', query: { redirect: route.fullPath } });
        else if (mfa === 'verify_required' && route.name !== 'mfa-verify') router.replace({ name: 'mfa-verify' });
        else if (mfa === 'setup_required' && route.name !== 'mfa-setup') router.replace({ name: 'mfa-setup' });
        else if (mustChange && route.name !== 'change-password') router.replace({ name: 'change-password' });
    },
);
</script>

<template>
    <component :is="layout">
        <RouterView />
    </component>
</template>
