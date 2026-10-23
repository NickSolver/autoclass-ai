<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <p class="font-medium text-ink">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-md text-[13px] text-muted">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot></slot></div>
    </div>
</template>
