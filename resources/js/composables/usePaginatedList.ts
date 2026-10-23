import type { Query } from '@/api';
import { ApiError } from '@/api/http';
import type { PageMeta, Paginated } from '@/api/types';
import { onMounted, reactive, ref, shallowRef, watch, type Ref } from 'vue';

/**
 * Список с пагинацией и фильтрами. Изменение фильтров сбрасывает страницу и (с задержкой
 * для текстового поиска) перезагружает данные. Поздние ответы не перетирают свежие.
 */
export function usePaginatedList<T, F extends Query>(fetcher: (query: Query) => Promise<Paginated<T>>, initialFilters: F) {
    const filters = reactive({ ...initialFilters }) as F;
    const rows = shallowRef<T[]>([]) as Ref<T[]>;
    const meta = ref<PageMeta | null>(null);
    const page = ref(1);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let requestId = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    async function load(): Promise<void> {
        const id = ++requestId;
        loading.value = true;
        error.value = null;
        try {
            const result = await fetcher({ ...filters, page: page.value });
            if (id !== requestId) return;
            rows.value = result.data;
            meta.value = result.meta;
        } catch (e) {
            if (id === requestId) error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить данные.';
        } finally {
            if (id === requestId) loading.value = false;
        }
    }

    function goTo(p: number): void {
        page.value = p;
        void load();
    }

    watch(
        () => ({ ...filters }),
        (next, prev) => {
            page.value = 1;
            clearTimeout(timer);
            const onlySearchChanged = next.q !== prev.q && Object.keys(next).every((k) => k === 'q' || next[k] === prev[k]);
            timer = setTimeout(() => void load(), onlySearchChanged ? 300 : 0);
        },
    );

    onMounted(load);

    return { filters, rows, meta, page, loading, error, load, goTo };
}
