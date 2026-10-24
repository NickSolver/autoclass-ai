<?php

namespace App\Domain\Evaluation\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\ChecklistCriterion;
use App\Domain\Evaluation\Models\ChecklistVersion;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Конструктор чек-листов (ТЗ, разд. 7): черновик → публикация неизменяемой версии.
 * Изменение опубликованного — новый черновик; старые результаты оценки не переписываются.
 */
final class ChecklistService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{code: string, name: string, description?: ?string, company_id?: ?int} $data */
    public function create(User $actor, array $data): Checklist
    {
        return DB::transaction(function () use ($actor, $data) {
            $checklist = Checklist::create([
                'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'company_id' => $data['company_id'] ?? null, 'is_demo' => false, 'created_by' => $actor->id,
            ]);
            $checklist->versions()->create(['version' => 1, 'status' => 'draft', 'created_by' => $actor->id]);
            $this->audit->record('checklist.created', $checklist);

            return $checklist;
        });
    }

    /**
     * Сохранение черновика целиком: настройки версии и список критериев.
     *
     * @param  array{lock_version: int, zero_on_critical?: bool, notes?: ?string, criteria: list<array<string, mixed>>}  $data
     */
    public function saveDraft(ChecklistVersion $draft, array $data): ChecklistVersion
    {
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Опубликованная версия неизменяема. Создайте новый черновик.']);
        }

        return DB::transaction(function () use ($draft, $data) {
            $updated = ChecklistVersion::query()->whereKey($draft->id)->where('lock_version', $data['lock_version'])->increment('lock_version');
            if ($updated === 0) {
                throw ValidationException::withMessages(['lock_version' => 'Черновик изменён другим пользователем. Обновите страницу.']);
            }

            $draft->refresh()->fill([
                'zero_on_critical' => (bool) ($data['zero_on_critical'] ?? false),
                'notes' => $data['notes'] ?? null,
            ])->save();

            $codes = array_map(fn (array $c) => (string) $c['code'], $data['criteria']);
            if (count($codes) !== count(array_unique($codes))) {
                throw ValidationException::withMessages(['criteria' => 'Коды критериев не должны повторяться.']);
            }

            $draft->criteria()->delete();
            foreach ($data['criteria'] as $i => $c) {
                $draft->criteria()->create([
                    'code' => $c['code'], 'position' => $i, 'title' => $c['title'],
                    'description' => $c['description'] ?? null, 'ai_hint' => $c['ai_hint'] ?? null,
                    'weight' => $c['weight'], 'is_required' => (bool) ($c['is_required'] ?? true),
                    'is_critical' => (bool) ($c['is_critical'] ?? false), 'type' => 'binary',
                    'applicability' => $c['applicability'] ?? null,
                ]);
            }

            return $draft->refresh()->load('criteria');
        });
    }

    /** Публикация черновика: версия становится неизменяемой, прежняя опубликованная — архивной. */
    public function publish(User $actor, ChecklistVersion $draft): ChecklistVersion
    {
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Публикуется только черновик.']);
        }
        $criteria = $draft->criteria()->get();
        $errors = [];
        if ($criteria->isEmpty()) {
            $errors[] = 'Добавьте хотя бы один критерий.';
        }
        if ($criteria->contains(fn (ChecklistCriterion $c) => (float) $c->weight <= 0)) {
            $errors[] = 'Вес каждого критерия должен быть больше нуля.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['version' => $errors]);
        }

        DB::transaction(function () use ($actor, $draft) {
            ChecklistVersion::query()->where('checklist_id', $draft->checklist_id)->where('status', 'published')->update(['status' => 'archived']);
            $draft->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id]);
        });
        $this->audit->record('checklist.published', $draft->checklist, ['version' => $draft->version]);

        return $draft->refresh();
    }

    /** Новый черновик — копия последней опубликованной версии. Черновик может быть только один. */
    public function newDraft(User $actor, Checklist $checklist): ChecklistVersion
    {
        if ($checklist->versions()->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['version' => 'Черновик уже есть — продолжите его редактирование.']);
        }
        $source = $checklist->latestPublished();

        return DB::transaction(function () use ($actor, $checklist, $source) {
            $draft = $checklist->versions()->create([
                'version' => (int) $checklist->versions()->max('version') + 1,
                'status' => 'draft',
                'zero_on_critical' => $source->zero_on_critical ?? false,
                'created_by' => $actor->id,
            ]);
            foreach ($source->criteria ?? [] as $c) {
                $draft->criteria()->create($c->only(['code', 'position', 'title', 'description', 'ai_hint', 'weight', 'is_required', 'is_critical', 'type', 'applicability']));
            }

            return $draft->refresh()->load('criteria');
        });
    }

    /**
     * Сравнение версий по кодам критериев (ТЗ, разд. 7.3).
     *
     * @return list<array{code: string, change: string, before: ?array<string, mixed>, after: ?array<string, mixed>}>
     */
    public function compare(ChecklistVersion $from, ChecklistVersion $to): array
    {
        $fields = ['title', 'description', 'ai_hint', 'weight', 'is_required', 'is_critical', 'applicability'];
        $a = $from->criteria()->get()->keyBy('code')->map(fn (ChecklistCriterion $c) => $c->only($fields));
        $b = $to->criteria()->get()->keyBy('code')->map(fn (ChecklistCriterion $c) => $c->only($fields));

        $diff = [];
        foreach ($a->keys()->merge($b->keys())->unique() as $code) {
            $before = $a->get($code);
            $after = $b->get($code);
            $change = $before === null ? 'added' : ($after === null ? 'removed' : ($before == $after ? 'same' : 'changed'));
            if ($change !== 'same') {
                $diff[] = ['code' => (string) $code, 'change' => $change, 'before' => $before, 'after' => $after];
            }
        }

        return $diff;
    }
}
