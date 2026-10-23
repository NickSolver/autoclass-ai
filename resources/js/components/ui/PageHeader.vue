<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-[24px] font-bold tracking-tight text-ink">{{ title }}</h1>
            <p v-if="description" class="mt-1 text-[13px] text-muted">{{ description }}</p>
        </div>
        <div class="flex flex-wrap gap-2"><slot name="actions"></slot></div>
    </div>
</template>
