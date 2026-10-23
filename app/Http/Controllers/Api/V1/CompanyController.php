<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompanyRequest;
use App\Http\Resources\CompanyResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $companies = Company::query()
            ->visibleTo($request->user(), Permission::OrganizationView)
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($s))->orWhere('code', 'like', $this->like($s))))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount(['dealerships', 'departments'])
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return CompanyResource::collection($companies);
    }

    public function store(CompanyRequest $request, Authorizer $authorizer): JsonResponse
    {
        $data = $request->validated();
        $group = CompanyGroup::query()->visibleTo($request->user(), Permission::OrganizationView)->find($data['group_id'])
            ?? throw ValidationException::withMessages(['group_id' => 'Группа компаний не найдена.']);
        if (! $authorizer->covers($request->user(), Permission::OrganizationManage, $group->orgPath())) {
            throw new AuthorizationException('Нет права создавать компании в этой группе.');
        }

        $company = Company::create($data);

        return (new CompanyResource($company))->response()->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->loadCount(['dealerships', 'departments']));
    }

    public function update(CompanyRequest $request, Company $company): CompanyResource
    {
        $this->authorize('update', $company);
        $company->update($request->validated());

        return new CompanyResource($company);
    }
}
