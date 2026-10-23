<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { unavailableModules } from '@/navigation';
import { computed } from 'vue';

const props = defineProps<{ module: string }>();
const info = computed(() => unavailableModules[props.module]);
</script>

<template>
    <UiCard>
        <div class="mx-auto max-w-xl py-10 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-gray-100 text-muted"><AppIcon name="lock" :size="22" /></span>
            <h1 class="mt-4 text-lg font-semibold">{{ info?.title ?? 'Модуль' }} — в разработке</h1>
            <p v-if="info" class="mt-2 text-[13px] text-muted">{{ info.description }}</p>
            <p class="mt-4 text-[13px] text-muted">
                Раздел недоступен в текущей версии портала<template v-if="info"> (этап {{ info.stage }} по ТЗ)</template>.
                Данных и действий здесь пока нет.
            </p>
        </div>
    </UiCard>
</template>
