import { describe, expect, it } from 'vitest';
import { auditActionLabel, formatDate, scopeLabel } from './format';

describe('format', () => {
    it('formats date-only values without timezone shift', () => {
        expect(formatDate('2026-01-01')).toContain('2026');
        expect(formatDate('2026-01-01')).toContain('1');
        expect(formatDate(null)).toBe('—');
    });

    it('labels known audit actions and falls back to the code', () => {
        expect(auditActionLabel('auth.login')).toBe('Вход в систему');
        expect(auditActionLabel('custom.action')).toBe('custom.action');
    });

    it('labels scope types', () => {
        expect(scopeLabel('own')).toBe('Собственные записи');
    });
});
