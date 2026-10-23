<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Dealership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DealershipRequest;
use App\Http\Resources\DealershipResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DealershipController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Dealership::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $dealerships = Dealership::query()
            ->visibleTo($request->user(), Permission::OrganizationView)
            ->with('company')
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($s))->orWhere('code', 'like', $this->like($s))->orWhere('city', 'like', $this->like($s))))
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount('departments')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return DealershipResource::collection($dealerships);
    }

    public function store(DealershipRequest $request, Authorizer $authorizer): JsonResponse
    {
        $data = $request->validated();
        $company = Company::query()->visibleTo($request->user(), Permission::OrganizationView)->find($data['company_id'])
            ?? throw ValidationException::withMessages(['company_id' => 'Компания не найдена.']);
        if (! $authorizer->covers($request->user(), Permission::OrganizationManage, $company->orgPath())) {
            throw new AuthorizationException('Нет права создавать дилерские центры в этой компании.');
        }

        $dealership = Dealership::create($data + ['group_id' => $company->group_id]);

        return (new DealershipResource($dealership->load('company')))->response()->setStatusCode(201);
    }

    public function show(Dealership $dealership): DealershipResource
    {
        return new DealershipResource($dealership->load('company')->loadCount('departments'));
    }

    public function update(DealershipRequest $request, Dealership $dealership): DealershipResource
    {
        $this->authorize('update', $dealership);
        $dealership->update($request->validated());

        return new DealershipResource($dealership->load('company'));
    }
}
