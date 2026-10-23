<script setup lang="ts">
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ChangePasswordForm from '@/views/profile/ChangePasswordForm.vue';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();

async function onChanged(): Promise<void> {
    auth.markPasswordChanged();
    await router.replace('/');
}

async function logout(): Promise<void> {
    await auth.logout();
    await router.replace({ name: 'login' });
}
</script>

<template>
    <h1 class="text-lg font-semibold">Смените временный пароль</h1>
    <UiAlert tone="warning" class="mt-3">Пароль задан администратором. Чтобы продолжить работу, придумайте собственный.</UiAlert>
    <ChangePasswordForm class="mt-4" @changed="onChanged" />
    <UiButton variant="ghost" block class="mt-2" @click="logout">Выйти</UiButton>
</template>
