<?php

namespace App\Domain\Identity;

enum ScopeType: string
{
    case Group = 'group';
    case Company = 'company';
    case Dealership = 'dealership';
    case Department = 'department';
    /** Только записи, связанные с собственным сотрудником пользователя. */
    case Own = 'own';

    public function label(): string
    {
        return match ($this) {
            self::Group => 'Группа компаний',
            self::Company => 'Компания',
            self::Dealership => 'Дилерский центр',
            self::Department => 'Отдел',
            self::Own => 'Собственные записи',
        };
    }
}
