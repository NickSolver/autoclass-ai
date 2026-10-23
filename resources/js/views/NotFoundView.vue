<script setup lang="ts">
import UiCard from '@/components/ui/UiCard.vue';
</script>

<template>
    <UiCard>
        <div class="py-10 text-center">
            <h1 class="text-lg font-semibold">Страница не найдена</h1>
            <p class="mt-2 text-[13px] text-muted">Проверьте адрес или вернитесь на главную.</p>
            <RouterLink to="/" class="mt-4 inline-block text-[13px] text-brand-700 hover:underline">На главную</RouterLink>
        </div>
    </UiCard>
</template>
