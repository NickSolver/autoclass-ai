<script setup lang="ts">
import type { ExtraIconName, IconName } from '@/navigation';
import AppIcon from './AppIcon.vue';

/** Плитка показателя. Значение null — «нет данных» (не ноль). */
defineProps<{ label: string; value: number | null; icon: IconName | ExtraIconName; tone?: 'blue' | 'green' | 'amber' | 'red'; to?: string }>();
</script>

<template>
    <component
        :is="to ? 'RouterLink' : 'div'"
        :to="to"
        class="flex items-center gap-3 rounded-xl border border-line bg-white px-4 py-3.5 shadow-[0_1px_2px_rgba(15,47,110,0.04)]"
        :class="to && 'transition-colors hover:border-brand-200'"
    >
        <span
            class="grid size-11 shrink-0 place-items-center rounded-full"
            :class="{
                'bg-brand-50 text-brand-600': !tone || tone === 'blue',
                'bg-emerald-50 text-emerald-600': tone === 'green',
                'bg-amber-50 text-amber-600': tone === 'amber',
                'bg-red-50 text-red-600': tone === 'red',
            }"
        >
            <AppIcon :name="icon" :size="20" />
        </span>
        <span class="min-w-0">
            <span class="block truncate text-[12px] text-muted">{{ label }}</span>
            <span class="block text-[24px] font-bold leading-tight text-ink">{{ value ?? '—' }}</span>
        </span>
    </component>
</template>
