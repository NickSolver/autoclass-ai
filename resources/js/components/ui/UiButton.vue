<script setup lang="ts">
import UiSpinner from './UiSpinner.vue';

withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        size?: 'sm' | 'md';
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'secondary', size: 'md', type: 'button' },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        class="inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60"
        :class="[
            block && 'w-full',
            size === 'sm' ? 'h-8 px-2.5 text-[13px]' : 'h-9 px-3.5',
            {
                'bg-brand-600 text-white hover:bg-brand-700': variant === 'primary',
                'border border-line bg-white text-ink hover:bg-gray-50': variant === 'secondary',
                'bg-red-600 text-white hover:bg-red-700': variant === 'danger',
                'text-muted hover:bg-gray-100 hover:text-ink': variant === 'ghost',
            },
        ]"
    >
        <UiSpinner v-if="loading" :size="14" />
        <slot></slot>
    </button>
</template>
