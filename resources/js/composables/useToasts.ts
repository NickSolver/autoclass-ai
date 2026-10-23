import { ref } from 'vue';

export interface Toast {
    id: number;
    message: string;
    tone: 'success' | 'danger' | 'info';
}

const toasts = ref<Toast[]>([]);
let seq = 0;

export function useToasts() {
    function push(message: string, tone: Toast['tone'] = 'success'): void {
        const id = ++seq;
        toasts.value.push({ id, message, tone });
        setTimeout(() => dismiss(id), 4500);
    }

    function dismiss(id: number): void {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    return { toasts, push, dismiss };
}
