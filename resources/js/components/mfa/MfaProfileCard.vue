<script setup lang="ts">
import { mfaApi } from '@/api';
import { ApiError } from '@/api/http';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiModal from '@/components/ui/UiModal.vue';
import { useToasts } from '@/composables/useToasts';
import { useAuthStore } from '@/stores/auth';
import { formatDateTime } from '@/utils/format';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';
import { computed, ref } from 'vue';

const auth = useAuthStore();
const { push } = useToasts();
const mfa = computed(() => auth.me?.mfa);
const busy = ref<string | null>(null);
const codes = ref<string[] | null>(null);
const disableOpen = ref(false);
const disableError = ref<string>();

async function refresh(): Promise<void> {
    auth.setMfa(await mfaApi.show());
}

async function regenerate(): Promise<void> {
    busy.value = 'codes';
    try {
        codes.value = (await mfaApi.regenerateRecoveryCodes()).recovery_codes;
        await refresh();
    } catch (e) {
        if (e instanceof ApiError) push(e.message, 'danger');
    } finally {
        busy.value = null;
    }
}

async function forgetDevices(): Promise<void> {
    busy.value = 'devices';
    try {
        const { forgotten } = await mfaApi.forgetDevices();
        push(forgotten ? `Доверенных устройств удалено: ${forgotten}.` : 'Доверенных устройств нет.');
        await refresh();
    } finally {
        busy.value = null;
    }
}

async function disable(): Promise<void> {
    busy.value = 'disable';
    disableError.value = undefined;
    try {
        auth.setMfa((await mfaApi.disable()).mfa);
        disableOpen.value = false;
        push('Двухфакторная аутентификация отключена.');
    } catch (e) {
        disableError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <UiCard v-if="mfa" title="Двухфакторная аутентификация" description="Код из приложения-аутентификатора при входе.">
        <template #actions>
            <UiBadge v-if="mfa.enabled" tone="success">Подключена</UiBadge>
            <UiBadge v-else-if="mfa.required" tone="warning">Обязательна</UiBadge>
            <UiBadge v-else>Не подключена</UiBadge>
        </template>

        <template v-if="mfa.enabled">
            <dl class="grid grid-cols-[12rem_1fr] gap-y-1.5 text-[13px]">
                <dt class="text-muted">Резервных кодов осталось</dt>
                <dd :class="mfa.recovery_codes_left < 3 && 'font-semibold text-red-700'">{{ mfa.recovery_codes_left }}</dd>
                <dt class="text-muted">Доверенных устройств</dt>
                <dd>{{ mfa.trusted_devices }}</dd>
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                <UiButton size="sm" :loading="busy === 'codes'" @click="regenerate">Новые резервные коды</UiButton>
                <UiButton size="sm" :loading="busy === 'devices'" :disabled="!mfa.trusted_devices" @click="forgetDevices">Забыть доверенные устройства</UiButton>
                <UiButton v-if="!mfa.required" size="sm" variant="ghost" @click="disableError = undefined; disableOpen = true">Отключить</UiButton>
            </div>
        </template>
        <template v-else>
            <p class="text-[13px] text-muted">
                <template v-if="mfa.required && mfa.grace_until">Для вашей роли второй фактор обязателен. Подключите до {{ formatDateTime(mfa.grace_until) }}.</template>
                <template v-else>Рекомендуется: защищает учётную запись, даже если пароль стал известен постороннему.</template>
            </p>
            <RouterLink to="/mfa/setup" class="mt-3 inline-block"><UiButton variant="primary" size="sm">Подключить</UiButton></RouterLink>
        </template>

        <UiModal :open="codes !== null" title="Новые резервные коды" @update:open="(v) => !v && (codes = null)">
            <RecoveryCodesPanel v-if="codes" :codes="codes" @done="codes = null" />
        </UiModal>
        <ConfirmDialog
            v-model:open="disableOpen"
            title="Отключить двухфакторную аутентификацию?"
            message="Вход будет защищён только паролем. Резервные коды и доверенные устройства будут удалены."
            confirm-label="Отключить"
            danger
            :loading="busy === 'disable'"
            :error="disableError"
            @confirm="disable"
        />
    </UiCard>
</template>
