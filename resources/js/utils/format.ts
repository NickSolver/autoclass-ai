import type { ScopeTypeCode } from '@/api/types';

const dateTime = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
const dateOnly = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' });

export function formatDateTime(iso: string | null | undefined): string {
    return iso ? dateTime.format(new Date(iso)) : '—';
}

/** Дата без времени (YYYY-MM-DD) — без сдвига часового пояса. */
export function formatDate(ymd: string | null | undefined): string {
    if (!ymd) return '—';
    const [y, m, d] = ymd.split('-').map(Number);
    return dateOnly.format(new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1));
}

export const scopeTypeLabels: Record<ScopeTypeCode, string> = {
    group: 'Группа компаний',
    company: 'Компания',
    dealership: 'Дилерский центр',
    department: 'Отдел',
    own: 'Собственные записи',
};

export const departmentKinds: Record<string, string> = {
    sales: 'Продажи',
    service: 'Сервис',
    parts: 'Запчасти',
    call_center: 'Колл-центр',
    marketing: 'Маркетинг',
    other: 'Другое',
};

export const permissionCategories: Record<string, string> = {
    identity: 'Пользователи и роли',
    organization: 'Оргструктура и сотрудники',
    calls: 'Звонки и записи',
    evaluation: 'Оценки, проверки, апелляции',
    designers: 'Конструкторы',
    integrations: 'Интеграции и ИИ',
    analytics: 'Аналитика',
    audit: 'Аудит',
};

/** Человекочитаемые названия событий аудита; неизвестные показываются кодом. */
const auditActions: Record<string, string> = {
    'auth.login': 'Вход в систему',
    'auth.logout': 'Выход из системы',
    'auth.login_failed': 'Неудачная попытка входа',
    'auth.password_changed': 'Смена пароля',
    'auth.password_reset_requested': 'Запрос сброса пароля',
    'auth.password_reset_completed': 'Пароль сброшен по ссылке',
    'auth.password_reset_ignored': 'Запрос сброса пароля отклонён',
    'auth.session_terminated': 'Завершена сессия',
    'auth.sessions_terminated': 'Завершены сессии',
    'auth.mfa_enabled': 'Подключена двухфакторная аутентификация',
    'auth.mfa_disabled': 'Отключена двухфакторная аутентификация',
    'auth.mfa_failed': 'Неверный код второго фактора',
    'auth.mfa_reset': 'Сброшена двухфакторная аутентификация',
    'auth.mfa_recovery_code_used': 'Вход по резервному коду',
    'auth.mfa_recovery_codes_regenerated': 'Выпущены новые резервные коды',
    'auth.mfa_device_trusted': 'Устройство отмечено доверенным',
    'auth.mfa_devices_forgotten': 'Доверенные устройства удалены',
    'call.uploaded': 'Загружен звонок',
    'call.updated': 'Изменены сведения звонка',
    'call.excluded': 'Звонок исключён из обработки',
    'call.reprocess_requested': 'Запущена повторная обработка',
    'call.audio_downloaded': 'Скачана запись звонка',
    'call.audio_played': 'Прослушана запись звонка',
    'user.created': 'Создан пользователь',
    'user.updated': 'Изменён пользователь',
    'user.disabled': 'Пользователь отключён',
    'user.enabled': 'Пользователь включён',
    'user.password_set_by_admin': 'Временный пароль задан администратором',
    'user.role_granted': 'Назначена роль',
    'user.role_revoked': 'Отозвана роль',
    'user.bootstrap_superadmin': 'Создан первый суперадминистратор',
    'role.created': 'Создана роль',
    'role.updated': 'Изменена роль',
    'role.deleted': 'Удалена роль',
    'role.permissions_changed': 'Изменены права роли',
    'company_group.updated': 'Изменена группа компаний',
    'company.created': 'Создана компания',
    'company.updated': 'Изменена компания',
    'dealership.created': 'Создан дилерский центр',
    'dealership.updated': 'Изменён дилерский центр',
    'department.created': 'Создан отдел',
    'department.updated': 'Изменён отдел',
    'employee.created': 'Добавлен сотрудник',
    'employee.updated': 'Изменён сотрудник',
    'employee_assignment.created': 'Назначение сотрудника',
    'employee_assignment.updated': 'Изменено назначение',
    'employee_assignment.deleted': 'Удалено назначение',
};

export function auditActionLabel(action: string): string {
    return auditActions[action] ?? action;
}

export function scopeLabel(type: ScopeTypeCode): string {
    return scopeTypeLabels[type];
}

/** Длительность мм:сс или ч:мм:сс. */
export function formatDuration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    const total = Math.round(ms / 1000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = String(total % 60).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes < 1024) return `${bytes} Б`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(0)} КБ`;
    return `${(bytes / 1024 ** 2).toFixed(1).replace('.', ',')} МБ`;
}

export type StatusTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

/** Цвет статуса звонка: обработка — синий, ожидание следующего этапа — серый, ошибка — красный. */
export function callStatusTone(code: string): StatusTone {
    if (code === 'failed') return 'danger';
    if (code === 'completed') return 'success';
    if (code === 'excluded' || code === 'awaiting_transcription') return 'neutral';
    return 'info';
}
