<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Аналитика и выгрузка (ТЗ, разд. 10). Данные — только в области права analytics.view. */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permission::AnalyticsView), 403, 'Нет доступа к аналитике.');
        $filters = $this->filters($request);
        $groupBy = $request->validate(['group_by' => ['nullable', 'in:'.implode(',', AnalyticsService::GROUPS)]])['group_by'] ?? 'company';

        return response()->json([
            ...$this->analytics->report($request->user(), $filters, $groupBy),
            'can' => ['export' => $request->user()->hasPermission(Permission::AnalyticsExport)],
        ]);
    }

    /** CSV для Excel (UTF-8 с BOM, разделитель «;»). Выгрузка записывается в аудит. */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        abort_unless($request->user()->hasPermission(Permission::AnalyticsExport), 403, 'Нет права выгружать аналитику.');
        $filters = $this->filters($request);
        $user = $request->user();
        $audit->record('analytics.exported', null, ['filters' => ['old' => null, 'new' => $filters]]);

        $headers = [
            'Звонок', 'Дата', 'Направление', 'Статус', 'Компания', 'Дилерский центр', 'Отдел', 'Сотрудник', 'Длительность, с',
            'Основной чек-лист', 'Версия', 'Оценка', 'Предварительная оценка', 'Статус оценки', 'Критическое нарушение', 'Итоговость',
        ];

        return response()->streamDownload(function () use ($user, $filters, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($this->analytics->exportRows($user, $filters) as $row) {
                $row['critical_violation'] = $row['critical_violation'] === null ? '' : ($row['critical_violation'] ? 'да' : 'нет');
                fputcsv($out, array_map(fn ($v) => $this->safeCell($v), array_values($row)), ';');
            }
            fclose($out);
        }, "analytics-{$filters['from']}-{$filters['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Защита от формул в табличном редакторе (CSV injection). */
    private function safeCell(mixed $value): string
    {
        $s = (string) ($value ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($s) ? "'{$s}" : $s;
    }

    /** @return array{from: string, to: string, company_id?: ?int, dealership_id?: ?int, department_id?: ?int, employee_id?: ?int, checklist_id?: ?int, direction?: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_id' => ['nullable', 'integer'],
            'dealership_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'checklist_id' => ['nullable', 'integer'],
            'direction' => ['nullable', 'in:incoming,outgoing,unknown'],
        ]);
        $data['to'] ??= now()->toDateString();
        $data['from'] ??= now()->subDays(29)->toDateString();
        if (now()->parse($data['from'])->diffInDays(now()->parse($data['to'])) > 366) {
            abort(422, 'Период — не больше года.');
        }

        return $data;
    }
}
