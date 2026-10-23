<script setup lang="ts">
import type { OrgNode } from '@/api/types';
import AppIcon from '@/components/ui/AppIcon.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import { ref } from 'vue';

const props = defineProps<{ node: OrgNode; depth: number }>();
const expanded = ref(props.depth < 2);

const icon = { group: 'tree', company: 'building', dealership: 'store', department: 'layers' } as const;
const typeLabel = { group: 'Группа', company: 'Компания', dealership: 'Дилерский центр', department: 'Отдел' } as const;
const links = { group: null, company: '/org/companies', dealership: '/org/dealerships', department: '/org/departments' } as const;
</script>

<template>
    <li>
        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-gray-50" :style="{ paddingLeft: `${depth * 1.5 + 0.5}rem` }">
            <button
                v-if="node.children.length"
                type="button"
                class="rounded p-0.5 text-muted hover:bg-gray-200"
                :aria-expanded="expanded"
                :aria-label="expanded ? 'Свернуть' : 'Развернуть'"
                @click="expanded = !expanded"
            >
                <AppIcon :name="expanded ? 'chevron-down' : 'chevron-right'" :size="14" />
            </button>
            <span v-else class="w-[18px]"></span>
            <AppIcon :name="icon[node.type]" :size="16" class="text-brand-600" />
            <RouterLink v-if="links[node.type] && node.selectable" :to="links[node.type]!" class="font-medium hover:underline">{{ node.name }}</RouterLink>
            <span v-else class="font-medium">{{ node.name }}</span>
            <span class="text-[12px] text-muted">{{ typeLabel[node.type] }}</span>
            <UiBadge v-if="!node.is_active" tone="warning">неактивно</UiBadge>
            <UiBadge v-if="!node.selectable">контекст</UiBadge>
        </div>
        <ul v-if="expanded && node.children.length">
            <OrgTreeNode v-for="child in node.children" :key="`${child.type}:${child.id}`" :node="child" :depth="depth + 1" />
        </ul>
    </li>
</template>
