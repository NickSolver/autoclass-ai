<script setup lang="ts">
import { mfaApi } from '@/api';
import { setStepUpHandler } from '@/api/http';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import { useForm } from '@/composables/useForm';
import { ref, watch } from 'vue';

/**
 * Глобальный диалог повторного подтверждения кодом для критичных действий.
 * После ввода кода исходный запрос повторяется автоматически (см. api/http.ts).
 */
const open = ref(false);
const code = ref('');
const { submitting, errors, formError, submit, reset } = useForm();
let resolver: ((ok: boolean) => void) | null = null;

setStepUpHandler(
    () =>
        new Promise<boolean>((resolve) => {
            resolver = resolve;
            code.value = '';
            reset();
            open.value = true;
        }),
);

function finish(ok: boolean): void {
    resolver?.(ok);
    resolver = null;
    open.value = false;
}

// Закрытие крестиком/Esc — отмена действия.
watch(open, (v) => {
    if (!v && resolver) finish(false);
});

async function confirm(): Promise<void> {
    if (await submit(() => mfaApi.verify({ code: code.value }))) finish(true);
}
</script>

<template>
    <UiModal v-model:open="open" title="Подтвердите действие" layer="top">
        <form id="step-up-form" class="space-y-4" @submit.prevent="confirm">
            <p class="text-[13px] text-muted">Это действие меняет права доступа. Введите код из приложения-аутентификатора.</p>
            <UiAlert v-if="formError" tone="danger">{{ formError }}</UiAlert>
            <UiInput v-model="code" label="Код" inputmode="numeric" autocomplete="one-time-code" :maxlength="7" required :error="errors.code" />
        </form>
        <template #footer>
            <UiButton @click="finish(false)">Отмена</UiButton>
            <UiButton type="submit" form="step-up-form" variant="primary" :loading="submitting" :disabled="code.replace(/\D/g, '').length !== 6">Подтвердить</UiButton>
        </template>
    </UiModal>
</template>
