# Автокласс AI — заметки для ИИ-агента

- ТЗ: `docs/avtoklass_ai_tz_v1_0.md`; план этапа A: `docs/plan-stage-a.md`; модель доступа: `docs/adr/0001-rbac-and-org-scope.md`.
- PHP/Composer есть **только в контейнере**: `docker compose exec -u $(id -u):$(id -g) app <cmd>`. Node — на хосте.
- Проверки перед сдачей: `php artisan test`, `vendor/bin/pint --test`, `composer analyse`, `npm run lint`, `npm run typecheck`, `npm test`, `npm run build`.
- Тесты идут на MySQL-базе `laravel_test` (см. `phpunit.xml`), каждому тесту — `RoleSeeder`. Защита в `tests/TestCase.php`:
  база без суффикса `_test` — тесты останавливаются (иначе `RefreshDatabase` сотрёт рабочую базу).
- E2E: `npm run test:e2e` — только на стенде `laravel_e2e` (порт 8081), никогда на рабочей базе.
- Откат миграций (`migrate:rollback`) проверять только на тестовой базе (`DB_DATABASE=laravel_test`), не на рабочей:
  откат удаляет таблицы вместе с данными.

## Правила

- Доступ проверяет только сервер. Бизнес-логика проверяет **права** (`Permission`), никогда не коды ролей.
- Новая сущность, фильтруемая по области, хранит путь `group_id…department_id` и подключает `HasOrgScope`;
  привязка модели из URL — через `Route::bind` с `visibleTo()` в `routes/api.php` (объект вне области → 404).
- Изменения справочников аудируются трейтом `Auditable`; секреты — только через `auditSensitive()`.
- Не придумывать API внешних систем (Calltouch, «Лягушка», ASR/LLM) — только контракт и mock до получения документации.
- Недоступные модули — экран «Модуль в разработке» (`resources/js/navigation.ts`), без фиктивных данных.
- UI-тексты — на русском.
