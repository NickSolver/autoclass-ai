<script setup lang="ts">
import { ref, watch } from 'vue';
import UiButton from './UiButton.vue';
import UiModal from './UiModal.vue';
import UiTextarea from './UiTextarea.vue';

/**
 * Подтверждение опасного действия. С requireReason запрашивает причину (уходит в аудит).
 */
const props = defineProps<{
    title: string;
    message: string;
    confirmLabel?: string;
    danger?: boolean;
    requireReason?: boolean;
    loading?: boolean;
    error?: string;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [reason: string] }>();
const reason = ref('');

watch(open, (v) => v && (reason.value = ''));
</script>

<template>
    <UiModal v-model:open="open" :title="props.title">
        <p class="text-[13px] text-ink">{{ props.message }}</p>
        <UiTextarea v-if="requireReason" v-model="reason" class="mt-4" label="Причина" required :maxlength="500" :error="error" />
        <p v-else-if="error" class="mt-3 text-[13px] text-red-600" role="alert">{{ error }}</p>
        <template #footer>
            <UiButton @click="open = false">Отмена</UiButton>
            <UiButton
                :variant="danger ? 'danger' : 'primary'"
                :loading="loading"
                :disabled="requireReason && !reason.trim()"
                @click="emit('confirm', reason.trim())"
            >
                {{ confirmLabel ?? 'Подтвердить' }}
            </UiButton>
        </template>
    </UiModal>
</template>
