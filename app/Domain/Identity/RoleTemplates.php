<?php

namespace App\Domain\Identity;

/**
 * Начальные шаблоны ролей (ТЗ, разд. 3.1). Это данные для первичного заполнения, а не правила
 * доступа: после установки роли редактируются из интерфейса, код проверяет только права.
 */
final class RoleTemplates
{
    public const SUPERADMIN = 'superadmin';

    /**
     * @return array<string, array{name: string, description: string, allowed_scope_types: list<string>, permissions: list<string>}>
     */
    public static function all(): array
    {
        $p = fn (Permission ...$ps) => array_map(fn (Permission $x) => $x->value, $ps);

        return [
            self::SUPERADMIN => [
                'name' => 'Суперадминистратор',
                'description' => 'Полный доступ в пределах группы компаний: пользователи, роли, оргструктура, интеграции, конфигурация, аудит.',
                'allowed_scope_types' => ['group'],
                'permissions' => array_map(fn (Permission $x) => $x->value, Permission::cases()),
            ],
            'company_admin' => [
                'name' => 'Администратор компании',
                'description' => 'Оргструктура, сотрудники, пользователи и назначение ролей в пределах назначенных компаний.',
                'allowed_scope_types' => ['company'],
                'permissions' => $p(
                    Permission::UsersView, Permission::UsersCreate, Permission::UsersUpdate, Permission::UsersDisable,
                    Permission::OrganizationView, Permission::OrganizationManage,
                    Permission::EmployeesView, Permission::EmployeesManage,
                    Permission::WorkflowDesign, Permission::ChecklistDesign,
                    Permission::AuditView,
                    // Приём звонков в своей компании (этап B).
                    Permission::CallsUpload, Permission::CallsReprocess, Permission::CallsDelete, Permission::AudioDownload,
                    // Права ролей, которые администратор компании назначает в своей области:
                    // выдать роль можно, только обладая всеми её правами (ADR-0001, п. 5).
                    Permission::CallsView, Permission::AudioPlay, Permission::TranscriptView, Permission::TranscriptEdit,
                    Permission::EvaluationView, Permission::EvaluationOverride, Permission::EvaluationFinalize,
                    Permission::ReviewAssign, Permission::ReviewResolve, Permission::AppealCreate, Permission::AppealResolve,
                    Permission::AnalyticsView, Permission::AnalyticsExport,
                ),
            ],
            'director' => [
                'name' => 'Директор / руководитель группы',
                'description' => 'Аналитика, звонки, поручение проверок в назначенных подразделениях.',
                'allowed_scope_types' => ['group', 'company', 'dealership'],
                'permissions' => $p(
                    Permission::OrganizationView, Permission::EmployeesView, Permission::UsersView,
                    Permission::CallsView, Permission::AudioPlay, Permission::TranscriptView, Permission::EvaluationView,
                    Permission::ReviewAssign, Permission::AnalyticsView, Permission::AnalyticsExport,
                ),
            ],
            'head_of_department' => [
                'name' => 'Руководитель отдела (РОП)',
                'description' => 'Звонки, аналитика, комментарии и инициирование апелляций в своих отделах.',
                'allowed_scope_types' => ['dealership', 'department'],
                'permissions' => $p(
                    Permission::OrganizationView, Permission::EmployeesView,
                    Permission::CallsView, Permission::AudioPlay, Permission::TranscriptView, Permission::EvaluationView,
                    Permission::AppealCreate, Permission::AnalyticsView,
                ),
            ],
            'quality_controller' => [
                'name' => 'Контролёр качества',
                'description' => 'Прослушивание, исправление расшифровок и оценок, решение проверок — с аудитом.',
                'allowed_scope_types' => ['group', 'company', 'dealership', 'department'],
                'permissions' => $p(
                    Permission::OrganizationView, Permission::EmployeesView,
                    Permission::CallsView, Permission::AudioPlay, Permission::TranscriptView, Permission::TranscriptEdit,
                    Permission::EvaluationView, Permission::EvaluationOverride, Permission::EvaluationFinalize,
                    Permission::ReviewResolve, Permission::AppealResolve,
                ),
            ],
            'employee' => [
                'name' => 'Сотрудник',
                'description' => 'Просмотр собственных разрешённых оценок, обращение на пересмотр.',
                'allowed_scope_types' => ['own'],
                'permissions' => $p(
                    Permission::EmployeesView, Permission::CallsView, Permission::EvaluationView, Permission::AppealCreate,
                ),
            ],
        ];
    }
}
