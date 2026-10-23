<?php

namespace Tests\Unit;

use App\Domain\Identity\Access\AccessScope;
use App\Domain\Organization\OrgPath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AccessScopeTest extends TestCase
{
    #[Test]
    public function empty_scope_covers_nothing(): void
    {
        $scope = AccessScope::none();

        $this->assertTrue($scope->isEmpty());
        $this->assertFalse($scope->covers(new OrgPath(1, 2, 3, 4)));
        $this->assertFalse($scope->covers(null));
    }

    #[Test]
    public function scope_is_inherited_downwards_only(): void
    {
        $company = new AccessScope(companyIds: [2]);

        $this->assertTrue($company->covers(new OrgPath(1, 2)));
        $this->assertTrue($company->covers(new OrgPath(1, 2, 3, 4)));
        $this->assertFalse($company->covers(new OrgPath(1)), 'Компания не покрывает уровень группы');
        $this->assertFalse($company->covers(new OrgPath(1, 5, 6)), 'Компания не покрывает соседнюю компанию');
    }

    #[Test]
    public function dealership_scope_does_not_cover_company_level_objects(): void
    {
        $dealership = new AccessScope(dealershipIds: [3]);

        $this->assertTrue($dealership->covers(new OrgPath(1, 2, 3)));
        $this->assertTrue($dealership->covers(new OrgPath(1, 2, 3, 9)));
        $this->assertFalse($dealership->covers(new OrgPath(1, 2)));
        $this->assertFalse($dealership->covers(new OrgPath(1, 2, null, 9)), 'Отдел уровня компании вне центра');
    }

    #[Test]
    public function group_scope_covers_everything_in_group(): void
    {
        $group = new AccessScope(groupIds: [1]);

        $this->assertTrue($group->covers(new OrgPath(1)));
        $this->assertTrue($group->covers(new OrgPath(1, 2, 3, 4)));
        $this->assertFalse($group->covers(new OrgPath(7, 8)));
    }

    #[Test]
    public function own_only_scope_is_not_empty_but_covers_no_org_path(): void
    {
        $own = new AccessScope(own: true);

        $this->assertFalse($own->isEmpty());
        $this->assertFalse($own->hasOrgUnits());
        $this->assertFalse($own->covers(new OrgPath(1, 2)));
    }
}
