<script setup lang="ts">
import { mfaApi } from '@/api';
import { ApiError } from '@/api/http';
import type { MfaSetup, MfaSummary } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import { useForm } from '@/composables/useForm';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';
import { computed, onMounted, ref } from 'vue';

/** Подключение TOTP: QR-код → код из приложения → резервные коды. */
const emit = defineEmits<{ done: [summary: MfaSummary] }>();
const setup = ref<MfaSetup | null>(null);
const loadError = ref<string | null>(null);
const code = ref('');
const showSecret = ref(false);
const result = ref<{ recovery_codes: string[]; mfa: MfaSummary } | null>(null);
const { submitting, errors, formError, submit } = useForm();

// SVG генерируется сервером из otpauth-ссылки (библиотека QR, без внешних сервисов) — не из пользовательского ввода.
const qrSrc = computed(() => (setup.value ? `data:image/svg+xml;base64,${btoa(setup.value.qr_svg)}` : ''));
const secretGroups = computed(() => setup.value?.secret.match(/.{1,4}/g)?.join(' ') ?? '');

onMounted(async () => {
    try {
        setup.value = await mfaApi.setup();
    } catch (e) {
        loadError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Не удалось начать подключение.';
    }
});

async function confirm(): Promise<void> {
    const r = await submit(() => mfaApi.confirm(code.value));
    if (r) result.value = r;
}
</script>

<template>
    <UiAlert v-if="loadError" tone="danger">{{ loadError }}</UiAlert>
    <RecoveryCodesPanel v-else-if="result" :codes="result.recovery_codes" @done="emit('done', result.mfa)" />
    <div v-else-if="!setup" class="flex justify-center py-8 text-muted"><UiSpinner :size="22" /></div>
    <form v-else class="space-y-4" @submit.prevent="confirm">
        <ol class="list-decimal space-y-1 pl-5 text-[13px] text-ink">
            <li>Установите на телефон приложение-аутентификатор: Яндекс Ключ, Google Authenticator, Microsoft Authenticator или аналог.</li>
            <li>Отсканируйте QR-код в приложении.</li>
            <li>Введите 6-значный код, который покажет приложение.</li>
        </ol>
        <div class="flex justify-center rounded-lg border border-line bg-white p-3">
            <img :src="qrSrc" alt="QR-код для приложения-аутентификатора" width="200" height="200" />
        </div>
        <p class="text-center text-[12px]">
            <button v-if="!showSecret" type="button" class="text-brand-700 hover:underline" @click="showSecret = true">Не получается отсканировать?</button>
            <span v-else class="block">Ключ для ручного ввода:<br /><code class="select-all text-[13px] tracking-wider">{{ secretGroups }}</code></span>
        </p>
        <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
        <UiInput v-model="code" label="Код из приложения" inputmode="numeric" autocomplete="one-time-code" :maxlength="7" required :error="errors.code" />
        <UiButton type="submit" variant="primary" block :loading="submitting" :disabled="code.replace(/\D/g, '').length !== 6">Подключить</UiButton>
    </form>
</template>
