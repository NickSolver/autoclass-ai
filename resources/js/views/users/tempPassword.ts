/** Временный пароль, удовлетворяющий политике (12+ символов, разный регистр, цифры). */
export function generateTemporaryPassword(length = 16): string {
    const lower = 'abcdefghjkmnpqrstuvwxyz';
    const upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    const digits = '23456789';
    const all = lower + upper + digits;
    const random = (max: number) => crypto.getRandomValues(new Uint32Array(1))[0]! % max;
    const pick = (set: string) => set[random(set.length)]!;
    const chars = [pick(lower), pick(upper), pick(digits)];
    while (chars.length < length) chars.push(pick(all));
    for (let i = chars.length - 1; i > 0; i--) {
        const j = random(i + 1);
        [chars[i], chars[j]] = [chars[j]!, chars[i]!];
    }
    return chars.join('');
}
