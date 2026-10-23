<script setup lang="ts">
import AppIcon from './AppIcon.vue';

withDefaults(defineProps<{ tone?: 'info' | 'warning' | 'danger' | 'success'; title?: string }>(), { tone: 'info' });
</script>

<template>
    <div
        class="flex gap-3 rounded-lg border px-4 py-3"
        :class="{
            'border-brand-200 bg-brand-50 text-brand-900': tone === 'info',
            'border-amber-200 bg-amber-50 text-amber-900': tone === 'warning',
            'border-red-200 bg-red-50 text-red-800': tone === 'danger',
            'border-emerald-200 bg-emerald-50 text-emerald-800': tone === 'success',
        }"
        :role="tone === 'danger' ? 'alert' : 'status'"
    >
        <AppIcon :name="tone === 'success' ? 'check' : 'alert'" class="mt-0.5" />
        <div class="min-w-0 flex-1">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div class="text-[13px]"><slot></slot></div>
        </div>
    </div>
</template>
