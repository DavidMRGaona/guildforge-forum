import { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import { afterEach, describe, expect, it } from 'vitest';
import { createForumExtensions } from '../../../resources/js/editor/extensions';

const POST = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

let editor: Editor | null = null;

function makeEditor(content: JSONContent): Editor {
    editor = new Editor({
        content,
        extensions: createForumExtensions({
            imageUrl: () => null,
            mentionNames: { nameOf: () => null },
            mentionSuggestion: null,
        }),
    });

    return editor;
}

function withReference(label: string | null): JSONContent {
    return {
        type: 'doc',
        content: [
            {
                type: 'paragraph',
                content: [
                    { type: 'text', text: 'Mira ' },
                    { type: 'postReference', attrs: { postId: POST, label } },
                ],
            },
        ],
    };
}

afterEach(() => {
    editor?.destroy();
    editor = null;
});

describe('a reference to a post in the editor', () => {
    it('shows the number the server labelled it with', () => {
        const reference = makeEditor(withReference('#3')).view.dom.querySelector('.forum-ref');

        expect(reference?.textContent).toBe('#3');
        expect(reference?.getAttribute('data-post-id')).toBe(POST);
    });

    it('shows an ellipsis without a label', () => {
        expect(
            makeEditor(withReference(null)).view.dom.querySelector('.forum-ref')?.textContent
        ).toBe('#…');
    });

    it('leaves no number in the text without a label', () => {
        expect(makeEditor(withReference(null)).getText()).toBe('Mira ');
    });

    it('keeps its post and label in the document', () => {
        expect(makeEditor(withReference('#3')).getJSON()).toEqual(withReference('#3'));
    });

    it('is an atom: it goes whole, never a character at a time', () => {
        const type = makeEditor(withReference('#3')).schema.nodes['postReference']!;

        expect(type.spec.atom).toBe(true);
        expect(type.isLeaf).toBe(true);
        expect(type.isInline).toBe(true);
    });

    it('takes a reference link of a rendered post when pasted', () => {
        const current = new Editor({
            content: `<p>Ver <a class="forum-ref" href="/foro/t/1-x/3" data-post-id="${POST}">#3</a></p>`,
            extensions: createForumExtensions({
                imageUrl: () => null,
                mentionNames: { nameOf: () => null },
                mentionSuggestion: null,
            }),
        });
        editor = current;

        expect(current.getJSON().content?.[0]?.content?.[1]).toEqual({
            type: 'postReference',
            attrs: { postId: POST, label: '#3' },
        });
    });
});
