import { describe, expect, it } from 'vitest';

/**
 * The core defines its colour tokens as plain classes (`border-default`, `text-base-muted`…), not
 * as Tailwind theme colours. `divide-default` therefore generates nothing: the dividers fall back
 * to `currentColor` and show almost black. Lists use `divide-[var(--color-border)]`, as the core.
 */
const SOURCES = import.meta.glob<string>('../../../resources/js/**/*.vue', {
    query: '?raw',
    import: 'default',
    eager: true,
});

describe('semantic colour utilities', () => {
    it('reads the module components', () => {
        expect(Object.keys(SOURCES).length).toBeGreaterThan(10);
    });

    it('never uses a divide colour the core does not define', () => {
        const offenders = Object.entries(SOURCES)
            .filter(([, source]) => /\bdivide-(default|strong)\b/.test(source))
            .map(([path]) => path);

        expect(offenders).toEqual([]);
    });
});
