<?php

namespace App\Domain\Identity;

/**
 * Реестр прав. Бизнес-логика проверяет только права — никогда не коды ролей.
 * Синхронизируется в таблицу permissions командой avtoklass:sync-permissions.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDisable = 'users.disable';
    case RolesManage = 'roles.manage';

    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';
    case EmployeesView = 'employees.view';
    case EmployeesManage = 'employees.manage';

    case CallsView = 'calls.view';
    case CallsUpload = 'calls.upload';
    case CallsDelete = 'calls.delete';
    case CallsReprocess = 'calls.reprocess';
    case AudioPlay = 'audio.play';
    case AudioDownload = 'audio.download';
    case TranscriptView = 'transcript.view';
    case TranscriptEdit = 'transcript.edit';

    case EvaluationView = 'evaluation.view';
    case EvaluationOverride = 'evaluation.override';
    case EvaluationFinalize = 'evaluation.finalize';
    case ReviewAssign = 'review.assign';
    case ReviewResolve = 'review.resolve';
    case AppealCreate = 'appeal.create';
    case AppealResolve = 'appeal.resolve';

    case WorkflowDesign = 'workflow.design';
    case WorkflowPublish = 'workflow.publish';
    case ChecklistDesign = 'checklist.design';
    case ChecklistPublish = 'checklist.publish';

    case IntegrationsManage = 'integrations.manage';
    case AiManage = 'ai.manage';
    case AiLogsView = 'ai.logs.view';
    case AnalyticsView = 'analytics.view';
    case AnalyticsExport = 'analytics.export';
    case AuditView = 'audit.view';

    public function category(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDisable, self::RolesManage => 'identity',
            self::OrganizationView, self::OrganizationManage, self::EmployeesView, self::EmployeesManage => 'organization',
            self::CallsView, self::CallsUpload, self::CallsDelete, self::CallsReprocess,
            self::AudioPlay, self::AudioDownload, self::TranscriptView, self::TranscriptEdit => 'calls',
            self::EvaluationView, self::EvaluationOverride, self::EvaluationFinalize,
            self::ReviewAssign, self::ReviewResolve, self::AppealCreate, self::AppealResolve => 'evaluation',
            self::WorkflowDesign, self::WorkflowPublish, self::ChecklistDesign, self::ChecklistPublish => 'designers',
            self::IntegrationsManage, self::AiManage, self::AiLogsView => 'integrations',
            self::AnalyticsView, self::AnalyticsExport => 'analytics',
            self::AuditView => 'audit',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'Просмотр пользователей',
            self::UsersCreate => 'Создание пользователей',
            self::UsersUpdate => 'Изменение пользователей и назначение ролей',
            self::UsersDisable => 'Отключение пользователей',
            self::RolesManage => 'Управление ролями и правами',
            self::OrganizationView => 'Просмотр оргструктуры',
            self::OrganizationManage => 'Управление оргструктурой',
            self::EmployeesView => 'Просмотр сотрудников',
            self::EmployeesManage => 'Управление сотрудниками и назначениями',
            self::CallsView => 'Просмотр звонков',
            self::CallsUpload => 'Загрузка звонков',
            self::CallsDelete => 'Удаление звонков',
            self::CallsReprocess => 'Повторная обработка звонков',
            self::AudioPlay => 'Прослушивание записей',
            self::AudioDownload => 'Скачивание записей',
            self::TranscriptView => 'Просмотр расшифровок',
            self::TranscriptEdit => 'Исправление расшифровок',
            self::EvaluationView => 'Просмотр оценок',
            self::EvaluationOverride => 'Исправление оценок',
            self::EvaluationFinalize => 'Утверждение оценок',
            self::ReviewAssign => 'Назначение ручных проверок',
            self::ReviewResolve => 'Решение ручных проверок',
            self::AppealCreate => 'Подача апелляций',
            self::AppealResolve => 'Решение апелляций',
            self::WorkflowDesign => 'Проектирование бизнес-процессов',
            self::WorkflowPublish => 'Публикация бизнес-процессов',
            self::ChecklistDesign => 'Проектирование чек-листов',
            self::ChecklistPublish => 'Публикация чек-листов',
            self::IntegrationsManage => 'Управление интеграциями',
            self::AiManage => 'Управление ИИ-провайдерами',
            self::AiLogsView => 'Просмотр журнала вызовов ИИ',
            self::AnalyticsView => 'Просмотр аналитики',
            self::AnalyticsExport => 'Выгрузка аналитики',
            self::AuditView => 'Просмотр журнала аудита',
        };
    }

    /**
     * Права управления: их обладателю двухфакторная аутентификация обязательна (ADR-0002).
     *
     * @return list<self>
     */
    public static function requiringMfa(): array
    {
        return [
            self::UsersCreate, self::UsersUpdate, self::UsersDisable, self::RolesManage,
            self::OrganizationManage, self::EmployeesManage,
            self::IntegrationsManage, self::AiManage, self::AuditView,
        ];
    }

    /** Модуль, к которому относится право, уже поставлен или ещё в разработке. */
    public function isModuleAvailable(): bool
    {
        return in_array($this->category(), ['identity', 'organization', 'audit'], true)
            || in_array($this, [
                self::CallsView, self::CallsUpload, self::CallsDelete, self::CallsReprocess,
                self::AudioPlay, self::AudioDownload,
                self::TranscriptView, self::EvaluationView, self::AiManage, self::AiLogsView,
                self::WorkflowDesign, self::WorkflowPublish, self::ChecklistDesign, self::ChecklistPublish,
            ], true);
    }
}
