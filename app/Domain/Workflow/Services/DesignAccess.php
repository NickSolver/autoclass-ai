<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Область конструкторов: общий (company_id = NULL) чек-лист или процесс — право на уровне группы,
 * компании — право в области этой компании.
 */
final class DesignAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function can(User $user, Permission $permission, ?int $companyId): bool
    {
        if ($companyId === null) {
            return $this->authorizer->hasGroupLevel($user, $permission);
        }
        $company = Company::query()->find($companyId);

        return $company !== null && $this->authorizer->covers($user, $permission, $company->orgPath());
    }

    public function authorize(User $user, Permission $permission, ?int $companyId): void
    {
        if (! $this->can($user, $permission, $companyId)) {
            throw new AuthorizationException('Недостаточно прав для этого действия в выбранной области.');
        }
    }

    /** Объект виден, если есть хотя бы одно из прав в его области. */
    public function visible(User $user, ?int $companyId, Permission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission, $companyId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Компании, где есть хотя бы одно из прав, и признак доступа к общим (группа).
     *
     * @return array{global: bool, companies: list<int>}
     */
    public function scopes(User $user, Permission ...$permissions): array
    {
        $global = false;
        $companies = [];
        foreach ($permissions as $permission) {
            $global = $global || $this->authorizer->hasGroupLevel($user, $permission);
            $companies = [...$companies, ...Company::query()->visibleTo($user, $permission)->pluck('id')->all()];
        }

        return ['global' => $global, 'companies' => array_values(array_unique(array_map('intval', $companies)))];
    }
}
