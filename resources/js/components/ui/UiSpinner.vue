<script setup lang="ts">
defineProps<{ size?: number }>();
</script>

<template>
    <svg :width="size ?? 18" :height="size ?? 18" viewBox="0 0 24 24" class="animate-spin" aria-hidden="true">
        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
        <path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    </svg>
</template>
