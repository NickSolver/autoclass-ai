import { expect, test, type Page } from '@playwright/test';
import { login, sineWav } from './support/helpers';

// Этап D, приёмка ТЗ: «если входящий и отдел продаж → основной Продажи + дополнительный Стандарты → оценить;
// если недостаточно данных → проверка» — без изменений кода. Выполняется после pipeline.spec.ts:
// тестовые провайдеры уже включены, тестовая оценка «выдумывает» цитату → требуется вмешательство человека.

const REVIEW_REASON = 'Недостаточно данных — нужна проверка';

async function addNode(page: Page, label: string): Promise<void> {
    await page.getByRole('button', { name: label, exact: true }).click();
    await expect(page.getByTestId('node-properties')).toBeVisible();
}

async function selectNode(page: Page, idPrefix: string): Promise<void> {
    // Узел может быть за краем холста — сначала «показать всё».
    await page.locator('.vue-flow__controls-fitview').click();
    await page.locator(`[data-testid^="node-${idPrefix}"]`).first().click();
}

/** Переход выхода выбранного узла — через список, без перетаскивания. */
async function connectOutput(page: Page, output: string, targetText: string): Promise<void> {
    const select = page.getByTestId('node-properties').getByLabel(`Выход «${output}»`);
    const value = await select.locator('option', { hasText: targetText }).first().getAttribute('value');
    await select.selectOption(value ?? '');
}

test('администратор настраивает маршрут оценки в конструкторе и звонок идёт по нему', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await login(page, 'demo.admin');

    await page.goto('/workflows');
    await page.getByRole('button', { name: 'Создать процесс' }).click();
    await page.getByLabel('Название').fill('Продажи: входящие');
    await page.getByRole('button', { name: 'Создать', exact: true }).click();
    await expect(page).toHaveURL(/\/workflows\/\d+/);
    const props = page.getByTestId('node-properties');

    // Старт → Основной → Дополнительные → Анализ → Условие по результату → Завершение.
    await selectNode(page, 'start');
    await addNode(page, 'Основной чек-лист');
    await props.getByLabel('Основной чек-лист').selectOption({ label: 'Входящий — продажа (DEMO) (демо) · v1' });
    await addNode(page, 'Дополнительные чек-листы');
    await props.getByText('Стандарт коммуникации (DEMO)').click();
    await addNode(page, 'Запустить анализ');
    await addNode(page, 'Условие (если / иначе)');
    await props.getByLabel('Поле').selectOption({ label: 'Статус оценки (основной чек-лист) (после анализа)' });
    await props.getByLabel('Значение').selectOption({ label: 'Требуется вмешательство человека' });
    await connectOutput(page, 'нет', 'Завершение');

    // «Да» → Задача на проверку → Завершение.
    await addNode(page, 'Задача на проверку');
    await props.getByLabel('Причина проверки').fill(REVIEW_REASON);
    await connectOutput(page, 'далее', 'Завершение');
    await selectNode(page, 'condition');
    await connectOutput(page, 'да', 'Задача на проверку');

    // В начале: входящий И отдел продаж; иначе — без оценки.
    await selectNode(page, 'start');
    await addNode(page, 'Условие (если / иначе)');
    await props.getByRole('button', { name: 'Добавить правило' }).click();
    const rule = props.getByTestId('rule').nth(1);
    await rule.getByLabel('Поле').selectOption({ label: 'Тип отдела' });
    await rule.getByLabel('Значение').selectOption({ label: 'Продажи' });
    await connectOutput(page, 'нет', 'Завершение');

    await page.getByRole('button', { name: 'Проверить' }).click();
    await expect(page.getByTestId('graph-errors').getByText('Ошибок нет.')).toBeVisible();

    // Симуляция без побочных эффектов.
    await page.getByRole('button', { name: 'Симуляция', exact: true }).click();
    const sim = page.getByTestId('simulation');
    await sim.getByLabel('Тип отдела').selectOption({ label: 'Продажи' });
    await sim.getByLabel('Статус оценки (основной чек-лист)').selectOption({ label: 'Требуется вмешательство человека' });
    await sim.getByRole('button', { name: 'Запустить симуляцию' }).click();
    await expect(page.getByTestId('sim-primary')).toHaveText('Входящий — продажа (DEMO)');
    await expect(page.getByTestId('sim-additional')).toHaveText('Стандарт коммуникации (DEMO)');
    await expect(page.getByTestId('sim-review')).toContainText(REVIEW_REASON);

    await page.getByRole('button', { name: 'Опубликовать' }).click();
    await expect(page.getByText('Версия 1 действует')).toBeVisible();

    // Входящий звонок в отдел продаж проходит по процессу.
    await page.goto('/calls/upload');
    const select = page.getByLabel('Подразделение', { exact: false }).locator('xpath=self::select');
    const value = await select.locator('option', { hasText: 'Отдел продаж' }).first().getAttribute('value');
    await select.selectOption(value ?? '');
    await page.getByLabel('Направление').selectOption({ label: 'Входящий' });
    await page.locator('input[type=file]').setInputFiles([{ name: 'процесс.wav', mimeType: 'audio/wav', buffer: sineWav(36, 610) }]);
    await page.getByRole('button', { name: /^Загрузить/ }).click();
    const row = page.locator('li', { hasText: 'процесс.wav' });
    await expect(row.getByText('Загружен')).toBeVisible();
    await row.getByRole('link', { name: /Звонок #\d+/ }).click();

    await expect(page.getByText('Готово').first()).toBeVisible();
    await expect(page.getByTestId('call-review')).toContainText(REVIEW_REASON);
    await expect(page.getByText('Стандарт коммуникации (DEMO)').first()).toBeVisible();
    await expect(page.getByTestId('workflow-trace')).toContainText('Продажи: входящие · версия 1');
    await expect(page.getByTestId('workflow-trace')).toContainText('Задача на проверку');
});
