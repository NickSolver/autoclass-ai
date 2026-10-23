import { expect, test } from '@playwright/test';
import { login } from './support/helpers';

// Приёмка этапа A: директор компании A не видит компанию B — и в интерфейсе, и через API.
test('директор «Севера» не видит данные «Юга»', async ({ page }) => {
    await login(page, 'demo.north.director');
    await expect(page.getByRole('heading', { name: /Здравствуйте/ })).toBeVisible();

    await page.goto('/org/companies');
    await expect(page.getByRole('cell', { name: /Демо Авто Север/ })).toBeVisible();
    await expect(page.getByText('Демо Авто Юг')).toHaveCount(0);

    await page.goto('/users');
    await expect(page.getByText('demo.north.admin')).toBeVisible();
    await expect(page.getByText('demo.south.admin')).toHaveCount(0);

    // Прямой запрос к API чужой компании — 404, а не данные.
    const companies = await (await page.request.get('/api/v1/companies?per_page=100')).json();
    const names = companies.data.map((c: { name: string }) => c.name);
    expect(names).toEqual(['Демо Авто Север']);
    for (let id = 1; id <= 5; id++) {
        const r = await page.request.get(`/api/v1/companies/${id}`);
        if (r.ok()) expect((await r.json()).name).toBe('Демо Авто Север');
        else expect(r.status()).toBe(404);
    }

    // Раздел без прав — экран «Нет доступа», а не данные.
    await page.goto('/audit');
    await expect(page.getByRole('heading', { name: 'Нет доступа' })).toBeVisible();
});
