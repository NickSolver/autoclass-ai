<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/** Журнал аудита — только чтение, в пределах области права audit.view. */
class AuditEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditEvent::class);
        $filters = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subject_id' => ['sometimes', 'integer'],
            'actor_user_id' => ['sometimes', 'integer'],
            'company_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $events = AuditEvent::query()
            ->visibleTo($request->user(), Permission::AuditView)
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', addcslashes($v, '%_\\').'%'))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['actor_user_id'] ?? null, fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', strlen($v) === 10 ? Carbon::parse($v)->endOfDay() : Carbon::parse($v)))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return AuditEventResource::collection($events);
    }

    public function show(AuditEvent $auditEvent): AuditEventResource
    {
        return new AuditEventResource($auditEvent);
    }
}
