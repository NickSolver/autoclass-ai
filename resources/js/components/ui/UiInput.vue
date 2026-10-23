<script setup lang="ts">
import { useId } from 'vue';
import UiField from './UiField.vue';

defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    type?: string;
    required?: boolean;
    placeholder?: string;
    autocomplete?: string;
    disabled?: boolean;
    maxlength?: number;
    inputmode?: 'text' | 'numeric';
}>();
const model = defineModel<string | number | null>();
const id = useId();
</script>

<template>
    <UiField :label="label" :for="id" :error="error" :hint="hint" :required="required">
        <input
            :id="id"
            v-model="model"
            :type="type ?? 'text'"
            :required="required"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :disabled="disabled"
            :maxlength="maxlength"
            :inputmode="inputmode"
            :aria-invalid="!!error || undefined"
            class="h-9 w-full rounded-md border bg-white px-3 text-ink placeholder:text-gray-400 disabled:bg-gray-50 disabled:text-muted"
            :class="error ? 'border-red-400' : 'border-line focus:border-brand-500'"
        />
    </UiField>
</template>
