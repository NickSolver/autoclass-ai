<?php

namespace App\Domain\Organization;

/**
 * Положение объекта в оргструктуре. Более глубокие уровни заполняются, только если
 * заполнены все уровни выше (кроме dealership для отделов уровня компании).
 */
final readonly class OrgPath
{
    public function __construct(
        public int $groupId,
        public ?int $companyId = null,
        public ?int $dealershipId = null,
        public ?int $departmentId = null,
    ) {}

    /** @return array{group_id: int, company_id: ?int, dealership_id: ?int, department_id: ?int} */
    public function toColumns(): array
    {
        return [
            'group_id' => $this->groupId,
            'company_id' => $this->companyId,
            'dealership_id' => $this->dealershipId,
            'department_id' => $this->departmentId,
        ];
    }
}
