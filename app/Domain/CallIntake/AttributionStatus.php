<?php

namespace App\Domain\CallIntake;

/**
 * Сопоставление звонка с сотрудником. «Не установлен» — допустимый выбор при загрузке,
 * такой звонок попадает в список «Требует сопоставления» (ТЗ, разд. 17 п. 4).
 */
enum AttributionStatus: string
{
    case Resolved = 'resolved';
    case NeedsEmployee = 'needs_employee';
}
