<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { employeesApi, orgApi, usersApi, type Query } from '@/api';
import type { Paginated } from '@/api/types';
import { navigation } from '@/navigation';
import { useAuthStore } from '@/stores/auth';
import { scopeTypeLabels } from '@/utils/format';
import { computed, onMounted, ref } from 'vue';

/** Главная этапа A: без метрик и фиктивных цифр — только доступные разделы и права пользователя. */
const auth = useAuthStore();

const available = computed(() =>
    navigation
        .flatMap((s) => s.items)
        .filter((i) => i.to !== '/' && !i.stage && (!i.permissions || auth.can(...i.permissions))),
);
const upcoming = computed(() =>
    navigation.flatMap((s) => s.items).filter((i) => i.stage && (!i.permissions || auth.can(...i.permissions))),
);

// Реальные количества записей в области пользователя (total из пагинации API), без вычисляемых метрик.
interface Stat {
    label: string;
    icon: 'building' | 'store' | 'layers' | 'people' | 'user';
    to: string;
    permission: string;
    fetch: (q: Query) => Promise<Paginated<unknown>>;
    value: number | null;
}
const stats = ref<Stat[]>(
    [
        { label: 'Компании', icon: 'building', to: '/org/companies', permission: 'organization.view', fetch: orgApi.companies.list, value: null },
        { label: 'Дилерские центры', icon: 'store', to: '/org/dealerships', permission: 'organization.view', fetch: orgApi.dealerships.list, value: null },
        { label: 'Отделы', icon: 'layers', to: '/org/departments', permission: 'organization.view', fetch: orgApi.departments.list, value: null },
        { label: 'Сотрудники', icon: 'people', to: '/employees', permission: 'employees.view', fetch: employeesApi.list, value: null },
        { label: 'Пользователи', icon: 'user', to: '/users', permission: 'users.view', fetch: usersApi.list, value: null },
    ].filter((s) => auth.can(s.permission)) as Stat[],
);

onMounted(() => {
    stats.value.forEach(async (s) => {
        s.value = await s.fetch({ per_page: 1, is_active: 1 }).then((r) => r.meta.total).catch(() => null);
    });
});
</script>

<template>
    <PageHeader :title="`Здравствуйте, ${auth.user?.name ?? ''}`" description="Портал анализа и контроля качества телефонных звонков." />

    <div v-if="stats.length" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <StatTile v-for="s in stats" :key="s.to" :label="`${s.label} (активные)`" :value="s.value" :icon="s.icon" :to="s.to" />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <UiCard title="Разделы" description="Доступны с учётом ваших прав.">
                <div v-if="available.length" class="grid gap-2 sm:grid-cols-2">
                    <RouterLink
                        v-for="item in available"
                        :key="item.to"
                        :to="item.to"
                        class="flex items-center gap-3 rounded-lg border border-line px-3 py-2.5 hover:border-brand-200 hover:bg-brand-50"
                    >
                        <AppIcon :name="item.icon" class="text-brand-600" />
                        <span class="font-medium">{{ item.label }}</span>
                    </RouterLink>
                </div>
                <p v-else class="text-[13px] text-muted">Рабочих разделов пока нет. Если это неожиданно, обратитесь к администратору за назначением роли.</p>
            </UiCard>

            <UiCard v-if="upcoming.length" title="В разработке" description="Разделы следующих этапов. Пока без данных и действий.">
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="item in upcoming" :key="item.to" class="flex items-center gap-3 rounded-lg border border-dashed border-line px-3 py-2.5 text-muted">
                        <AppIcon :name="item.icon" />
                        <span>{{ item.label }}</span>
                        <span class="ml-auto text-[11px]">этап {{ item.stage }}</span>
                    </li>
                </ul>
            </UiCard>
        </div>

        <UiCard title="Ваш доступ">
            <dl class="space-y-3 text-[13px]">
                <div>
                    <dt class="text-muted">Подразделение</dt>
                    <dd>{{ auth.user?.home }}</dd>
                </div>
                <div v-if="auth.user?.employee">
                    <dt class="text-muted">Сотрудник</dt>
                    <dd>{{ auth.user.employee.full_name }}</dd>
                </div>
                <div>
                    <dt class="text-muted">Роли</dt>
                    <dd v-if="auth.me?.role_assignments.length" class="mt-1 space-y-1.5">
                        <div v-for="(a, i) in auth.me.role_assignments" :key="i">
                            <span class="font-medium">{{ a.role }}</span>
                            <span class="block text-muted">{{ scopeTypeLabels[a.scope_type] }}: {{ a.scope_label }}</span>
                        </div>
                    </dd>
                    <dd v-else class="text-muted">Роли не назначены</dd>
                </div>
            </dl>
            <RouterLink to="/profile" class="mt-4 inline-block text-[13px] text-brand-700 hover:underline">Профиль и сессии →</RouterLink>
        </UiCard>
    </div>
</template>
