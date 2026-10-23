<script setup lang="ts">
import UiCard from '@/components/ui/UiCard.vue';
</script>

<template>
    <UiCard>
        <div class="py-10 text-center">
            <h1 class="text-lg font-semibold">Нет доступа</h1>
            <p class="mt-2 text-[13px] text-muted">У вашей учётной записи нет прав на этот раздел. Если доступ нужен для работы, обратитесь к администратору.</p>
            <RouterLink to="/" class="mt-4 inline-block text-[13px] text-brand-700 hover:underline">На главную</RouterLink>
        </div>
    </UiCard>
</template>
