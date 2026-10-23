import { authApi } from '@/api';
import { ApiError, onApiError } from '@/api/http';
import type { Me, MfaSummary } from '@/api/types';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export const useAuthStore = defineStore('auth', () => {
    const me = ref<Me | null>(null);
    const loaded = ref(false);

    const user = computed(() => me.value?.user ?? null);
    const isAuthenticated = computed(() => me.value !== null);
    const permissions = computed(() => new Set(me.value?.permissions ?? []));

    /** Для отображения интерфейса. Сервер проверяет права независимо. */
    function can(...codes: string[]): boolean {
        return codes.some((c) => permissions.value.has(c));
    }

    async function fetchMe(): Promise<void> {
        try {
            me.value = await authApi.me();
        } catch (e) {
            if (!(e instanceof ApiError) || e.status !== 401) throw e;
            me.value = null;
        } finally {
            loaded.value = true;
        }
    }

    async function login(loginName: string, password: string): Promise<void> {
        me.value = await authApi.login(loginName, password);
    }

    async function logout(): Promise<void> {
        try {
            await authApi.logout();
        } finally {
            me.value = null;
        }
    }

    function setMfa(summary: MfaSummary): void {
        if (me.value) me.value.mfa = summary;
    }

    function markPasswordChanged(): void {
        if (me.value) me.value.user.must_change_password = false;
    }

    // Сессия истекла или пользователя отключили — локальное состояние сбрасывается.
    onApiError((error) => {
        if (error.status === 401) me.value = null;
        if (error.code === 'password_change_required' && me.value) me.value.user.must_change_password = true;
        if (error.code === 'mfa_verification_required' && me.value) me.value.mfa.status = 'verify_required';
        if (error.code === 'mfa_setup_required' && me.value) me.value.mfa.status = 'setup_required';
    });

    return { me, loaded, user, isAuthenticated, permissions, can, fetchMe, login, logout, markPasswordChanged, setMfa };
});
