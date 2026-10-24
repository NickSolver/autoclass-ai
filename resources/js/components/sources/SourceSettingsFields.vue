<script setup lang="ts">
import type { SourceField } from '@/api/types';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';

/** Поля настроек, объявленные адаптером источника. */
defineProps<{ fields: SourceField[]; errors?: Record<string, string>; disabled?: boolean }>();
const model = defineModel<Record<string, string | number | null>>({ required: true });
</script>

<template>
    <template v-for="f in fields" :key="f.key">
        <UiSelect
            v-if="f.type === 'select'"
            v-model="model[f.key]"
            :label="f.label"
            :hint="f.hint"
            :required="f.required"
            :disabled="disabled"
            :error="errors?.[`settings.${f.key}`]"
            :options="Object.entries(f.options ?? {}).map(([value, label]) => ({ value, label }))"
        />
        <UiInput
            v-else
            v-model="model[f.key]"
            :type="f.type === 'number' ? 'number' : 'text'"
            :label="f.label"
            :hint="f.hint"
            :required="f.required"
            :disabled="disabled"
            :error="errors?.[`settings.${f.key}`]"
        />
    </template>
</template>
