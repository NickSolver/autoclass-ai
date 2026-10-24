<?php

namespace App\Domain\Analytics;

use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика (ТЗ, разд. 10) с честными знаменателями:
 * - средняя оценка — только по основному чек-листу и только по итоговым результатам с числовой оценкой
 *   (последняя ревизия действующего анализа); результаты без оценки показываются отдельно и в среднее не входят;
 * - дополнительные чек-листы — отдельной таблицей, в главное среднее не попадают;
 * - всегда показываются период, объём выборки и источник итоговости (ИИ / подтверждено / исправлено человеком).
 */
final class AnalyticsService
{
    public const GROUPS = ['company', 'dealership', 'department', 'employee'];

    /**
     * @param  array{from: string, to: string, company_id?: ?int, dealership_id?: ?int, department_id?: ?int, employee_id?: ?int, checklist_id?: ?int, direction?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function report(User $user, array $filters, string $groupBy = 'company'): array
    {
        [$from, $to] = $this->period($filters);
        $calls = fn () => $this->calls($user, $filters, $from, $to);

        $statuses = $calls()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $total = (int) $statuses->sum();
        $excluded = (int) ($statuses[CallStatus::Excluded->value] ?? 0);
        $failed = (int) ($statuses[CallStatus::Failed->value] ?? 0);
        $completed = (int) ($statuses[CallStatus::Completed->value] ?? 0);

        $primary = fn () => $this->results($calls(), 'primary', $filters['checklist_id'] ?? null);
        $quality = $primary()->selectRaw(<<<'SQL'
            count(*) as evaluated,
            sum(er.score is not null) as scored,
            avg(er.score) as average,
            sum(er.score is not null and er.critical_violation = 1) as critical,
            sum(er.score is null) as unscored,
            sum(er.score is not null and er.origin = 'ai' and er.approval_state <> 'human_confirmed') as final_ai,
            sum(er.score is not null and er.approval_state = 'human_confirmed') as final_confirmed,
            sum(er.score is not null and er.origin = 'human') as final_corrected
            SQL)->first();

        $byChecklist = fn (string $role) => $this->results($calls(), $role, $filters['checklist_id'] ?? null)
            ->join('checklist_versions as cv', 'cv.id', '=', 'er.checklist_version_id')
            ->join('checklists as cl', 'cl.id', '=', 'cv.checklist_id')
            ->groupBy('cl.id', 'cl.name', 'cl.is_demo', 'cv.version')
            ->orderBy('cl.name')->orderBy('cv.version')
            ->selectRaw('cl.id as checklist_id, cl.name, cl.is_demo, cv.version, count(*) as evaluated, sum(er.score is not null) as scored, avg(er.score) as average, sum(er.score is not null and er.critical_violation = 1) as critical')
            ->get()->map(fn ($r) => [
                'checklist_id' => (int) $r->checklist_id, 'name' => $r->name, 'is_demo' => (bool) $r->is_demo, 'version' => (int) $r->version,
                ...$this->stats($r),
            ])->values();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => config('app.timezone')],
            'volume' => [
                'total' => $total,
                'excluded' => $excluded,
                'failed' => $failed,
                'completed' => $completed,
                'in_progress' => $total - $excluded - $failed - $completed,
                'evaluated' => (int) $quality->evaluated,
                // Процесс мог решить не оценивать звонок (например, другой отдел).
                'completed_without_evaluation' => max(0, $completed - $this->evaluatedCalls($calls())),
            ],
            'quality' => [
                ...$this->stats($quality),
                'unscored' => (int) $quality->unscored,
                'finality' => [
                    'ai' => (int) $quality->final_ai,
                    'human_confirmed' => (int) $quality->final_confirmed,
                    'human_corrected' => (int) $quality->final_corrected,
                ],
            ],
            'checklists' => ['primary' => $byChecklist('primary'), 'additional' => $byChecklist('additional')],
            'dynamics' => $this->dynamics($primary(), $from, $to),
            'breakdown' => ['group_by' => $groupBy, 'rows' => $this->breakdown($calls(), $primary(), $groupBy)],
            'review' => $this->review($calls()),
            'cost' => $this->cost($calls()),
            'conversion' => [
                // Договорённость из разговора в текущей версии оценки не извлекается; CRM не подключена (ТЗ, разд. 10).
                'conversation_agreement' => null,
                'crm_confirmed_conversion' => null,
            ],
        ];
    }

    /**
     * Строки выгрузки: звонок и итоговый результат основного чек-листа.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function exportRows(User $user, array $filters): \Generator
    {
        [$from, $to] = $this->period($filters);
        $rows = $this->calls($user, $filters, $from, $to)->toBase()
            ->leftJoin('evaluation_results as er', function (JoinClause $j) {
                $j->on('er.call_id', '=', 'calls.id')->on('er.analysis_run_id', '=', 'calls.current_analysis_run_id')
                    ->where('er.is_current', true)->where('er.role', 'primary');
            })
            ->leftJoin('checklist_versions as cv', 'cv.id', '=', 'er.checklist_version_id')
            ->leftJoin('checklists as cl', 'cl.id', '=', 'cv.checklist_id')
            ->orderBy('calls.occurred_at')
            ->select([
                'calls.id', 'calls.occurred_at', 'calls.direction', 'calls.status', 'calls.org_snapshot', 'calls.duration_ms',
                'cl.name as checklist', 'cl.is_demo', 'cv.version as checklist_version', 'er.score', 'er.provisional_score',
                'er.business_status', 'er.critical_violation', 'er.origin', 'er.approval_state',
            ])
            ->lazy(500);

        foreach ($rows as $r) {
            $org = json_decode((string) $r->org_snapshot, true) ?: [];
            yield [
                'id' => $r->id, 'occurred_at' => $r->occurred_at, 'direction' => $r->direction, 'status' => $r->status,
                'company' => $org['company']['name'] ?? null, 'dealership' => $org['dealership']['name'] ?? null,
                'department' => $org['department']['name'] ?? null, 'employee' => $org['employee']['full_name'] ?? null,
                'duration_s' => $r->duration_ms !== null ? (int) round($r->duration_ms / 1000) : null,
                'checklist' => $r->checklist ? $r->checklist.($r->is_demo ? ' [DEMO]' : '') : null, 'checklist_version' => $r->checklist_version,
                'score' => $r->score, 'provisional_score' => $r->provisional_score, 'business_status' => $r->business_status,
                'critical_violation' => $r->critical_violation === null ? null : (bool) $r->critical_violation,
                'finality' => $r->origin === null ? null : ($r->origin === 'human' ? 'human_corrected' : ($r->approval_state === 'human_confirmed' ? 'human_confirmed' : 'ai')),
            ];
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function period(array $filters): array
    {
        return [Carbon::parse($filters['from'])->startOfDay(), Carbon::parse($filters['to'])->endOfDay()];
    }

    /** @return Builder<Call> */
    private function calls(User $user, array $filters, Carbon $from, Carbon $to): Builder
    {
        return Call::query()->visibleTo($user, Permission::AnalyticsView)
            ->whereBetween('calls.occurred_at', [$from, $to])
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('calls.company_id', $v))
            ->when($filters['dealership_id'] ?? null, fn ($q, $v) => $q->where('calls.dealership_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('calls.department_id', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('calls.employee_id', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('calls.direction', $v));
    }

    /** Итоговые результаты действующего анализа по роли чек-листа. */
    private function results(Builder $calls, string $role, ?int $checklistId): \Illuminate\Database\Query\Builder
    {
        return $calls->toBase()
            ->where('calls.status', '!=', CallStatus::Excluded->value)
            ->join('evaluation_results as er', function (JoinClause $j) use ($role) {
                $j->on('er.call_id', '=', 'calls.id')->on('er.analysis_run_id', '=', 'calls.current_analysis_run_id')
                    ->where('er.is_current', true)->where('er.role', $role);
            })
            ->when($checklistId, fn ($q, $id) => $q->whereIn('er.checklist_version_id', DB::table('checklist_versions')->where('checklist_id', $id)->select('id')));
    }

    private function evaluatedCalls(Builder $calls): int
    {
        return $calls->where('calls.status', CallStatus::Completed->value)->whereNotNull('calls.current_analysis_run_id')->count();
    }

    /** @return array{evaluated: int, scored: int, average: ?float, critical: int, critical_share: ?float} */
    private function stats(object $r): array
    {
        $scored = (int) $r->scored;

        return [
            'evaluated' => (int) $r->evaluated,
            'scored' => $scored,
            'average' => $scored > 0 && $r->average !== null ? round((float) $r->average, 1) : null,
            'critical' => (int) $r->critical,
            'critical_share' => $scored > 0 ? round(100 * (int) $r->critical / $scored, 1) : null,
        ];
    }

    /** @return array{unit: string, points: list<array<string, mixed>>} */
    private function dynamics(\Illuminate\Database\Query\Builder $primary, Carbon $from, Carbon $to): array
    {
        $weekly = $from->diffInDays($to) > 62;
        $bucket = $weekly ? "date_format(date_sub(calls.occurred_at, interval weekday(calls.occurred_at) day), '%Y-%m-%d')" : "date_format(calls.occurred_at, '%Y-%m-%d')";
        $points = $primary->selectRaw("{$bucket} as bucket, count(*) as evaluated, sum(er.score is not null) as scored, avg(er.score) as average, sum(er.score is not null and er.critical_violation = 1) as critical")
            ->groupBy('bucket')->orderBy('bucket')->get()
            ->map(fn ($r) => ['date' => $r->bucket, ...$this->stats($r)])->values()->all();

        return ['unit' => $weekly ? 'week' : 'day', 'points' => $points];
    }

    /** @return list<array<string, mixed>> */
    private function breakdown(Builder $calls, \Illuminate\Database\Query\Builder $primary, string $groupBy): array
    {
        [$column, $table, $label] = match ($groupBy) {
            'dealership' => ['dealership_id', 'dealerships', 'name'],
            'department' => ['department_id', 'departments', 'name'],
            'employee' => ['employee_id', 'employees', "concat_ws(' ', last_name, first_name)"],
            default => ['company_id', 'companies', 'name'],
        };
        $volume = $calls->toBase()->selectRaw("calls.{$column} as unit_id, count(*) as calls")->groupBy('unit_id')->pluck('calls', 'unit_id');
        $quality = $primary->selectRaw("calls.{$column} as unit_id, count(*) as evaluated, sum(er.score is not null) as scored, avg(er.score) as average, sum(er.score is not null and er.critical_violation = 1) as critical")
            ->groupBy('unit_id')->get()->keyBy('unit_id');
        $names = DB::table($table)->whereIn('id', $volume->keys()->filter())->selectRaw("id, {$label} as label")->pluck('label', 'id');

        return $volume->map(fn ($n, $id) => [
            'id' => $id === '' ? null : (int) $id,
            'name' => $id === '' ? ($groupBy === 'employee' ? 'Сотрудник не установлен' : 'Не указано') : ($names[$id] ?? "#{$id}"),
            'calls' => (int) $n,
            ...$this->stats($quality->get($id) ?? (object) ['evaluated' => 0, 'scored' => 0, 'average' => null, 'critical' => 0]),
        ])->sortByDesc('calls')->values()->all();
    }

    /** @return array<string, int> */
    private function review(Builder $calls): array
    {
        $ids = $calls->select('calls.id');
        $reviews = DB::table('manual_reviews')->whereIn('call_id', $ids)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $appeals = DB::table('appeals')->whereIn('call_id', (clone $ids))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'pending' => (int) ($reviews['pending'] ?? 0), 'in_progress' => (int) ($reviews['in_progress'] ?? 0),
            'resolved' => (int) ($reviews['resolved'] ?? 0),
            'appeals_open' => (int) (($appeals['open'] ?? 0) + ($appeals['in_review'] ?? 0)),
            'appeals_accepted' => (int) ($appeals['accepted'] ?? 0), 'appeals_rejected' => (int) ($appeals['rejected'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function cost(Builder $calls): array
    {
        $row = DB::table('ai_provider_calls')->whereIn('call_id', $calls->select('calls.id'))
            ->selectRaw("count(*) as requests, sum(cost) as cost, sum(cost is null) as unpriced, avg(case when operation = 'evaluate' then duration_ms end) as evaluate_ms, avg(case when operation = 'transcribe' then duration_ms end) as transcribe_ms, max(currency) as currency")
            ->first();

        return [
            'requests' => (int) $row->requests,
            'cost' => $row->cost !== null ? round((float) $row->cost, 2) : null,
            'currency' => $row->currency ?? 'RUB',
            // Вызовы без тарифа не считаются нулём.
            'unpriced_requests' => (int) $row->unpriced,
            'avg_evaluate_ms' => $row->evaluate_ms !== null ? (int) round((float) $row->evaluate_ms) : null,
            'avg_transcribe_ms' => $row->transcribe_ms !== null ? (int) round((float) $row->transcribe_ms) : null,
        ];
    }
}
