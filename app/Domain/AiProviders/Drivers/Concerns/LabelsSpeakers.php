<?php

namespace App\Domain\AiProviders\Drivers\Concerns;

/** Метки говорящих провайдера («A», «0», «spk_1») → единые S1, S2… по порядку появления. */
trait LabelsSpeakers
{
    /** @var array<string, string> */
    private array $speakerMap = [];

    private function speakerLabel(string $providerLabel): string
    {
        return $this->speakerMap[$providerLabel] ??= 'S'.(count($this->speakerMap) + 1);
    }
}
