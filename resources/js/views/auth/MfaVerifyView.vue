<script setup lang="ts">
import { mfaApi } from '@/api';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/auth';
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const code = ref('');
const recoveryCode = ref('');
const useRecovery = ref(false);
const trustDevice = ref(false);
const { submitting, errors, formError, submit } = useForm();

async function onSubmit(): Promise<void> {
    const result = await submit(() =>
        mfaApi.verify(useRecovery.value ? { recovery_code: recoveryCode.value, trust_device: trustDevice.value } : { code: code.value, trust_device: trustDevice.value }),
    );
    if (!result) return;
    auth.setMfa(result.mfa);
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';
    await router.replace(redirect);
}

async function logout(): Promise<void> {
    await auth.logout();
    await router.replace({ name: 'login' });
}
</script>

<template>
    <h1 class="text-lg font-semibold">Подтверждение входа</h1>
    <p class="mt-1 text-[13px] text-muted">
        {{ useRecovery ? 'Введите один из резервных кодов, сохранённых при подключении.' : 'Введите 6-значный код из приложения-аутентификатора.' }}
    </p>
    <form class="mt-5 space-y-4" @submit.prevent="onSubmit">
        <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
        <UiInput v-if="!useRecovery" v-model="code" label="Код" inputmode="numeric" autocomplete="one-time-code" :maxlength="7" required :error="errors.code" />
        <UiInput v-else v-model="recoveryCode" label="Резервный код" autocomplete="off" placeholder="XXXXX-XXXXX" required :error="errors.recovery_code || errors.code" />
        <UiCheckbox v-model="trustDevice" label="Доверять этому устройству 30 дней" hint="Не отмечайте на общих и чужих компьютерах." />
        <UiButton type="submit" variant="primary" block :loading="submitting" :disabled="useRecovery ? !recoveryCode : code.replace(/\D/g, '').length !== 6">
            Подтвердить
        </UiButton>
    </form>
    <button type="button" class="mt-4 block w-full text-center text-[13px] text-brand-700 hover:underline" @click="useRecovery = !useRecovery">
        {{ useRecovery ? 'Ввести код из приложения' : 'Нет доступа к телефону? Резервный код' }}
    </button>
    <p class="mt-3 text-center text-[12px] text-muted">Если нет ни телефона, ни резервных кодов — обратитесь к администратору для сброса.</p>
    <UiButton variant="ghost" block class="mt-2" @click="logout">Выйти</UiButton>
</template>
