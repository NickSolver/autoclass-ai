<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEvent */
class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'actor' => $this->actor_user_id !== null ? ['id' => $this->actor_user_id, 'login' => $this->actor_login] : null,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'company_id' => $this->company_id,
            'changes' => $this->changes,
            'reason' => $this->reason,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
        ];
    }
}
