<script setup lang="ts">
import { useId } from 'vue';
import UiField from './UiField.vue';

export interface SelectOption {
    value: string | number | null;
    label: string;
    disabled?: boolean;
}

defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    options: SelectOption[];
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
}>();
const model = defineModel<string | number | null>();
const id = useId();
</script>

<template>
    <UiField :label="label" :for="id" :error="error" :hint="hint" :required="required">
        <select
            :id="id"
            v-model="model"
            :required="required"
            :disabled="disabled"
            :aria-invalid="!!error || undefined"
            class="h-9 w-full rounded-md border bg-white px-2.5 text-ink disabled:bg-gray-50"
            :class="error ? 'border-red-400' : 'border-line focus:border-brand-500'"
        >
            <option v-if="placeholder !== undefined" :value="null">{{ placeholder }}</option>
            <option v-for="o in options" :key="String(o.value)" :value="o.value" :disabled="o.disabled">{{ o.label }}</option>
        </select>
    </UiField>
</template>
