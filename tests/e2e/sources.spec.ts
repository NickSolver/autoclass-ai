import { expect, test } from '@playwright/test';
import { login } from './support/helpers';

// Этап F (каркас): подключение тестового источника, синхронизация без дублей, журнал событий.
// Calltouch и «Лягушка» видны, но подключить их нельзя до получения документации.
test('тестовый источник: подключение, синхронизация, повтор без дублей', async ({ page }) => {
    await login(page, 'demo.admin');
    await page.goto('/sources');
    await expect(page.getByText('ждёт документации', { exact: true })).toHaveCount(2);

    await page.locator('section', { hasText: 'Тестовый источник' }).first().getByRole('button', { name: 'Подключить' }).click();
    await page.getByLabel('Название').fill('Тестовая телефония');
    const unit = page.getByLabel('Куда поступают звонки', { exact: false }).locator('xpath=self::select');
    await unit.selectOption((await unit.locator('option', { hasText: 'Отдел продаж' }).first().getAttribute('value')) ?? '');
    await page.getByLabel('Всего звонков в источнике').fill('4');
    await page.getByLabel('Добавочные сотрудников').fill('101');
    await page.getByRole('button', { name: 'Создать', exact: true }).click();
    await expect(page).toHaveURL(/\/sources\/\d+/);
    await expect(page.getByText('Тестовый источник: звонки синтетические')).toBeVisible();

    await page.getByRole('button', { name: 'Проверить связь' }).click();
    await expect(page.getByText('Тестовый источник отвечает')).toBeVisible();

    await page.getByRole('button', { name: 'Синхронизировать' }).click();
    const runs = page.getByTestId('sync-runs');
    await expect(runs.locator('tbody tr').first()).toContainText('успешно');
    await expect(runs.locator('tbody tr').first().locator('td').nth(4)).toHaveText('4');
    await expect(page.getByTestId('source-events').getByText('принят')).toHaveCount(4);

    // Повтор той же выгрузки после сброса курсора невозможен из интерфейса — повторная синхронизация
    // просто ничего не находит; дубли проверяет тест сервера. Здесь — что новых звонков нет.
    await page.getByRole('button', { name: 'Синхронизировать' }).click();
    await expect(runs.locator('tbody tr')).toHaveCount(2);
    await expect(runs.locator('tbody tr').first().locator('td').nth(4)).toHaveText('0');

    await page.getByTestId('source-events').getByRole('link', { name: /Звонок #\d+/ }).first().click();
    await expect(page.getByRole('heading', { name: /Звонок #\d+/ })).toBeVisible();
    await expect(page.getByText('Иванов Пётр').first()).toBeVisible();
});
