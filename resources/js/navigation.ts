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
    review: { title: 'Очередь ручных проверок', stage: 'E', description: 'Задачи на проверку для звонков со статусом «Требуется вмешательство человека», исправления с обязательной причиной и аудитом.' },
    appeals: { title: 'Апелляции', stage: 'E', description: 'Обращения сотрудников на пересмотр оценки и их рассмотрение независимым контролёром.' },
    analytics: { title: 'Аналитика', stage: 'E', description: 'KPI по финальным результатам с честными знаменателями, динамика по компаниям, центрам и сотрудникам, экспорт.' },
    workflows: { title: 'Бизнес-процессы', stage: 'D', description: 'Визуальный конструктор маршрутизации звонков: условия, ветвления, выбор основного и дополнительных чек-листов, публикация версий.' },
    checklists: { title: 'Чек-листы', stage: 'D', description: 'Конструктор версионируемых чек-листов: критерии, веса, обязательность, критические признаки, тестирование на эталонном звонке.' },
    integrations: { title: 'Источники звонков', stage: 'F', description: 'Подключаемые модули Calltouch и телефонии «Лягушка». Подключаются после получения документации и тестового доступа.' },
    'ai-providers': { title: 'ИИ-провайдеры', stage: 'C', description: 'Настройка облачных сервисов распознавания речи и оценки, ключи, лимиты, тест подключения и статистика вызовов.' },
};

export const navigation: NavSection[] = [
    { title: null, items: [{ label: 'Главная', to: '/', icon: 'home' }] },
    {
        title: 'Звонки',
        items: [
            { label: 'Звонки', to: '/calls', permissions: ['calls.view'], icon: 'phone' },
            { label: 'Загрузка', to: '/calls/upload', permissions: ['calls.upload'], icon: 'upload' },
            { label: 'Ручные проверки', to: '/modules/review', permissions: ['review.assign', 'review.resolve'], stage: 'E', icon: 'inbox' },
            { label: 'Апелляции', to: '/modules/appeals', permissions: ['appeal.create', 'appeal.resolve'], stage: 'E', icon: 'scale' },
            { label: 'Аналитика', to: '/modules/analytics', permissions: ['analytics.view'], stage: 'E', icon: 'chart' },
        ],
    },
    {
        title: 'Настройка оценки',
        items: [
            { label: 'Бизнес-процессы', to: '/modules/workflows', permissions: ['workflow.design', 'workflow.publish'], stage: 'D', icon: 'flow' },
            { label: 'Чек-листы', to: '/modules/checklists', permissions: ['checklist.design', 'checklist.publish'], stage: 'D', icon: 'checklist' },
            { label: 'Источники звонков', to: '/modules/integrations', permissions: ['integrations.manage'], stage: 'F', icon: 'plug' },
            { label: 'ИИ-провайдеры', to: '/modules/ai-providers', permissions: ['ai.manage'], stage: 'C', icon: 'cpu' },
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
