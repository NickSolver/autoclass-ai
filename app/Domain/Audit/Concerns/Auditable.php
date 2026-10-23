<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Автоматически пишет в аудит создание, изменение и удаление модели со старыми и новыми значениями.
 * Поля из auditSensitive() попадают в журнал только как факт изменения, без значений.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            /** @var Model&self $model */
            $changes = [];
            foreach ($model->getAttributes() as $field => $value) {
                if ($model->isAuditableField($field)) {
                    $changes[$field] = ['old' => null, 'new' => $model->auditValue($field, $value)];
                }
            }
            app(AuditLogger::class)->record($model->auditSubjectType().'.created', $model, $changes);
        });

        static::updated(function (Model $model) {
            /** @var Model&self $model */
            $changes = [];
            foreach ($model->getChanges() as $field => $value) {
                if ($model->isAuditableField($field)) {
                    $changes[$field] = [
                        'old' => $model->auditValue($field, $model->getRawOriginal($field)),
                        'new' => $model->auditValue($field, $value),
                    ];
                }
            }
            if ($changes !== []) {
                app(AuditLogger::class)->record($model->auditSubjectType().'.updated', $model, $changes);
            }
        });

        static::deleted(function (Model $model) {
            /** @var Model&self $model */
            app(AuditLogger::class)->record($model->auditSubjectType().'.deleted', $model);
        });
    }

    public function auditSubjectType(): string
    {
        return Str::snake(class_basename($this));
    }

    /** Поля, которые не пишутся вовсе (служебные). @return list<string> */
    protected function auditIgnored(): array
    {
        return ['created_at', 'updated_at'];
    }

    /** Поля, значения которых не раскрываются в журнале. @return list<string> */
    protected function auditSensitive(): array
    {
        return [];
    }

    protected function isAuditableField(string $field): bool
    {
        return ! in_array($field, $this->auditIgnored(), true);
    }

    protected function auditValue(string $field, mixed $value): mixed
    {
        if (in_array($field, $this->auditSensitive(), true)) {
            return $value === null ? null : '[скрыто]';
        }

        return $value;
    }
}
