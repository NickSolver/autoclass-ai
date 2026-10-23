<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';

// layer="top" — поверх других модальных окон (например, подтверждение кодом поверх формы).
const props = defineProps<{ title: string; size?: 'md' | 'lg'; layer?: 'top' }>();
const open = defineModel<boolean>('open', { required: true });
const panel = ref<HTMLElement | null>(null);
let previouslyFocused: Element | null = null;

watch(open, async (value) => {
    if (value) {
        previouslyFocused = document.activeElement;
        await nextTick();
        panel.value?.querySelector<HTMLElement>('input, select, textarea, button:not([data-close])')?.focus();
    } else if (previouslyFocused instanceof HTMLElement) {
        previouslyFocused.focus();
    }
});

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') open.value = false;
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 flex items-start justify-center overflow-y-auto bg-gray-900/40 p-4 sm:p-8" :class="props.layer === 'top' ? 'z-[70]' : 'z-50'" @keydown="onKeydown" @mousedown.self="open = false">
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="props.title"
                class="mt-[6vh] w-full rounded-xl bg-white shadow-xl"
                :class="size === 'lg' ? 'max-w-3xl' : 'max-w-lg'"
            >
                <header class="flex items-center justify-between border-b border-line px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold">{{ props.title }}</h2>
                    <button data-close type="button" class="rounded p-1 text-muted hover:bg-gray-100" aria-label="Закрыть" @click="open = false">
                        <AppIcon name="x" />
                    </button>
                </header>
                <div class="px-5 py-4"><slot></slot></div>
                <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3"><slot name="footer"></slot></footer>
            </div>
        </div>
    </Teleport>
</template>
