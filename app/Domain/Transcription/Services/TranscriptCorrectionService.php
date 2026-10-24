<?php

namespace App\Domain\Transcription\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Transcription\Models\TranscriptSegment;
use App\Domain\Transcription\Models\TranscriptVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Исправление расшифровки человеком (ТЗ, разд. 11): новая версия с источником «human», прежняя не меняется.
 * Оценка автоматически не пересчитывается — только явной повторной обработкой (ТЗ, разд. 17, п. 3).
 */
final class TranscriptCorrectionService
{
    public const ROLES = ['agent', 'client', 'unknown'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{id: int, text?: string, speaker_role?: string}>  $edits
     */
    public function correct(User $actor, Call $call, TranscriptVersion $base, array $edits, string $reason): TranscriptVersion
    {
        $latest = TranscriptVersion::query()->where('call_id', $call->id)->max('version');
        if ($base->call_id !== $call->id || $base->version !== (int) $latest) {
            throw ValidationException::withMessages(['version' => 'Исправляется только последняя версия расшифровки. Обновите страницу.']);
        }
        $segments = $base->segments()->get()->keyBy('id');
        $changes = [];
        foreach ($edits as $edit) {
            /** @var TranscriptSegment|null $segment */
            $segment = $segments->get($edit['id']) ?? throw ValidationException::withMessages(['segments' => 'Реплика не относится к этой версии.']);
            $text = isset($edit['text']) ? trim($edit['text']) : $segment->text;
            $role = $edit['speaker_role'] ?? $segment->speaker_role;
            if ($text === '' || ! in_array($role, self::ROLES, true)) {
                throw ValidationException::withMessages(['segments' => 'Текст реплики не может быть пустым; роль — сотрудник, клиент или не определена.']);
            }
            if ($text !== $segment->text || $role !== $segment->speaker_role) {
                $changes[$segment->id] = ['text' => $text, 'speaker_role' => $role];
            }
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['segments' => 'Нет изменений.']);
        }

        $version = DB::transaction(function () use ($actor, $call, $base, $segments, $changes, $reason) {
            $version = TranscriptVersion::create([
                'call_id' => $call->id, 'transcription_run_id' => $base->transcription_run_id, 'version' => $base->version + 1,
                'source' => 'human', 'is_synthetic' => $base->is_synthetic, 'created_by' => $actor->id, 'reason' => $reason, 'created_at' => now(),
            ]);
            foreach ($segments as $s) {
                $version->segments()->create([
                    ...$s->only(['position', 'start_ms', 'end_ms', 'speaker', 'confidence']),
                    'text' => $changes[$s->id]['text'] ?? $s->text,
                    'speaker_role' => $changes[$s->id]['speaker_role'] ?? $s->speaker_role,
                ]);
            }

            return $version;
        });

        $this->audit->record('transcript.corrected', $version, [
            'segments' => [
                'old' => array_map(fn (int $id) => mb_substr("#{$segments[$id]->position}: [{$segments[$id]->speaker_role}] {$segments[$id]->text}", 0, 300), array_keys($changes)),
                'new' => array_map(fn (int $id, array $c) => mb_substr("#{$segments[$id]->position}: [{$c['speaker_role']}] {$c['text']}", 0, 300), array_keys($changes), $changes),
            ],
        ], $reason, $call->orgPath(), $actor);

        return $version;
    }
}
