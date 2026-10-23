<script setup lang="ts">
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import { ref } from 'vue';

/** Резервные коды показываются один раз: пользователь должен подтвердить, что сохранил их. */
const props = defineProps<{ codes: string[] }>();
const emit = defineEmits<{ done: [] }>();
const saved = ref(false);
const copied = ref(false);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <UiAlert tone="warning" title="Сохраните резервные коды">
            Каждый код срабатывает один раз — если телефон недоступен. Больше коды показаны не будут.
            Храните их отдельно от телефона: в менеджере паролей или распечатанными.
        </UiAlert>
        <ol class="grid grid-cols-2 gap-2 rounded-lg border border-line bg-canvas p-4 font-mono text-[14px]">
            <li v-for="code in codes" :key="code" class="tracking-wider">{{ code }}</li>
        </ol>
        <div class="flex flex-wrap gap-2">
            <UiButton size="sm" @click="copy">{{ copied ? 'Скопировано' : 'Скопировать' }}</UiButton>
        </div>
        <UiCheckbox v-model="saved" label="Я сохранил резервные коды" />
        <UiButton variant="primary" block :disabled="!saved" @click="emit('done')">Готово</UiButton>
    </div>
</template>
