import { ApiError } from '@/api/http';
import { ref } from 'vue';

/**
 * Отправка формы: состояние отправки, ошибки по полям (422) и общая ошибка.
 */
export function useForm() {
    const submitting = ref(false);
    const errors = ref<Record<string, string>>({});
    const formError = ref<string | null>(null);

    async function submit<R>(action: () => Promise<R>): Promise<R | undefined> {
        submitting.value = true;
        errors.value = {};
        formError.value = null;
        try {
            return await action();
        } catch (e) {
            if (e instanceof ApiError && e.isValidation) {
                errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? '']));
                if (!Object.keys(errors.value).length) formError.value = e.message;
            } else {
                formError.value = e instanceof ApiError ? e.message : 'Не удалось выполнить действие.';
            }
            return undefined;
        } finally {
            submitting.value = false;
        }
    }

    function reset(): void {
        errors.value = {};
        formError.value = null;
    }

    return { submitting, errors, formError, submit, reset };
}
