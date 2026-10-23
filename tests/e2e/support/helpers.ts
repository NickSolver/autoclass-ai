import { expect, type Page } from '@playwright/test';
import crypto from 'node:crypto';

/** Пароль демо-пользователей (database/seeders/DemoSeeder.php). */
export const DEMO_PASSWORD = 'Demo-Password-2026';

export async function login(page: Page, loginName: string, password = DEMO_PASSWORD): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Логин').fill(loginName);
    await page.getByLabel('Пароль').fill(password);
    await page.getByRole('button', { name: 'Войти' }).click();
}

export async function logout(page: Page): Promise<void> {
    await page.locator('header button[aria-haspopup=menu]').click();
    await page.getByRole('menuitem', { name: 'Выйти' }).click();
    await expect(page).toHaveURL(/\/login/);
}

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...input.replace(/[\s=]/g, '').toUpperCase()].map((c) => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
    return Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)));
}

function totpAt(secret: string, step: number): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(step));
    const hmac = crypto.createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = hmac[hmac.length - 1]! & 0xf;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

/**
 * Код TOTP из нового 30-секундного шага: сервер не принимает повторно код уже использованного шага.
 */
export class TotpClock {
    private lastStep = 0;

    constructor(private readonly secret: string) {}

    async next(): Promise<string> {
        while (Math.floor(Date.now() / 30_000) <= this.lastStep) {
            await new Promise((r) => setTimeout(r, 500));
        }
        this.lastStep = Math.floor(Date.now() / 30_000);
        return totpAt(this.secret, this.lastStep);
    }
}
