import { describe, expect, it } from 'vitest';
import indexSource from '../../../resources/js/pages/Forum/Index.vue?raw';
import categorySource from '../../../resources/js/pages/Forum/Category.vue?raw';

/**
 * The forum and category headers put the title and up to four actions side by side. From `sm` the
 * action group could shrink, so a long description pushed «Nuevo tema» to a second row on its own,
 * at the start, with a gap at the end. The row now starts at `lg`, where the actions fit, and the
 * group neither shrinks nor leaves the main action hanging (forum#103).
 */
function classesOf(source: string, testId: string): string[] {
    const tag = new RegExp(`<div[^>]*data-testid="${testId}"[^>]*>`).exec(source)?.[0] ?? '';
    const classes = /\sclass="([^"]*)"/.exec(tag)?.[1] ?? '';

    return classes.split(/\s+/).filter((name) => name !== '');
}

describe.each([
    ['forum index', indexSource],
    ['category', categorySource],
])('%s header', (_name, source) => {
    it('puts the title and the actions in one row only from lg', () => {
        const row = classesOf(source, 'forum-header');

        expect(row).toEqual(
            expect.arrayContaining(['flex', 'flex-col', 'lg:flex-row', 'lg:justify-between'])
        );
        expect(row.filter((name) => name.startsWith('sm:flex-'))).toEqual([]);
    });

    it('keeps the actions on one row at the end once they sit beside the title', () => {
        const actions = classesOf(source, 'forum-header-actions');

        expect(actions).toEqual(
            expect.arrayContaining(['flex', 'flex-wrap', 'lg:shrink-0', 'lg:justify-end'])
        );
    });
});
