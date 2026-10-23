<script setup lang="ts">
import { authApi } from '@/api';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useForm } from '@/composables/useForm';
import { ref } from 'vue';

const login = ref('');
const sentMessage = ref<string | null>(null);
const { submitting, errors, formError, submit } = useForm();

async function onSubmit(): Promise<void> {
    const result = await submit(() => authApi.requestReset(login.value));
    if (result) sentMessage.value = result.message;
}
</script>

<template>
    <h1 class="text-lg font-semibold">Восстановление пароля</h1>
    <UiAlert v-if="sentMessage" tone="success" class="mt-4">{{ sentMessage }}</UiAlert>
    <template v-else>
        <p class="mt-1 text-[13px] text-muted">
            Укажите логин или e-mail. Если для учётной записи задан e-mail, на него придёт одноразовая ссылка.
            Если e-mail не задан, обратитесь к администратору.
        </p>
        <form class="mt-5 space-y-4" @submit.prevent="onSubmit">
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <UiInput v-model="login" label="Логин или e-mail" autocomplete="username" required :error="errors.login" />
            <UiButton type="submit" variant="primary" block :loading="submitting" :disabled="!login">Отправить ссылку</UiButton>
        </form>
    </template>
    <RouterLink to="/login" class="mt-4 block text-center text-[13px] text-brand-700 hover:underline">Вернуться ко входу</RouterLink>
</template>
