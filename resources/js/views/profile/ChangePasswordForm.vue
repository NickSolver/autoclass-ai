<script setup lang="ts">
import { authApi } from '@/api';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useForm } from '@/composables/useForm';
import { reactive } from 'vue';

const emit = defineEmits<{ changed: [] }>();
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const { submitting, errors, formError, submit } = useForm();

async function onSubmit(): Promise<void> {
    const ok = await submit(async () => {
        await authApi.changePassword({ ...form });
        return true;
    });
    if (ok) {
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
        emit('changed');
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="onSubmit">
        <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
        <UiInput v-model="form.current_password" label="Текущий пароль" type="password" autocomplete="current-password" required :error="errors.current_password" />
        <UiInput v-model="form.password" label="Новый пароль" type="password" autocomplete="new-password" required :error="errors.password"
                 hint="Не менее 12 символов, строчные и прописные буквы, цифры." />
        <UiInput v-model="form.password_confirmation" label="Повторите новый пароль" type="password" autocomplete="new-password" required />
        <UiButton type="submit" variant="primary" :loading="submitting" :disabled="!form.current_password || !form.password">Сменить пароль</UiButton>
    </form>
</template>
