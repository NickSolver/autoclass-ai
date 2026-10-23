<script setup lang="ts">
import BrandLogo from '@/components/BrandLogo.vue';
import StepUpDialog from '@/components/mfa/StepUpDialog.vue';
import ToastHost from '@/components/ToastHost.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import { navigation } from '@/navigation';
import { formatDateTime } from '@/utils/format';
import { useAuthStore } from '@/stores/auth';
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const sidebarOpen = ref(false);
const menuOpen = ref(false);

// Пункты без прав не показываются; пустые разделы скрываются.
const sections = computed(() =>
    navigation
        .map((s) => ({ ...s, items: s.items.filter((i) => !i.permissions || auth.can(...i.permissions)) }))
        .filter((s) => s.items.length > 0),
);

// Активен самый точный пункт: «Загрузка» (/calls/upload), а не «Звонки» (/calls).
const activeTo = computed(() => {
    const matches = sections.value
        .flatMap((s) => s.items.map((i) => i.to))
        .filter((to) => (to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(`${to}/`)));
    return matches.sort((a, b) => b.length - a.length)[0];
});

function isActive(to: string): boolean {
    return activeTo.value === to;
}

const initials = computed(() =>
    (auth.user?.name ?? '')
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase(),
);

watch(() => route.fullPath, () => {
    sidebarOpen.value = false;
    menuOpen.value = false;
});

// Под именем — первая роль пользователя (как в дизайн-референсе); полный список — в профиле.
const roleLabel = computed(() => auth.me?.role_assignments[0]?.role ?? 'Роль не назначена');
const version = import.meta.env.VITE_APP_VERSION ?? '1.0';
const year = new Date().getFullYear();

async function logout(): Promise<void> {
    await auth.logout();
    await router.replace({ name: 'login' });
}
</script>

<template>
    <div class="min-h-svh">
        <!-- Верхняя панель на всю ширину -->
        <header class="fixed inset-x-0 top-0 z-40 flex h-16 items-center border-b border-line bg-white">
            <div class="flex h-full shrink-0 items-center gap-2 px-4 lg:w-64 lg:px-6">
                <button type="button" class="rounded p-1.5 text-muted hover:bg-gray-100 lg:hidden" aria-label="Открыть меню" @click="sidebarOpen = !sidebarOpen">
                    <AppIcon name="menu" />
                </button>
                <RouterLink to="/" aria-label="На главную"><BrandLogo /></RouterLink>
            </div>
            <nav aria-label="Навигационная цепочка" class="hidden min-w-0 flex-1 px-6 md:block">
                <ol class="flex items-center gap-1 truncate text-[13px] text-muted">
                    <li><RouterLink to="/" class="hover:text-ink">Главная</RouterLink></li>
                    <template v-for="(crumb, i) in route.meta.breadcrumbs ?? []" :key="i">
                        <li aria-hidden="true"><AppIcon name="chevron-right" :size="14" /></li>
                        <li class="truncate">
                            <RouterLink v-if="crumb.to" :to="crumb.to" class="hover:text-ink">{{ crumb.label }}</RouterLink>
                            <span v-else :class="i === (route.meta.breadcrumbs?.length ?? 0) - 1 && 'text-ink'">{{ crumb.label }}</span>
                        </li>
                    </template>
                </ol>
            </nav>
            <div class="relative ml-auto pr-3 sm:pr-6">
                <button
                    type="button"
                    class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-canvas"
                    :aria-expanded="menuOpen"
                    aria-haspopup="menu"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="grid size-9 place-items-center rounded-full bg-brand-900 text-[13px] font-semibold text-white">{{ initials }}</span>
                    <span class="hidden max-w-56 text-left sm:block">
                        <span class="block truncate text-[13px] font-semibold text-ink">{{ auth.user?.name }}</span>
                        <span class="block truncate text-[12px] text-muted">{{ roleLabel }}</span>
                    </span>
                    <AppIcon name="chevron-down" :size="14" class="text-muted" />
                </button>
                <div v-if="menuOpen" class="fixed inset-0 z-10" @click="menuOpen = false"></div>
                <div v-if="menuOpen" role="menu" class="absolute right-3 z-20 mt-1 w-60 rounded-lg border border-line bg-white py-1 shadow-lg sm:right-6">
                    <div class="border-b border-line px-3 py-2">
                        <p class="truncate text-[13px] font-medium">{{ auth.user?.name }}</p>
                        <p class="truncate text-[12px] text-muted">{{ auth.user?.login }}</p>
                    </div>
                    <RouterLink to="/profile" role="menuitem" class="flex items-center gap-2 px-3 py-2 text-[13px] hover:bg-gray-50">
                        <AppIcon name="user" :size="16" /> Профиль и сессии
                    </RouterLink>
                    <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] text-red-700 hover:bg-gray-50" @click="logout">
                        <AppIcon name="logout" :size="16" /> Выйти
                    </button>
                </div>
            </div>
        </header>

        <!-- Боковое меню -->
        <div v-if="sidebarOpen" class="fixed inset-0 top-16 z-30 bg-gray-900/40 lg:hidden" @click="sidebarOpen = false"></div>
        <aside
            class="fixed bottom-0 left-0 top-16 z-30 flex w-64 flex-col bg-gradient-to-b from-sidebar to-sidebar-deep text-white/85 transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            aria-label="Основное меню"
        >
            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <div v-for="section in sections" :key="section.title ?? 'main'" class="mt-4 first:mt-0">
                    <p v-if="section.title" class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-white/45">{{ section.title }}</p>
                    <RouterLink
                        v-for="item in section.items"
                        :key="item.to"
                        :to="item.to"
                        class="flex items-center gap-3 rounded-lg px-3 py-[7px] text-[13.5px] transition-colors"
                        :class="isActive(item.to) ? 'bg-white/15 font-semibold text-white shadow-sm' : 'hover:bg-white/10 hover:text-white'"
                        :aria-current="isActive(item.to) ? 'page' : undefined"
                    >
                        <AppIcon :name="item.icon" :size="18" />
                        <span class="flex-1 truncate">{{ item.label }}</span>
                        <AppIcon v-if="item.stage" name="lock" :size="13" class="text-white/40" title="Модуль в разработке" />
                    </RouterLink>
                </div>
            </nav>
            <footer class="shrink-0 border-t border-white/10 px-6 py-4 text-[11px] leading-relaxed text-white/55">
                <p>© {{ year }} Автокласс</p>
                <p>Ваш Эксперт в выборе автомобиля</p>
                <p>Версия {{ version }}</p>
            </footer>
        </aside>

        <div class="flex min-h-full flex-col pt-16 lg:pl-64">
            <main class="mx-auto w-full max-w-[1400px] flex-1 px-4 py-6 sm:px-6">
                <div
                    v-if="auth.me?.mfa.status === 'setup_recommended'"
                    class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
                    role="status"
                >
                    <AppIcon name="key" />
                    <span class="flex-1">
                        Для вашей роли обязательна двухфакторная аутентификация. Подключите её до
                        <strong>{{ formatDateTime(auth.me.mfa.grace_until) }}</strong> — после этого без неё войти будет нельзя.
                    </span>
                    <RouterLink to="/mfa/setup" class="rounded-md bg-amber-600 px-3 py-1.5 font-medium text-white hover:bg-amber-700">Подключить</RouterLink>
                </div>
                <slot></slot>
            </main>
        </div>
        <ToastHost />
        <StepUpDialog />
    </div>
</template>
