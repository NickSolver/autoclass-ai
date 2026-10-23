<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Контракт с фронтендом: каждый список отдаётся как {data: [...]}, постраничные — ещё и с meta.
 * (Раньше /roles и /groups отдавали «голый» массив — интерфейс падал.)
 */
class ResponseShapeTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function lists(): array
    {
        return [
            'groups' => ['/api/v1/groups', false],
            'roles' => ['/api/v1/roles', false],
            'permissions' => ['/api/v1/permissions', false],
            'org tree' => ['/api/v1/org/tree', false],
            'sessions' => ['/api/v1/auth/sessions', false],
            'companies' => ['/api/v1/companies', true],
            'dealerships' => ['/api/v1/dealerships', true],
            'departments' => ['/api/v1/departments', true],
            'employees' => ['/api/v1/employees', true],
            'users' => ['/api/v1/users', true],
            'audit' => ['/api/v1/audit', true],
            'calls' => ['/api/v1/calls', true],
        ];
    }

    #[Test]
    #[DataProvider('lists')]
    public function lists_are_wrapped_in_data(string $url, bool $paginated): void
    {
        $this->buildOrg();

        $response = $this->actingAs($this->superadmin())->getJson($url)->assertOk();

        $this->assertIsArray($response->json('data'), "{$url}: ожидается массив в поле data");
        $this->assertTrue(array_is_list($response->json('data')), "{$url}: data должен быть списком");
        if ($paginated) {
            $response->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        }
    }
}
