import { expect, test } from '@playwright/test';
import { login, logout, selectOptionContaining, sineWav } from './support/helpers';

// Этап C на тестовом провайдере. Файл назван так, чтобы выполняться последним:
// он включает провайдеров, а предыдущие тесты ожидают, что их нет.
test('настройка тестовых провайдеров, расшифровка, оценка и выдуманная цитата', async ({ page }) => {
    await login(page, 'demo.admin');
    await page.goto('/ai-providers');
    await expect(page.getByText('Передача записей внешним провайдерам')).toBeVisible();
    await expect(page.getByText('Запрещена', { exact: true })).toBeVisible();

    // Два тестовых провайдера: распознавание и оценка.
    for (const section of ['Распознавание речи', 'Оценка разговора']) {
        const card = page.locator('section', { hasText: section }).first();
        await card.getByRole('button', { name: 'Добавить' }).click();
        await page.getByLabel('Название').fill(`Тест: ${section}`);
        await page.locator('button[form=provider-form]').click();
        await expect(card.getByText(`Тест: ${section}`)).toBeVisible();
        await card.getByRole('button', { name: 'Сделать основным' }).click();
        await expect(card.getByText('Основной', { exact: true })).toBeVisible();
    }
    await page.getByRole('button', { name: 'Проверить' }).first().click();
    await expect(page.getByText(/Провайдер отвечает/)).toBeVisible();

    // Звонок проходит весь конвейер (очередь на стенде e2e синхронная).
    await page.goto('/calls/upload');
    await selectOptionContaining(page, 'Подразделение', 'Демо Авто Север');
    await page.getByLabel('Направление').selectOption({ label: 'Входящий' });
    await page.locator('input[type=file]').setInputFiles([{ name: 'оценка.wav', mimeType: 'audio/wav', buffer: sineWav(36, 520) }]);
    await page.getByRole('button', { name: /^Загрузить/ }).click();
    const row = page.locator('li', { hasText: 'оценка.wav' });
    await expect(row.getByText('Загружен')).toBeVisible();
    await row.getByRole('link', { name: /Звонок #\d+/ }).click();

    await expect(page.getByText('Готово').first()).toBeVisible();
    await expect(page.getByText('ТЕСТОВЫЕ ДАННЫЕ')).toBeVisible();
    await expect(page.getByText('Входящий — продажа (DEMO)').first()).toBeVisible();
    await expect(page.getByText('DEMO — не для кадровых выводов').first()).toBeVisible();
    await expect(page.getByText('Полная оценка').first()).toBeVisible();
    await expect(page.getByText('Здравствуйте, дилерский центр, меня зовут Анна. Чем могу помочь?', { exact: true })).toBeVisible();

    // Клик по доказательству перематывает запись к реплике.
    await page.getByRole('button', { name: /0:00 · «Здравствуйте/ }).first().click();
    await expect(page.locator('li[id^="segment-"]').first()).toHaveClass(/bg-amber-50/);
    const callUrl = page.url();

    // Модель «выдумывает» цитату — результат уходит на проверку человеку.
    await page.goto('/ai-providers');
    const llm = page.locator('section', { hasText: 'Оценка разговора' }).first();
    await llm.getByRole('button', { name: 'Изменить' }).click();
    await page.getByLabel('Режим тестового провайдера').selectOption({ label: 'Ошибка: выдуманная цитата' });
    await page.locator('button[form=provider-form]').click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    await page.goto(callUrl);
    await page.getByRole('button', { name: 'Обработать повторно' }).click();
    await expect(page.getByText('Требуется вмешательство человека').first()).toBeVisible();
    await expect(page.getByText(/цитаты нет в указанной реплике/).first()).toBeVisible();
    await expect(page.getByText('нет итога').first()).toBeVisible();

    await page.goto('/calls?review_required=1');
    await expect(page.getByText('на проверке').first()).toBeVisible();

    // Журнал вызовов — только метаданные.
    await page.goto('/ai-providers');
    await page.getByRole('button', { name: 'Журнал вызовов' }).click();
    await expect(page.getByText('Распознавание').first()).toBeVisible();
    await expect(page.getByText('Оценка').first()).toBeVisible();
    await logout(page);

    // Администратор компании не настраивает провайдеров.
    await login(page, 'demo.north.admin');
    expect((await page.request.get('/api/v1/ai-providers')).status()).toBe(403);
});
