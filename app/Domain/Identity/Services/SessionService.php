<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сессии хранятся в БД (SESSION_DRIVER=database). Наружу идентификатор сессии
 * не отдаётся — только его SHA-256, чтобы ответ API нельзя было использовать для подмены сессии.
 */
final class SessionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<array{id: string, ip_address: ?string, user_agent: ?string, last_activity: string, is_current: bool}> */
    public function listFor(User $user, ?string $currentSessionId): array
    {
        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => [
                'id' => hash('sha256', $s->id),
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_activity' => Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
                'is_current' => $s->id === $currentSessionId,
            ])
            ->values()
            ->all();
    }

    public function terminate(User $user, string $publicId): bool
    {
        $ids = DB::table('sessions')->where('user_id', $user->id)->pluck('id');
        $match = $ids->first(fn (string $id) => hash_equals(hash('sha256', $id), $publicId));
        if ($match === null) {
            return false;
        }
        DB::table('sessions')->where('id', $match)->delete();
        $this->audit->record('auth.session_terminated', $user);

        return true;
    }

    /** Завершает все сессии пользователя, кроме указанной. Возвращает число завершённых. */
    public function terminateAll(User $user, ?string $exceptSessionId = null, bool $audit = true): int
    {
        $count = DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();

        // remember_token сбрасывается, чтобы старые cookie «запомнить меня» перестали действовать.
        $user->forceFill(['remember_token' => null])->saveQuietly();

        if ($audit && $count > 0) {
            $this->audit->record('auth.sessions_terminated', $user, ['count' => $count]);
        }

        return $count;
    }
}
