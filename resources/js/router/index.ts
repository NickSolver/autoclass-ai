import { useAuthStore } from '@/stores/auth';
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

declare module 'vue-router' {
    interface RouteMeta {
        title?: string;
        /** Доступно без входа (экран входа, восстановление пароля). */
        public?: boolean;
        /** Хотя бы одно из прав. Только для интерфейса — сервер проверяет сам. */
        permissions?: string[];
        layout?: 'auth' | 'app';
        breadcrumbs?: { label: string; to?: string }[];
    }
}

const org = { label: 'Организация' };
const access = { label: 'Доступ' };
const setup = { label: 'Настройка оценки' };

const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { public: true, layout: 'auth', title: 'Вход' } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/auth/ForgotPasswordView.vue'), meta: { public: true, layout: 'auth', title: 'Восстановление пароля' } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/views/auth/ResetPasswordView.vue'), meta: { public: true, layout: 'auth', title: 'Новый пароль' } },
    { path: '/mfa/verify', name: 'mfa-verify', component: () => import('@/views/auth/MfaVerifyView.vue'), meta: { layout: 'auth', title: 'Код подтверждения' } },
    { path: '/mfa/setup', name: 'mfa-setup', component: () => import('@/views/auth/MfaSetupView.vue'), meta: { layout: 'auth', title: 'Двухфакторная аутентификация' } },
    { path: '/change-password', name: 'change-password', component: () => import('@/views/auth/ForceChangePasswordView.vue'), meta: { layout: 'auth', title: 'Смена временного пароля' } },

    { path: '/', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { title: 'Главная' } },
    { path: '/profile', name: 'profile', component: () => import('@/views/profile/ProfileView.vue'), meta: { title: 'Профиль', breadcrumbs: [{ label: 'Профиль' }] } },

    { path: '/org', name: 'org', component: () => import('@/views/org/OrgStructureView.vue'), meta: { title: 'Оргструктура', permissions: ['organization.view'], breadcrumbs: [org, { label: 'Структура' }] } },
    { path: '/org/companies', name: 'companies', component: () => import('@/views/org/CompaniesView.vue'), meta: { title: 'Компании', permissions: ['organization.view'], breadcrumbs: [org, { label: 'Компании' }] } },
    { path: '/org/dealerships', name: 'dealerships', component: () => import('@/views/org/DealershipsView.vue'), meta: { title: 'Дилерские центры', permissions: ['organization.view'], breadcrumbs: [org, { label: 'Дилерские центры' }] } },
    { path: '/org/departments', name: 'departments', component: () => import('@/views/org/DepartmentsView.vue'), meta: { title: 'Отделы', permissions: ['organization.view'], breadcrumbs: [org, { label: 'Отделы' }] } },
    { path: '/employees', name: 'employees', component: () => import('@/views/employees/EmployeesView.vue'), meta: { title: 'Сотрудники', permissions: ['employees.view'], breadcrumbs: [org, { label: 'Сотрудники' }] } },
    { path: '/employees/:id(\\d+)', name: 'employee', component: () => import('@/views/employees/EmployeeDetailView.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Сотрудник', permissions: ['employees.view'], breadcrumbs: [org, { label: 'Сотрудники', to: '/employees' }, { label: 'Карточка' }] } },

    { path: '/users', name: 'users', component: () => import('@/views/users/UsersView.vue'), meta: { title: 'Пользователи', permissions: ['users.view'], breadcrumbs: [access, { label: 'Пользователи' }] } },
    { path: '/users/:id(\\d+)', name: 'user', component: () => import('@/views/users/UserDetailView.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Пользователь', permissions: ['users.view'], breadcrumbs: [access, { label: 'Пользователи', to: '/users' }, { label: 'Учётная запись' }] } },
    { path: '/roles', name: 'roles', component: () => import('@/views/roles/RolesView.vue'), meta: { title: 'Роли и права', permissions: ['roles.manage', 'users.create', 'users.update'], breadcrumbs: [access, { label: 'Роли и права' }] } },
    { path: '/roles/:id(\\d+|new)', name: 'role', component: () => import('@/views/roles/RoleDetailView.vue'), props: (r) => ({ id: r.params.id === 'new' ? null : Number(r.params.id) }), meta: { title: 'Роль', permissions: ['roles.manage', 'users.create', 'users.update'], breadcrumbs: [access, { label: 'Роли и права', to: '/roles' }, { label: 'Роль' }] } },
    { path: '/audit', name: 'audit', component: () => import('@/views/audit/AuditView.vue'), meta: { title: 'Журнал аудита', permissions: ['audit.view'], breadcrumbs: [access, { label: 'Журнал аудита' }] } },

    { path: '/calls', name: 'calls', component: () => import('@/views/calls/CallsView.vue'), meta: { title: 'Звонки', permissions: ['calls.view'], breadcrumbs: [{ label: 'Звонки' }] } },
    { path: '/calls/upload', name: 'calls-upload', component: () => import('@/views/calls/CallUploadView.vue'), meta: { title: 'Загрузка звонков', permissions: ['calls.upload'], breadcrumbs: [{ label: 'Звонки', to: '/calls' }, { label: 'Загрузка' }] } },
    { path: '/calls/:id(\\d+)', name: 'call', component: () => import('@/views/calls/CallDetailView.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Звонок', permissions: ['calls.view'], breadcrumbs: [{ label: 'Звонки', to: '/calls' }, { label: 'Карточка звонка' }] } },

    { path: '/workflows', name: 'workflows', component: () => import('@/views/workflows/WorkflowsView.vue'), meta: { title: 'Бизнес-процессы', permissions: ['workflow.design', 'workflow.publish'], breadcrumbs: [setup, { label: 'Бизнес-процессы' }] } },
    { path: '/workflows/:id(\\d+)', name: 'workflow', component: () => import('@/views/workflows/WorkflowEditorView.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Бизнес-процесс', permissions: ['workflow.design', 'workflow.publish'], breadcrumbs: [setup, { label: 'Бизнес-процессы', to: '/workflows' }, { label: 'Редактор' }] } },
    { path: '/checklists', name: 'checklists', component: () => import('@/views/checklists/ChecklistsView.vue'), meta: { title: 'Чек-листы', permissions: ['checklist.design', 'checklist.publish'], breadcrumbs: [setup, { label: 'Чек-листы' }] } },
    { path: '/checklists/:id(\\d+)', name: 'checklist', component: () => import('@/views/checklists/ChecklistEditorView.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Чек-лист', permissions: ['checklist.design', 'checklist.publish'], breadcrumbs: [setup, { label: 'Чек-листы', to: '/checklists' }, { label: 'Редактор' }] } },
    { path: '/review', name: 'review', component: () => import('@/views/review/ReviewQueueView.vue'), meta: { title: 'Ручные проверки', permissions: ['review.assign', 'review.resolve'], breadcrumbs: [{ label: 'Звонки', to: '/calls' }, { label: 'Ручные проверки' }] } },
    { path: '/appeals', name: 'appeals', component: () => import('@/views/review/AppealsView.vue'), meta: { title: 'Апелляции', permissions: ['appeal.create', 'appeal.resolve', 'review.assign'], breadcrumbs: [{ label: 'Звонки', to: '/calls' }, { label: 'Апелляции' }] } },
    { path: '/analytics', name: 'analytics', component: () => import('@/views/analytics/AnalyticsView.vue'), meta: { title: 'Аналитика', permissions: ['analytics.view'], breadcrumbs: [{ label: 'Аналитика' }] } },
    { path: '/ai-providers', name: 'ai-providers', component: () => import('@/views/ai/AiProvidersView.vue'), meta: { title: 'ИИ-провайдеры', permissions: ['ai.manage', 'ai.logs.view'], breadcrumbs: [setup, { label: 'ИИ-провайдеры' }] } },

    { path: '/modules/:module', name: 'module', component: () => import('@/views/ModuleUnavailableView.vue'), props: true, meta: { title: 'Модуль в разработке' } },
    { path: '/forbidden', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue'), meta: { title: 'Нет доступа' } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Страница не найдена' } },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.loaded) await auth.fetchMe();

    if (to.meta.public) {
        return auth.isAuthenticated && to.name === 'login' ? { name: 'home' } : true;
    }
    if (!auth.isAuthenticated) {
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
    }
    // Сначала второй фактор, затем смена временного пароля.
    const mfa = auth.me?.mfa.status;
    if (mfa === 'verify_required') return to.name === 'mfa-verify' ? true : { name: 'mfa-verify', query: { redirect: to.fullPath } };
    if (to.name === 'mfa-verify') return { name: 'home' };
    if (mfa === 'setup_required') return to.name === 'mfa-setup' ? true : { name: 'mfa-setup' };
    if (to.name === 'mfa-setup') return auth.me?.mfa.enabled ? { name: 'profile' } : true;
    if (auth.user?.must_change_password && to.name !== 'change-password') {
        return { name: 'change-password' };
    }
    if (!auth.user?.must_change_password && to.name === 'change-password') {
        return { name: 'home' };
    }
    if (to.meta.permissions?.length && !auth.can(...to.meta.permissions)) {
        return { name: 'forbidden', replace: true };
    }
    return true;
});

router.afterEach((to) => {
    document.title = [to.meta.title, import.meta.env.VITE_APP_NAME || 'Автокласс AI'].filter(Boolean).join(' — ');
});
