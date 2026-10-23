<?php

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Http\Controllers\Api\V1\AiProviderCallController;
use App\Http\Controllers\Api\V1\AiProviderController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CallAnalysisController;
use App\Http\Controllers\Api\V1\CallAudioController;
use App\Http\Controllers\Api\V1\CallController;
use App\Http\Controllers\Api\V1\CallUploadController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyGroupController;
use App\Http\Controllers\Api\V1\DealershipController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\OrgTreeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleScopeController;
use Illuminate\Support\Facades\Route;

/*
| API v1. Группа web (сессия + CSRF), префикс /api/v1 — см. bootstrap/app.php.
|
| Привязка моделей из URL сразу применяет область просмотра: объект вне области
| пользователя даёт 404 ещё до валидации и контроллера (не раскрывает существование).
*/

$visible = fn (string $model, Permission $permission) => fn (string $value) => $model::query()
    ->visibleTo(request()->user(), $permission)
    ->findOrFail((int) $value);

Route::bind('group', $visible(CompanyGroup::class, Permission::OrganizationView));
Route::bind('company', $visible(Company::class, Permission::OrganizationView));
Route::bind('dealership', $visible(Dealership::class, Permission::OrganizationView));
Route::bind('department', $visible(Department::class, Permission::OrganizationView));
Route::bind('employee', $visible(Employee::class, Permission::EmployeesView));
Route::bind('user', $visible(User::class, Permission::UsersView));
Route::bind('auditEvent', $visible(AuditEvent::class, Permission::AuditView));
Route::bind('call', $visible(Call::class, Permission::CallsView));
Route::bind('provider', fn (string $value) => ProviderConfig::query()->findOrFail((int) $value));
Route::bind('assignment', fn (string $value) => request()->route('employee')->assignments()->findOrFail((int) $value));
Route::bind('roleScope', fn (string $value) => request()->route('user')->roleScopes()->findOrFail((int) $value));

// Публичные маршруты: только вход и восстановление пароля. Регистрации нет.
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('password-reset/request', [PasswordResetController::class, 'request'])->middleware('throttle:password-reset')->name('password-reset.request');
    Route::post('password-reset/confirm', [PasswordResetController::class, 'confirm'])->middleware('throttle:password-reset-confirm')->name('password-reset.confirm');
});

Route::middleware(['auth', 'active'])->group(function () {
    // Доступны до второго фактора: профиль, выход, вход по коду и подключение MFA.
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('mfa', [MfaController::class, 'show'])->name('mfa.show');
        Route::post('mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:mfa')->name('mfa.setup');
        Route::post('mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa')->name('mfa.confirm');
        Route::post('mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:mfa')->name('mfa.verify');
    });

    // После второго фактора, но до смены временного пароля.
    Route::prefix('auth')->name('auth.')->middleware('mfa')->group(function () {
        Route::post('mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('mfa.recent')->name('mfa.recovery-codes');
        Route::delete('mfa/trusted-devices', [MfaController::class, 'forgetDevices'])->name('mfa.trusted-devices.destroy');
        Route::delete('mfa', [MfaController::class, 'disable'])->middleware('mfa.recent')->name('mfa.disable');
        Route::post('password', [AuthController::class, 'changePassword'])->middleware('throttle:password-change')->name('password');
        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    });

    Route::middleware(['mfa', 'password.changed', 'throttle:api'])->group(function () {
        Route::get('org/tree', OrgTreeController::class)->name('org.tree');

        Route::apiResource('groups', CompanyGroupController::class)->only(['index', 'show', 'update']);
        Route::apiResource('companies', CompanyController::class)->except(['destroy']);
        Route::apiResource('dealerships', DealershipController::class)->except(['destroy']);
        Route::apiResource('departments', DepartmentController::class)->except(['destroy']);

        Route::apiResource('employees', EmployeeController::class)->except(['destroy']);
        Route::post('employees/{employee}/assignments', [EmployeeAssignmentController::class, 'store'])->name('employees.assignments.store');
        Route::patch('employees/{employee}/assignments/{assignment}', [EmployeeAssignmentController::class, 'update'])->name('employees.assignments.update');
        Route::delete('employees/{employee}/assignments/{assignment}', [EmployeeAssignmentController::class, 'destroy'])->name('employees.assignments.destroy');

        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::post('users/{user}/status', [UserController::class, 'status'])->name('users.status');
        Route::post('users/{user}/password', [UserController::class, 'password'])->middleware('mfa.recent')->name('users.password');
        Route::delete('users/{user}/mfa', [UserController::class, 'resetMfa'])->middleware('mfa.recent')->name('users.mfa.reset');
        Route::delete('users/{user}/sessions', [UserController::class, 'terminateSessions'])->name('users.sessions.destroy');
        Route::post('users/{user}/role-scopes', [UserRoleScopeController::class, 'store'])->middleware('mfa.recent')->name('users.role-scopes.store');
        Route::delete('users/{user}/role-scopes/{roleScope}', [UserRoleScopeController::class, 'destroy'])->middleware('mfa.recent')->name('users.role-scopes.destroy');

        Route::apiResource('roles', RoleController::class)->only(['index', 'show']);
        Route::apiResource('roles', RoleController::class)->only(['store', 'update', 'destroy'])->middleware('mfa.recent');
        Route::get('permissions', PermissionController::class)->name('permissions.index');

        // Звонки (этап B).
        Route::get('calls/summary', [CallController::class, 'summary'])->name('calls.summary');
        Route::get('calls/uploads/settings', [CallUploadController::class, 'settings'])->name('calls.uploads.settings');
        Route::post('calls/uploads', [CallUploadController::class, 'store'])->middleware('throttle:uploads')->name('calls.uploads');
        Route::get('calls', [CallController::class, 'index'])->name('calls.index');
        Route::get('calls/{call}', [CallController::class, 'show'])->name('calls.show');
        Route::patch('calls/{call}', [CallController::class, 'update'])->name('calls.update');
        Route::get('calls/{call}/audio', CallAudioController::class)->name('calls.audio');
        Route::post('calls/{call}/reprocess', [CallController::class, 'reprocess'])->name('calls.reprocess');
        Route::post('calls/{call}/exclude', [CallController::class, 'exclude'])->name('calls.exclude');
        Route::get('calls/{call}/transcript', [CallAnalysisController::class, 'transcript'])->name('calls.transcript');
        Route::get('calls/{call}/analysis', [CallAnalysisController::class, 'analysis'])->name('calls.analysis');

        // Провайдеры ИИ (этап C). Изменения ключей, ролей и разрешения внешней обработки — с повторным кодом MFA.
        Route::get('ai-providers', [AiProviderController::class, 'index'])->name('ai-providers.index');
        Route::post('ai-providers', [AiProviderController::class, 'store'])->name('ai-providers.store');
        Route::patch('ai-providers/{provider}', [AiProviderController::class, 'update'])->name('ai-providers.update');
        Route::put('ai-providers/{provider}/secret', [AiProviderController::class, 'setSecret'])->middleware('mfa.recent')->name('ai-providers.secret');
        Route::delete('ai-providers/{provider}/secret', [AiProviderController::class, 'clearSecret'])->name('ai-providers.secret.clear');
        Route::post('ai-providers/{provider}/role', [AiProviderController::class, 'role'])->middleware('mfa.recent')->name('ai-providers.role');
        Route::post('ai-providers/{provider}/test', [AiProviderController::class, 'test'])->middleware('throttle:mfa')->name('ai-providers.test');
        Route::put('ai-settings/external-processing', [AiProviderController::class, 'externalProcessing'])->middleware('mfa.recent')->name('ai-settings.external');
        Route::get('ai-provider-calls', AiProviderCallController::class)->name('ai-provider-calls.index');

        Route::get('audit', [AuditEventController::class, 'index'])->name('audit.index');
        Route::get('audit/{auditEvent}', [AuditEventController::class, 'show'])->name('audit.show');
    });
});

// Любой неизвестный адрес API любым методом — 404 в JSON.
Route::any('{path}', fn () => response()->json(['message' => 'Маршрут API не найден.'], 404))
    ->where('path', '.*')
    ->name('not-found');
