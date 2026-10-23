<script setup lang="ts">
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/auth';
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const login = ref('');
const password = ref('');
const { submitting, errors, formError, submit } = useForm();

async function onSubmit(): Promise<void> {
    await submit(async () => {
        await auth.login(login.value, password.value);
        const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';
        await router.replace(auth.user?.must_change_password ? { name: 'change-password' } : redirect);
    });
    password.value = '';
}
</script>

<template>
    <h1 class="text-lg font-semibold">Вход в портал</h1>
    <p class="mt-1 text-[13px] text-muted">Используйте логин и пароль, выданные администратором.</p>
    <form class="mt-5 space-y-4" novalidate @submit.prevent="onSubmit">
        <UiAlert v-if="errors.login || formError" tone="danger">{{ errors.login || formError }}</UiAlert>
        <UiInput v-model="login" label="Логин" autocomplete="username" required />
        <UiInput v-model="password" label="Пароль" type="password" autocomplete="current-password" required :error="errors.password" />
        <UiButton type="submit" variant="primary" block :loading="submitting" :disabled="!login || !password">Войти</UiButton>
    </form>
    <RouterLink to="/forgot-password" class="mt-4 block text-center text-[13px] text-brand-700 hover:underline">Забыли пароль?</RouterLink>
</template>
