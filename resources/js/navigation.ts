/**
 * Меню портала. Модули будущих этапов показываются явно недоступными
 * (страница «Модуль в разработке») — без фиктивных данных и действий.
 */
export interface NavItem {
    label: string;
    to: string;
    /** Пункт виден, если есть хотя бы одно из прав (пусто — всем). */
    permissions?: string[];
    /** Этап ТЗ, на котором модуль появится; задан — модуль недоступен. */
    stage?: string;
    icon: IconName;
}

export interface NavSection {
    title: string | null;
    items: NavItem[];
}

export type ExtraIconName = 'chevron-right' | 'chevron-down' | 'logout' | 'menu' | 'x' | 'plus' | 'search' | 'lock' | 'alert' | 'check' | 'clock' | 'key' | 'play' | 'download' | 'refresh' | 'ban';

export type IconName =
    | 'home' | 'phone' | 'upload' | 'inbox' | 'scale' | 'chart' | 'flow' | 'checklist'
    | 'plug' | 'cpu' | 'building' | 'store' | 'layers' | 'people' | 'user' | 'shield' | 'log' | 'tree';

export interface UnavailableModule {
    title: string;
    stage: string;
    description: string;
}

/** Описание недоступных модулей — по разделам ТЗ, без обещаний сроков. */
export const unavailableModules: Record<string, UnavailableModule> = {
};

export const navigation: NavSection[] = [
    { title: null, items: [{ label: 'Главная', to: '/', icon: 'home' }] },
    {
        title: 'Звонки',
        items: [
            { label: 'Звонки', to: '/calls', permissions: ['calls.view'], icon: 'phone' },
            { label: 'Загрузка', to: '/calls/upload', permissions: ['calls.upload'], icon: 'upload' },
            { label: 'Ручные проверки', to: '/review', permissions: ['review.assign', 'review.resolve'], icon: 'inbox' },
            { label: 'Апелляции', to: '/appeals', permissions: ['appeal.create', 'appeal.resolve', 'review.assign'], icon: 'scale' },
            { label: 'Аналитика', to: '/analytics', permissions: ['analytics.view'], icon: 'chart' },
        ],
    },
    {
        title: 'Настройка оценки',
        items: [
            { label: 'Бизнес-процессы', to: '/workflows', permissions: ['workflow.design', 'workflow.publish'], icon: 'flow' },
            { label: 'Чек-листы', to: '/checklists', permissions: ['checklist.design', 'checklist.publish'], icon: 'checklist' },
            { label: 'Источники звонков', to: '/sources', permissions: ['integrations.manage'], icon: 'plug' },
            { label: 'ИИ-провайдеры', to: '/ai-providers', permissions: ['ai.manage', 'ai.logs.view'], icon: 'cpu' },
        ],
    },
    {
        title: 'Организация',
        items: [
            { label: 'Структура', to: '/org', permissions: ['organization.view'], icon: 'tree' },
            { label: 'Компании', to: '/org/companies', permissions: ['organization.view'], icon: 'building' },
            { label: 'Дилерские центры', to: '/org/dealerships', permissions: ['organization.view'], icon: 'store' },
            { label: 'Отделы', to: '/org/departments', permissions: ['organization.view'], icon: 'layers' },
            { label: 'Сотрудники', to: '/employees', permissions: ['employees.view'], icon: 'people' },
        ],
    },
    {
        title: 'Доступ',
        items: [
            { label: 'Пользователи', to: '/users', permissions: ['users.view'], icon: 'user' },
            { label: 'Роли и права', to: '/roles', permissions: ['roles.manage', 'users.create', 'users.update'], icon: 'shield' },
            { label: 'Журнал аудита', to: '/audit', permissions: ['audit.view'], icon: 'log' },
        ],
    },
];
