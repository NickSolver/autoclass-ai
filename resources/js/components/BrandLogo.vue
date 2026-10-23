<script setup lang="ts">
/** Текстовый логотип портала (по образцу дизайн-референса ТЗ; графический логотип — от заказчика). */
defineProps<{ inverted?: boolean }>();
</script>

<template>
    <span class="flex flex-col leading-none" :class="inverted ? 'text-white' : 'text-brand-700'">
        <span class="text-[22px] font-extrabold tracking-[0.06em]">АВТОКЛАСС</span>
        <span class="mt-1 whitespace-nowrap text-[10.5px] font-medium" :class="inverted ? 'text-white/70' : 'text-muted'">Ваш Эксперт в выборе автомобиля</span>
    </span>
</template>
