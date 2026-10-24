<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Transcription\Models\TranscriptVersion;
use App\Domain\Workflow\Engine\Plan;
use App\Domain\Workflow\Engine\WorkflowEngine;
use App\Domain\Workflow\Graph\FieldCatalog;
use App\Domain\Workflow\Graph\Graph;
use App\Domain\Workflow\Graph\GraphValidator;
use App\Domain\Workflow\Models\WorkflowDefinition;
use App\Domain\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Конструктор бизнес-процессов (ТЗ, разд. 6.2): черновик → проверка → симуляция → публикация.
 */
final class WorkflowService
{
    public function __construct(
        private readonly GraphValidator $validator,
        private readonly WorkflowEngine $engine,
        private readonly WorkflowRuntime $runtime,
        private readonly AuditLogger $audit,
    ) {}

    /** Минимальный граф нового процесса: «Старт» → «Завершение». */
    public static function emptyGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 80, 'y' => 200], 'data' => ['trigger' => WorkflowRuntime::TRIGGER]],
                ['id' => 'end', 'type' => 'end', 'position' => ['x' => 480, 'y' => 200], 'data' => []],
            ],
            'edges' => [['id' => 'e-start-end', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'end']],
        ];
    }

    /** @param array{name: string, description?: ?string, company_id?: ?int, priority?: int} $data */
    public function create(User $actor, array $data): WorkflowDefinition
    {
        return DB::transaction(function () use ($actor, $data) {
            $definition = WorkflowDefinition::create([
                'name' => $data['name'], 'description' => $data['description'] ?? null, 'company_id' => $data['company_id'] ?? null,
                'trigger' => WorkflowRuntime::TRIGGER, 'priority' => $data['priority'] ?? 100, 'created_by' => $actor->id,
            ]);
            $definition->versions()->create(['version' => 1, 'status' => 'draft', 'graph' => self::emptyGraph(), 'created_by' => $actor->id]);

            return $definition;
        });
    }

    /** @param array{graph: array<string, mixed>, lock_version: int} $data */
    public function saveDraft(WorkflowVersion $draft, array $data): WorkflowVersion
    {
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Опубликованная версия неизменяема. Создайте новый черновик.']);
        }
        if (count((array) ($data['graph']['nodes'] ?? [])) > GraphValidator::MAX_NODES) {
            throw ValidationException::withMessages(['graph' => 'Слишком много узлов (максимум '.GraphValidator::MAX_NODES.').']);
        }

        return DB::transaction(function () use ($draft, $data) {
            $updated = WorkflowVersion::query()->whereKey($draft->id)->where('lock_version', $data['lock_version'])->increment('lock_version');
            if ($updated === 0) {
                throw ValidationException::withMessages(['lock_version' => 'Черновик изменён другим пользователем. Обновите страницу.']);
            }
            $draft->refresh()->update(['graph' => $data['graph']]);

            return $draft->refresh();
        });
    }

    /** @return list<array{node_id: ?string, message: string}> */
    public function validate(WorkflowVersion $version): array
    {
        return $this->validator->validate($version->graph, $version->definition->company_id);
    }

    public function publish(User $actor, WorkflowVersion $draft): WorkflowVersion
    {
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Публикуется только черновик.']);
        }
        $errors = $this->validate($draft);
        if ($errors !== []) {
            throw ValidationException::withMessages(['graph' => array_map(fn (array $e) => $e['message'], $errors)]);
        }

        DB::transaction(function () use ($actor, $draft) {
            WorkflowVersion::query()->where('workflow_definition_id', $draft->workflow_definition_id)->where('status', 'published')->update(['status' => 'archived']);
            $draft->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id]);
        });
        $this->audit->record('workflow.published', $draft->definition, ['version' => $draft->version]);

        return $draft->refresh();
    }

    public function newDraft(User $actor, WorkflowDefinition $definition): WorkflowVersion
    {
        if ($definition->versions()->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['version' => 'Черновик уже есть — продолжите его редактирование.']);
        }
        $source = $definition->latestPublished();

        return $definition->versions()->create([
            'version' => (int) $definition->versions()->max('version') + 1,
            'status' => 'draft',
            'graph' => $source->graph ?? self::emptyGraph(),
            'created_by' => $actor->id,
        ])->refresh();
    }

    /**
     * Симуляция без побочных эффектов: на данных звонка или на введённых значениях полей.
     * Поля результата анализа (статус, оценка) задаются в $input, анализ не выполняется.
     *
     * @param  array<string, mixed>  $input
     * @return array{trace: list<array<string, mixed>>, plan: array<string, mixed>, context: array<string, mixed>, finished: bool, error: ?string}
     */
    public function simulate(WorkflowVersion $version, array $input, ?Call $call): array
    {
        $context = [];
        if ($call !== null) {
            $transcript = TranscriptVersion::query()->where('call_id', $call->id)->orderByDesc('version')->first();
            $context = $this->runtime->context($call, $transcript);
        }
        foreach (FieldCatalog::fields() as $field => $meta) {
            if (array_key_exists($field, $input) && ($call === null || $meta['phase'] === 'after_analysis')) {
                $context[$field] = $this->cast($meta['type'], $input[$field]);
            }
        }

        $graph = new Graph($version->graph);
        $plan = new Plan;
        try {
            $result = $this->engine->run($graph, null, $context, $plan);
            $trace = $result['trace'];
            if ($result['status'] === 'paused' && $result['node_id'] !== null) {
                $result = $this->engine->resume($graph, $result['node_id'], $context, $plan);
                $trace = [...$trace, ...$result['trace']];
            }
        } catch (RuntimeException $e) {
            return ['trace' => $trace ?? [], 'plan' => $plan->toArray(), 'context' => $context, 'finished' => false, 'error' => $e->getMessage()];
        }

        return ['trace' => $trace, 'plan' => $plan->toArray(), 'context' => $context, 'finished' => true, 'error' => null];
    }

    private function cast(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'number' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'org' => (int) $value,
            default => (string) $value,
        };
    }
}
