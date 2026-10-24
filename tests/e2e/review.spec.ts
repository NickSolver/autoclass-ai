import { expect, test, type Page } from '@playwright/test';
import { login, logout, sineWav } from './support/helpers';

// Этап E, приёмка ТЗ: автоматический и исправленный результаты различимы; неизвестные оценки не искажают
// среднее; журнал изменений полон. Выполняется после pipeline.spec.ts: тестовая оценка «выдумывает» цитату —
// результат требует вмешательства человека и попадает в очередь проверок.

async function selectOption(page: Page, label: string, text: string): Promise<void> {
    const select = page.getByLabel(label, { exact: false }).locator('xpath=self::select');
    const value = await select.locator('option', { hasText: text }).first().getAttribute('value');
    await select.selectOption(value ?? '');
}

test('проверка контролёром, апелляция сотрудника, независимое решение и аналитика', async ({ page }) => {
    // Звонок менеджера Иванова в отдел продаж.
    await login(page, 'demo.admin');
    await page.goto('/calls/upload');
    await selectOption(page, 'Подразделение', 'Отдел продаж');
    await selectOption(page, 'Сотрудник', 'Иванов');
    await page.getByLabel('Направление').selectOption({ label: 'Входящий' });
    await page.locator('input[type=file]').setInputFiles([{ name: 'проверка.wav', mimeType: 'audio/wav', buffer: sineWav(36, 700) }]);
    await page.getByRole('button', { name: /^Загрузить/ }).click();
    const row = page.locator('li', { hasText: 'проверка.wav' });
    await expect(row.getByText('Загружен')).toBeVisible();
    await row.getByRole('link', { name: /Звонок #\d+/ }).click();
    await expect(page.getByText('Готово').first()).toBeVisible();
    const callUrl = page.url();
    const callId = callUrl.split('/').pop();
    await expect(page.getByTestId('review-panel')).toContainText('ожидает');
    await logout(page);

    // Контролёр берёт проверку из очереди и исправляет критерий основного чек-листа.
    await login(page, 'demo.qc');
    await page.goto('/review');
    const item = page.locator('tr', { hasText: `Звонок #${callId}` });
    await item.getByRole('button', { name: 'Взять' }).click();
    await expect(page).toHaveURL(callUrl);
    await page.getByRole('button', { name: 'Вынести решение' }).click();
    const form = page.getByTestId('resolve-form');
    const primary = form.locator('section').first();
    await primary.getByLabel('Исправить критерии').check();
    const editor = primary.getByTestId('criteria-editor');
    await editor.locator('select').first().selectOption('fail');
    await editor.getByPlaceholder('Причина изменения (обязательно)').fill('Цитаты нет в записи — приветствие не прозвучало');
    await form.getByLabel('Комментарий к решению').fill('Прослушал запись целиком');
    await form.getByRole('button', { name: 'Завершить проверку' }).click();
    await expect(page.getByTestId('review-panel')).toContainText('результат исправлен');
    await expect(page.getByTestId('result-origin').first()).toHaveText('исправлено человеком');
    await page.getByTestId('result-history').first().locator('summary').click();
    await expect(page.getByTestId('result-history').first()).toContainText('Ревизия 1 · оценка ИИ');
    await expect(page.getByTestId('result-history').first()).toContainText('Цитаты нет в записи');
    await logout(page);

    // Сотрудник подаёт апелляцию на свою оценку.
    await login(page, 'demo.north.manager');
    await page.goto(callUrl);
    await page.getByRole('button', { name: 'Подать апелляцию' }).click();
    await page.getByLabel('С чем вы не согласны').fill('Я поздоровался в начале разговора, проверьте первые секунды.');
    await page.getByRole('button', { name: 'Подать', exact: true }).click();
    await expect(page.getByTestId('appeal').first()).toContainText('подана');
    await logout(page);

    // Контролёр, исправивший оценку, апелляцию не решает.
    await login(page, 'demo.qc');
    await page.goto(callUrl);
    await expect(page.getByTestId('appeal').first()).toContainText('не тот, кто выставил');
    await expect(page.getByRole('button', { name: 'Взять на рассмотрение' })).toHaveCount(0);
    await logout(page);

    // Независимый пользователь отклоняет апелляцию с обоснованием.
    await login(page, 'demo.admin');
    await page.goto(callUrl);
    await page.getByRole('button', { name: 'Взять на рассмотрение' }).click();
    await page.getByRole('button', { name: 'Вынести решение' }).click();
    await page.getByLabel('Обоснование решения').fill('Прослушано: приветствия нет');
    await page.getByRole('dialog').getByRole('button', { name: 'Вынести решение' }).click();
    await expect(page.getByTestId('appeal').first()).toContainText('отклонена');

    // Аналитика различает итоговость и показывает объём выборки.
    await page.goto('/analytics');
    await expect(page.getByTestId('analytics-period')).toContainText('с итоговой оценкой');
    await expect(page.getByTestId('kpi-finality')).toContainText('Исправлено человеком');
    await expect(page.getByTestId('kpi-finality').locator('dd').nth(2)).not.toHaveText('0');
    await expect(page.getByRole('link', { name: 'Выгрузить CSV' })).toBeVisible();
});
