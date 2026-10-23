<script setup lang="ts">
import BrandLogo from '@/components/BrandLogo.vue';
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-gradient-to-br from-sidebar via-sidebar-deep to-brand-900 px-4 py-10">
        <main class="w-full max-w-sm rounded-2xl bg-white p-7 shadow-2xl">
            <BrandLogo class="mb-6" />
            <slot></slot>
        </main>
        <p class="mt-6 text-center text-[12px] text-white/60">Закрытый портал группы компаний. Доступ выдаёт администратор.</p>
    </div>
</template>
