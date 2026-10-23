import { defineConfig, devices } from '@playwright/test';

/**
 * Браузерные e2e-тесты. Запуск: npm run test:e2e (scripts/e2e.sh поднимает изолированный стенд).
 * Тесты меняют данные стенда, поэтому выполняются последовательно на свежей базе.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8081',
        locale: 'ru-RU',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    outputDir: 'storage/e2e-results',
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
