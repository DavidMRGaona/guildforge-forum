import { describe, expect, it } from 'vitest';
import { postDraft } from '../../../resources/js/editor/draft';

const DOCUMENT = {
    type: 'doc',
    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'A medias' }] }],
};

describe('post drafts', () => {
    it('keeps a draft per key until it is cleared', () => {
        const draft = postDraft('discussion-1');
        draft.save(DOCUMENT);

        expect(postDraft('discussion-1').load()).toEqual(DOCUMENT);
        expect(postDraft('discussion-2').load()).toBeNull();

        draft.clear();
        expect(postDraft('discussion-1').load()).toBeNull();
    });

    it('forgets drafts older than a month', () => {
        postDraft('old', () => 0).save(DOCUMENT);

        expect(postDraft('old', () => 31 * 24 * 60 * 60 * 1000).load()).toBeNull();
        expect(localStorage.getItem('guildforge:forum:draft:old')).toBeNull();
    });

    it('ignores what is not a draft and keeps nothing without a key', () => {
        localStorage.setItem('guildforge:forum:draft:broken', '{no es json');
        expect(postDraft('broken').load()).toBeNull();

        const none = postDraft(null);
        none.save(DOCUMENT);
        expect(none.load()).toBeNull();
    });
});
