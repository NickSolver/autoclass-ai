<?php

namespace App\Domain\Evaluation\Services;

use RuntimeException;

/** Ответ модели не соответствует схеме — повтор запроса или отказ. */
final class InvalidEvaluationResponse extends RuntimeException {}
