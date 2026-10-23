<script setup lang="ts">
withDefaults(defineProps<{ title?: string; description?: string; padded?: boolean }>(), { padded: true });
</script>

<template>
    <section class="rounded-xl border border-line bg-white shadow-[0_1px_2px_rgba(15,47,110,0.04)]">
        <header v-if="title || $slots.actions" class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-3.5">
            <div>
                <h2 class="text-[15px] font-bold text-ink">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-[13px] text-muted">{{ description }}</p>
            </div>
            <div class="flex flex-wrap gap-2"><slot name="actions"></slot></div>
        </header>
        <div :class="padded && 'p-5'"><slot></slot></div>
    </section>
</template>
