<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Дерево оргструктуры в области действия указанного права (по умолчанию organization.view).
 * Используется для навигации и выбора подразделения в формах: узлы-предки, в которых
 * права нет, возвращаются для контекста с selectable = false.
 */
class OrgTreeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'permission' => ['sometimes', Rule::enum(Permission::class)],
        ]);
        $permission = Permission::from($validated['permission'] ?? Permission::OrganizationView->value);
        $user = $request->user();

        $departments = Department::query()->visibleTo($user, $permission)->get();
        $dealerships = Dealership::query()->visibleTo($user, $permission)->get();
        $companies = Company::query()->visibleTo($user, $permission)->get();
        $groups = CompanyGroup::query()->visibleTo($user, $permission)->get();

        $selectable = [
            'group' => $groups->pluck('id')->flip(),
            'company' => $companies->pluck('id')->flip(),
            'dealership' => $dealerships->pluck('id')->flip(),
            'department' => $departments->pluck('id')->flip(),
        ];

        // Предки видимых узлов — для контекста.
        $dealerships = $dealerships->concat(Dealership::query()
            ->whereIn('id', $departments->pluck('dealership_id')->filter()->diff($dealerships->pluck('id')))->get());
        $companies = $companies->concat(Company::query()
            ->whereIn('id', $dealerships->pluck('company_id')->concat($departments->pluck('company_id'))->unique()->diff($companies->pluck('id')))->get());
        $groups = $groups->concat(CompanyGroup::query()
            ->whereIn('id', $companies->pluck('group_id')->unique()->diff($groups->pluck('id')))->get());

        /**
         * @param  iterable<CompanyGroup|Company|Dealership|Department>  $units
         * @param  callable(CompanyGroup|Company|Dealership|Department): list<array<string, mixed>>  $children
         * @return list<array<string, mixed>>
         */
        $nodes = function (string $type, iterable $units, callable $children) use ($selectable): array {
            $result = [];
            foreach ($units as $m) {
                $result[] = [
                    'type' => $type,
                    'id' => $m->id,
                    'name' => $m->name,
                    'is_active' => $m->is_active,
                    'selectable' => isset($selectable[$type][$m->id]),
                    'children' => $children($m),
                ];
            }
            usort($result, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

            return $result;
        };
        $leaf = fn () => [];

        $tree = $nodes('group', $groups, fn (CompanyGroup $g) => $nodes('company', $companies->where('group_id', $g->id),
            fn (Company $c) => [
                ...$nodes('dealership', $dealerships->where('company_id', $c->id),
                    fn (Dealership $d) => $nodes('department', $departments->where('dealership_id', $d->id), $leaf)),
                // Отделы уровня компании — после дилерских центров.
                ...$nodes('department', $departments->where('company_id', $c->id)->whereNull('dealership_id'), $leaf),
            ]));

        return response()->json(['permission' => $permission->value, 'data' => $tree]);
    }
}
