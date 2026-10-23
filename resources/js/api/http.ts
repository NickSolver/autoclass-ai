import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';

/**
 * HTTP-клиент API v1. Аутентификация — сессионная cookie; CSRF-токен axios берёт
 * из cookie XSRF-TOKEN и отправляет в заголовке X-XSRF-TOKEN.
 * Интерфейс не является защитой доступа: все проверки — на сервере.
 */
export const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export type ValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: ValidationErrors = {},
        public readonly code?: string,
        /** Тело ответа сервера — для ошибок с подробностями (например, дубль записи). */
        public readonly data: Record<string, unknown> = {},
    ) {
        super(message);
    }

    get isValidation(): boolean {
        return this.status === 422;
    }

    /** Первая ошибка по полю — для вывода под полем формы. */
    field(name: string): string | undefined {
        return this.errors[name]?.[0];
    }
}

/**
 * Повторное подтверждение кодом MFA для критичных действий: сервер отвечает 403 с кодом
 * mfa_confirmation_required, обработчик (диалог ввода кода) возвращает true после ввода —
 * и исходный запрос повторяется автоматически.
 */
type StepUpHandler = () => Promise<boolean>;
let stepUpHandler: StepUpHandler | null = null;

export function setStepUpHandler(handler: StepUpHandler): void {
    stepUpHandler = handler;
}

type Listener = (error: ApiError) => void;
const listeners: Listener[] = [];

/** Глобальная реакция на 401/403-смену пароля (подписывается auth-store, без циклических импортов). */
export function onApiError(listener: Listener): void {
    listeners.push(listener);
}

function messageFor(status: number, fallback?: string): string {
    if (fallback) return fallback;
    switch (status) {
        case 0:
            return 'Нет связи с сервером. Проверьте подключение.';
        case 401:
            return 'Требуется вход в систему.';
        case 403:
            return 'Недостаточно прав для выполнения действия.';
        case 404:
            return 'Объект не найден.';
        case 419:
            return 'Сессия устарела. Обновите страницу.';
        case 429:
            return 'Слишком много запросов. Повторите немного позже.';
        default:
            return status >= 500 ? 'Ошибка сервера. Попробуйте позже.' : 'Не удалось выполнить запрос.';
    }
}

http.interceptors.response.use(
    (response) => response,
    async (error: AxiosError<{ message?: string; errors?: ValidationErrors; code?: string }>) => {
        const status = error.response?.status ?? 0;
        const data = error.response?.data;
        const config = error.config as (InternalAxiosRequestConfig & { _stepUpRetried?: boolean }) | undefined;
        if (status === 403 && data?.code === 'mfa_confirmation_required' && stepUpHandler && config && !config._stepUpRetried) {
            if (await stepUpHandler()) {
                config._stepUpRetried = true;
                return http.request(config);
            }
        }
        // Для 429 из ограничителя входа сервер отдаёт осмысленное сообщение в errors.
        const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        const message = status === 429 && firstFieldError ? firstFieldError : messageFor(status, status >= 500 ? undefined : data?.message);
        const apiError = new ApiError(message, status, data?.errors ?? {}, data?.code, (data ?? {}) as Record<string, unknown>);
        listeners.forEach((l) => l(apiError));
        return Promise.reject(apiError);
    },
);
