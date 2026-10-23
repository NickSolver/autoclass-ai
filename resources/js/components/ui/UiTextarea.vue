<script setup lang="ts">
import { useId } from 'vue';
import UiField from './UiField.vue';

defineProps<{ label?: string; error?: string; hint?: string; required?: boolean; rows?: number; maxlength?: number }>();
const model = defineModel<string | null>();
const id = useId();
</script>

<template>
    <UiField :label="label" :for="id" :error="error" :hint="hint" :required="required">
        <textarea
            :id="id"
            v-model="model"
            :rows="rows ?? 3"
            :required="required"
            :maxlength="maxlength"
            class="w-full rounded-md border bg-white px-3 py-2 text-ink"
            :class="error ? 'border-red-400' : 'border-line focus:border-brand-500'"
        ></textarea>
    </UiField>
</template>
