<script setup lang="ts">
import { useToasts } from '@/composables/useToasts';
import AppIcon from './ui/AppIcon.vue';

const { toasts, dismiss } = useToasts();
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2" aria-live="polite">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="pointer-events-auto flex items-start gap-2 rounded-lg px-4 py-3 text-[13px] text-white shadow-lg"
            :class="{ 'bg-emerald-700': t.tone === 'success', 'bg-red-700': t.tone === 'danger', 'bg-gray-800': t.tone === 'info' }"
        >
            <span class="flex-1">{{ t.message }}</span>
            <button type="button" aria-label="Скрыть" class="opacity-80 hover:opacity-100" @click="dismiss(t.id)"><AppIcon name="x" :size="16" /></button>
        </div>
    </div>
</template>
