<script setup lang="ts">
defineProps<{ label: string; hint?: string; disabled?: boolean }>();
const model = defineModel<boolean>();
</script>

<template>
    <label class="flex cursor-pointer items-start gap-2" :class="disabled && 'cursor-not-allowed opacity-60'">
        <input v-model="model" type="checkbox" :disabled="disabled" class="mt-0.5 size-4 rounded border-line accent-brand-600" />
        <span>
            <span class="text-ink">{{ label }}</span>
            <span v-if="hint" class="block text-[12px] text-muted">{{ hint }}</span>
        </span>
    </label>
</template>
