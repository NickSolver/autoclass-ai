<script setup lang="ts">
import { orgApi } from '@/api';
import type { OrgNode, OrgUnitType } from '@/api/types';
import UiField from '@/components/ui/UiField.vue';
import { computed, onMounted, ref, useId, watch } from 'vue';

export interface OrgUnitValue {
    type: OrgUnitType;
    id: number;
}

/**
 * Выбор подразделения из дерева оргструктуры в области указанного права.
 * Недоступные узлы видны для контекста, но не выбираются.
 */
const props = defineProps<{
    permission: string;
    label?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    /** Разрешённые уровни выбора (по умолчанию — все). */
    types?: OrgUnitType[];
    placeholder?: string;
}>();
const model = defineModel<OrgUnitValue | null>({ default: null });
const id = useId();
const tree = ref<OrgNode[]>([]);
const loading = ref(true);
const failed = ref(false);

interface Flat {
    key: string;
    label: string;
    disabled: boolean;
}

const prefix: Record<OrgUnitType, string> = { group: '', company: '', dealership: 'ДЦ ', department: '' };

const flat = computed<Flat[]>(() => {
    const out: Flat[] = [];
    const walk = (nodes: OrgNode[], depth: number) => {
        for (const n of nodes) {
            const allowedType = !props.types || props.types.includes(n.type);
            out.push({
                key: `${n.type}:${n.id}`,
                label: `${'    '.repeat(depth)}${prefix[n.type]}${n.name}${n.is_active ? '' : ' (неактивно)'}`,
                disabled: !n.selectable || !allowedType,
            });
            walk(n.children, depth + 1);
        }
    };
    walk(tree.value, 0);
    return out;
});

const selected = computed({
    get: () => (model.value ? `${model.value.type}:${model.value.id}` : ''),
    set: (key: string) => {
        if (!key) {
            model.value = null;
            return;
        }
        const [type, rawId] = key.split(':');
        model.value = { type: type as OrgUnitType, id: Number(rawId) };
    },
});

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        tree.value = (await orgApi.tree(props.permission)).data;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(() => props.permission, load);
</script>

<template>
    <UiField :label="label" :for="id" :error="error ?? (failed ? 'Не удалось загрузить оргструктуру.' : undefined)" :hint="hint" :required="required">
        <select
            :id="id"
            v-model="selected"
            :disabled="loading"
            :required="required"
            class="h-9 w-full rounded-md border bg-white px-2.5 text-ink"
            :class="error ? 'border-red-400' : 'border-line'"
        >
            <option value="">{{ loading ? 'Загрузка…' : (placeholder ?? 'Выберите подразделение') }}</option>
            <option v-for="o in flat" :key="o.key" :value="o.key" :disabled="o.disabled">{{ o.label }}</option>
        </select>
    </UiField>
</template>
