import { expect, test } from '@playwright/test';
import { login, logout } from './support/helpers';

test.describe('Вход и выход', () => {
    test('неверный пароль — общая ошибка, без раскрытия существования логина', async ({ page }) => {
        await login(page, 'demo.qc', 'wrong-password');
        await expect(page.getByText('Неверный логин или пароль.')).toBeVisible();
        await login(page, 'no.such.user', 'wrong-password');
        await expect(page.getByText('Неверный логин или пароль.')).toBeVisible();
        await expect(page).toHaveURL(/\/login/);
    });

    test('вход, недоступный модуль и выход', async ({ page }) => {
        await login(page, 'demo.qc');
        await expect(page.getByRole('heading', { name: /Здравствуйте, Демо Контролёр качества/ })).toBeVisible();

        await page.getByRole('link', { name: 'История звонков' }).first().click();
        await expect(page.getByRole('heading', { name: /в разработке/ })).toBeVisible();

        await logout(page);
        await page.goto('/users');
        await expect(page).toHaveURL(/\/login\?redirect=\/users/);
    });

    test('публичной регистрации нет', async ({ page }) => {
        await page.goto('/register');
        await expect(page).toHaveURL(/\/login/);
        await expect(page.getByText(/зарегистр/i)).toHaveCount(0);
    });
});
