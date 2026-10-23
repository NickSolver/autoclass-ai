<?php

namespace App\Domain\Evaluation\Services;

use App\Domain\AiProviders\ProviderException;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\ChecklistVersion;

/**
 * ВРЕМЕННЫЙ выбор чек-листов до конструктора бизнес-процессов (этап D): правило из
 * config/analysis.php по направлению звонка. Ровно один основной и ноль или более дополнительных.
 */
final class ChecklistRouter
{
    /** @return list<array{role: string, version: ChecklistVersion, reason: string}> */
    public function route(Call $call): array
    {
        $direction = $call->direction->value;
        $primaryCode = (string) config("analysis.routing.primary.{$direction}");
        $routes = [[
            'role' => 'primary',
            'version' => $this->published($primaryCode),
            'reason' => "Направление «{$call->direction->label()}» → основной чек-лист (временное правило до конструктора процессов).",
        ]];
        foreach ((array) config('analysis.routing.additional', []) as $code) {
            $routes[] = ['role' => 'additional', 'version' => $this->published((string) $code), 'reason' => 'Дополнительный чек-лист для всех звонков (временное правило).'];
        }

        return $routes;
    }

    private function published(string $code): ChecklistVersion
    {
        $checklist = Checklist::query()->where('code', $code)->first();
        $version = $checklist?->latestPublished()
            ?? throw new ProviderException(ProviderException::CONFIG_ERROR, "Нет опубликованной версии чек-листа «{$code}».");

        return $version->load(['checklist', 'criteria']);
    }
}
