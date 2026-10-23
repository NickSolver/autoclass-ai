<script setup lang="ts">
/** Подпись, подсказка и ошибка вокруг любого поля ввода. */
defineProps<{ label?: string; for?: string; error?: string; hint?: string; required?: boolean }>();
</script>

<template>
    <div class="space-y-1">
        <label v-if="label" :for="$props.for" class="block text-[13px] font-medium text-ink">
            {{ label }}<span v-if="required" class="text-red-600" aria-hidden="true"> *</span>
        </label>
        <slot></slot>
        <p v-if="error" class="text-[12px] text-red-600" role="alert">{{ error }}</p>
        <p v-else-if="hint" class="text-[12px] text-muted">{{ hint }}</p>
    </div>
</template>
