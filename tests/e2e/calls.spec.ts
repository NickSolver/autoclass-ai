import { expect, test } from '@playwright/test';
import crypto from 'node:crypto';
import { login, logout, selectOptionContaining, sineWav } from './support/helpers';

// Этап B — ручная загрузка. На стенде e2e очередь синхронная: подготовка аудио выполняется сразу.
test('загрузка, дубль, повреждённый файл, карточка звонка и изоляция', async ({ page }) => {
    await login(page, 'demo.north.admin');
    await page.goto('/calls/upload');
    await expect(page.getByText(/Поддерживаются: MP3, WAV, M4A/)).toBeVisible();

    await selectOptionContaining(page, 'Подразделение', 'Демо Авто Север');
    await page.getByLabel('Сотрудник').selectOption({ label: 'Иванов Пётр' });
    await page.getByLabel('Направление').selectOption({ label: 'Входящий' });

    const good = { name: 'тест-звонок.wav', mimeType: 'audio/wav', buffer: sineWav(3, 440) };
    const broken = { name: 'повреждён.mp3', mimeType: 'audio/mpeg', buffer: Buffer.concat([Buffer.from('ID3'), crypto.randomBytes(3000)]) };
    await page.locator('input[type=file]').setInputFiles([good, broken]);
    await page.getByRole('button', { name: /^Загрузить/ }).click();

    const goodRow = page.locator('li', { hasText: 'тест-звонок.wav' });
    const brokenRow = page.locator('li', { hasText: 'повреждён.mp3' });
    await expect(goodRow.getByText('Загружен')).toBeVisible();
    await expect(brokenRow.getByText(/повреждён или не читается/)).toBeVisible();
    const callLink = goodRow.getByRole('link', { name: /Звонок #\d+/ });
    const callId = Number((await callLink.innerText()).replace(/\D/g, ''));

    // Тот же файл ещё раз — предупреждение о дубле, загрузка только с подтверждением.
    await page.locator('input[type=file]').setInputFiles([good]);
    await page.getByRole('button', { name: /^Загрузить/ }).click();
    const dupRow = page.locator('li', { hasText: 'тест-звонок.wav' }).last();
    await expect(dupRow.getByText('Возможный дубль')).toBeVisible();
    await dupRow.getByRole('button', { name: 'Загрузить всё равно' }).click();
    await expect(dupRow.getByText('Загружен')).toBeVisible();

    // Карточка звонка.
    await page.goto(`/calls/${callId}`);
    await expect(page.getByRole('heading', { name: `Звонок #${callId}` })).toBeVisible();
    await expect(page.getByText('Ожидает распознавания').first()).toBeVisible();
    await expect(page.getByText('Подготовка аудио — попытка 1')).toBeVisible();
    await expect(page.getByText('Иванов Пётр').first()).toBeVisible();
    await expect(page.locator('audio')).toHaveAttribute('src', `/api/v1/calls/${callId}/audio`);

    const audio = await page.request.get(`/api/v1/calls/${callId}/audio`, { headers: { Range: 'bytes=0-99' } });
    expect(audio.status()).toBe(206);
    expect(audio.headers()['content-type']).toBe('audio/wav');

    // Исключение с причиной и возврат в обработку.
    await page.getByRole('button', { name: 'Исключить' }).click();
    await page.getByLabel('Причина').fill('Проверка e2e');
    await page.getByRole('dialog').getByRole('button', { name: 'Исключить' }).click();
    await expect(page.getByText('Исключён из обработки', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Вернуть в обработку' }).click();
    await expect(page.getByText('Ожидает распознавания').first()).toBeVisible();

    // Список: звонок виден директору своей компании и не виден администратору другой.
    await logout(page);
    await login(page, 'demo.north.director');
    await page.goto('/calls');
    await expect(page.getByRole('link', { name: `#${callId}` })).toBeVisible();
    await logout(page);

    await login(page, 'demo.south.admin');
    await expect(page.getByRole('heading', { name: /Здравствуйте/ })).toBeVisible();
    expect((await page.request.get(`/api/v1/calls/${callId}`)).status()).toBe(404);
    expect((await page.request.get(`/api/v1/calls/${callId}/audio`)).status()).toBe(404);
});
