<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }>(), { tone: 'neutral' });
</script>

<template>
    <span
        class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-2 py-0.5 text-[12px] font-medium"
        :class="{
            'border-gray-200 bg-gray-50 text-gray-700': tone === 'neutral',
            'border-emerald-200 bg-emerald-50 text-emerald-700': tone === 'success',
            'border-amber-200 bg-amber-50 text-amber-800': tone === 'warning',
            'border-red-200 bg-red-50 text-red-700': tone === 'danger',
            'border-brand-200 bg-brand-50 text-brand-700': tone === 'info',
        }"
    >
        <span
            class="size-1.5 rounded-full"
            :class="{
                'bg-gray-400': tone === 'neutral',
                'bg-emerald-500': tone === 'success',
                'bg-amber-500': tone === 'warning',
                'bg-red-500': tone === 'danger',
                'bg-brand-500': tone === 'info',
            }"
            aria-hidden="true"
        ></span>
        <slot></slot>
    </span>
</template>
