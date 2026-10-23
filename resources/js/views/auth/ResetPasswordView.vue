<script setup lang="ts">
import { authApi } from '@/api';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useForm } from '@/composables/useForm';
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));
const password = ref('');
const confirmation = ref('');
const done = ref(false);
const { submitting, errors, formError, submit } = useForm();

async function onSubmit(): Promise<void> {
    const ok = await submit(async () => {
        await authApi.confirmReset({ token: token.value, password: password.value, password_confirmation: confirmation.value });
        return true;
    });
    if (ok) done.value = true;
}
</script>

<template>
    <h1 class="text-lg font-semibold">Новый пароль</h1>
    <UiAlert v-if="!token" tone="danger" class="mt-4">Ссылка неполная. Запросите восстановление пароля ещё раз.</UiAlert>
    <template v-else-if="done">
        <UiAlert tone="success" class="mt-4">Пароль изменён. Все прежние сессии завершены.</UiAlert>
        <RouterLink to="/login" class="mt-4 block text-center text-[13px] text-brand-700 hover:underline">Войти</RouterLink>
    </template>
    <form v-else class="mt-5 space-y-4" @submit.prevent="onSubmit">
        <UiAlert v-if="errors.token || formError" tone="danger">{{ errors.token || formError }}</UiAlert>
        <UiInput v-model="password" label="Новый пароль" type="password" autocomplete="new-password" required :error="errors.password"
                 hint="Не менее 12 символов, строчные и прописные буквы, цифры." />
        <UiInput v-model="confirmation" label="Повторите пароль" type="password" autocomplete="new-password" required />
        <UiButton type="submit" variant="primary" block :loading="submitting" :disabled="!password || !confirmation">Сохранить пароль</UiButton>
    </form>
</template>
