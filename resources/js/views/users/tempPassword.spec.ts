import { describe, expect, it } from 'vitest';
import { generateTemporaryPassword } from './tempPassword';

describe('generateTemporaryPassword', () => {
    it('satisfies the server password policy', () => {
        for (let i = 0; i < 50; i++) {
            const p = generateTemporaryPassword();
            expect(p).toHaveLength(16);
            expect(p).toMatch(/[a-z]/);
            expect(p).toMatch(/[A-Z]/);
            expect(p).toMatch(/[0-9]/);
        }
    });

    it('produces different values', () => {
        expect(generateTemporaryPassword()).not.toBe(generateTemporaryPassword());
    });
});
