import { expect, test } from '@playwright/test';
import { login, logout, TotpClock } from './support/helpers';

// Двухфакторная аутентификация администратора (ADR-0002). На стенде e2e повторный код
// для критичных действий требуется сразу (AUTH_MFA_STEP_UP_MINUTES=0).
test('подключение MFA, вход по коду и подтверждение критичного действия', async ({ page }) => {
    await login(page, 'demo.south.admin');

    // Льготный период: напоминание и переход к подключению.
    await expect(page.getByText(/обязательна двухфакторная аутентификация/)).toBeVisible();
    await page.getByRole('link', { name: 'Подключить' }).first().click();
    await expect(page.getByRole('img', { name: /QR-код/ })).toBeVisible();

    await page.getByText('Не получается отсканировать?').click();
    const secret = (await page.locator('code.select-all').innerText()).replace(/\s/g, '');
    const totp = new TotpClock(secret);

    await page.getByLabel('Код из приложения').fill('000000');
    await page.getByRole('button', { name: 'Подключить' }).click();
    await expect(page.getByText(/Неверный код/)).toBeVisible();

    await page.getByLabel('Код из приложения').fill(await totp.next());
    await page.getByRole('button', { name: 'Подключить' }).click();
    await expect(page.getByText('Сохраните резервные коды')).toBeVisible();
    const codes = await page.locator('ol.font-mono li').allInnerTexts();
    expect(codes).toHaveLength(10);
    await page.getByText('Я сохранил резервные коды').click();
    await page.getByRole('button', { name: 'Готово' }).click();
    await expect(page.getByText(/обязательна двухфакторная аутентификация/)).toHaveCount(0);

    // Повторный вход: без кода дальше не пустит.
    await logout(page);
    await login(page, 'demo.south.admin');
    await expect(page).toHaveURL(/\/mfa\/verify/);
    expect((await page.request.get('/api/v1/companies')).status()).toBe(403);

    await page.getByLabel('Код').fill(await totp.next());
    await page.getByRole('button', { name: 'Подтвердить' }).click();
    await expect(page.getByRole('heading', { name: /Здравствуйте/ })).toBeVisible();

    // Создание пользователя и назначение роли: роль — критичное действие, нужен код.
    await page.goto('/users');
    await page.getByRole('button', { name: 'Создать пользователя' }).click();
    await page.getByLabel('Имя').fill('E2E Менеджер');
    await page.getByLabel('Логин').fill('e2e.manager');
    const home = page.getByLabel('Подразделение');
    await home.selectOption(await home.locator('option', { hasText: 'Демо Авто Юг' }).first().getAttribute('value'));
    await page.locator('button[form=user-form]').click();
    await expect(page.getByRole('heading', { name: 'E2E Менеджер' })).toBeVisible();

    await page.getByRole('button', { name: 'Назначить роль' }).click();
    const grant = page.locator('#grant-form select');
    await grant.nth(0).selectOption({ label: 'Директор / руководитель группы' });
    await grant.nth(1).selectOption({ label: 'Компания' });
    await grant.nth(2).selectOption(await grant.nth(2).locator('option', { hasText: 'Демо Авто Юг' }).first().getAttribute('value'));
    await page.locator('button[form=grant-form]').click();

    await expect(page.getByRole('dialog', { name: 'Подтвердите действие' })).toBeVisible();
    await page.locator('#step-up-form input').fill(await totp.next());
    await page.locator('button[form=step-up-form]').click();
    await expect(page.getByText('Роль назначена.')).toBeVisible();
    await expect(page.getByText('Директор / руководитель группы')).toBeVisible();

    // Резервный код: срабатывает один раз.
    await logout(page);
    await login(page, 'demo.south.admin');
    await page.getByRole('button', { name: /Резервный код/ }).click();
    await page.getByLabel('Резервный код').fill(codes[0]!);
    await page.getByRole('button', { name: 'Подтвердить' }).click();
    await expect(page.getByRole('heading', { name: /Здравствуйте/ })).toBeVisible();
});
