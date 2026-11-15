import { Node } from '@tiptap/core';
import type { Attributes } from '@tiptap/core';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';

/**
 * A reference to another post («#3»). It keeps the post's id; the label is the number the
 * server gave it when the post was opened for editing, and the server drops it on saving. A
 * «#3» typed while writing stays text until the post is saved: then the server turns it.
 */
export const PostReference = Node.create({
    name: 'postReference',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes(): Attributes {
        return {
            postId: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('data-post-id'),
                renderHTML: (attributes: Record<string, unknown>) => ({
                    'data-post-id': attributes['postId'],
                }),
            },
            label: {
                default: null,
                parseHTML: (element: HTMLElement): string | null => {
                    const text = element.textContent?.trim() ?? '';

                    return /^#(\d{1,10}|\?)$/u.test(text) ? text : null;
                },
                renderHTML: () => ({}),
            },
        };
    },

    parseHTML(): TagParseRule[] {
        // Ahead of links: a reference copied from a rendered post is a reference, not a link
        return [
            { tag: 'a.forum-ref[data-post-id]', priority: 60 },
            { tag: 'span.forum-ref[data-post-id]', priority: 60 },
        ];
    },

    renderHTML({ node, HTMLAttributes }): DOMOutputSpec {
        const label = node.attrs['label'];

        return [
            'span',
            { ...HTMLAttributes, class: 'forum-ref' },
            typeof label === 'string' && label !== '' ? label : '#…',
        ];
    },

    renderText({ node }): string {
        const label = node.attrs['label'];

        return typeof label === 'string' ? label : '';
    },
});
