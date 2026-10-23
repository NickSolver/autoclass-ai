<?php

namespace App\Domain\CallIntake;

/**
 * Технический статус конвейера (ТЗ, разд. 5). Не путать с бизнес-статусом оценки (этап C+).
 * awaiting_transcription / awaiting_evaluation — шаг готов, а следующий провайдер не включён (ADR-0003, ADR-0004).
 */
enum CallStatus: string
{
    case Received = 'received';
    case AwaitingAudio = 'awaiting_audio';
    case Queued = 'queued';
    case Preparing = 'preparing';
    case AwaitingTranscription = 'awaiting_transcription';
    case AwaitingEvaluation = 'awaiting_evaluation';
    case Transcribing = 'transcribing';
    case Classifying = 'classifying';
    case Evaluating = 'evaluating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Получен',
            self::AwaitingAudio => 'Ожидает запись',
            self::Queued => 'В очереди',
            self::Preparing => 'Подготовка аудио',
            self::AwaitingTranscription => 'Ожидает распознавания',
            self::AwaitingEvaluation => 'Ожидает оценки',
            self::Transcribing => 'Распознавание',
            self::Classifying => 'Классификация',
            self::Evaluating => 'Оценка',
            self::Completed => 'Готово',
            self::Failed => 'Ошибка обработки',
            self::Excluded => 'Исключён',
        };
    }

    /** Звонок сейчас обрабатывается — повторный запуск не нужен. */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Received, self::Queued, self::Preparing, self::Transcribing, self::Classifying, self::Evaluating], true);
    }

    /** @return list<self> */
    public static function inProgress(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isInProgress()));
    }
}
