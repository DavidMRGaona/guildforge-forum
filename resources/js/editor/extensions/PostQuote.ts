import { mergeAttributes, Node } from '@tiptap/core';
import type { Attributes, JSONContent, RawCommands } from '@tiptap/core';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        postQuote: {
            /** Adds a quotation of a forum post at the end of the document, then a paragraph to answer below */
            insertPostQuote: (postId: string, content: JSONContent[]) => ReturnType;
        };
    }
}

/**
 * The quotation of a forum post. It keeps the post id only: who wrote it and the link to it
 * are added by the server when the post is shown, for readers who may see the original.
 */
export const PostQuote = Node.create({
    name: 'postQuote',
    group: 'block',
    content: 'block+',
    defining: true,

    addAttributes(): Attributes {
        return {
            postId: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('data-post-id'),
                renderHTML: (attributes: Record<string, unknown>) => ({
                    'data-post-id': attributes['postId'],
                }),
            },
        };
    },

    parseHTML(): TagParseRule[] {
        // Ahead of the plain blockquote, which would take it otherwise
        return [{ tag: 'blockquote[data-post-id]', priority: 60 }];
    },

    renderHTML({ HTMLAttributes }): DOMOutputSpec {
        return ['blockquote', mergeAttributes(HTMLAttributes, { class: 'forum-quote' }), 0];
    },

    addCommands(): Partial<RawCommands> {
        return {
            insertPostQuote:
                (postId, content) =>
                ({ chain, state }) =>
                    chain()
                        // In an empty editor the quote takes the place of its blank paragraph
                        .insertContentAt(
                            state.doc.childCount === 1 && state.doc.firstChild?.content.size === 0
                                ? { from: 0, to: state.doc.content.size }
                                : state.doc.content.size,
                            [
                                {
                                    type: this.name,
                                    attrs: { postId },
                                    content: content.length > 0 ? content : [{ type: 'paragraph' }],
                                },
                                { type: 'paragraph' },
                            ]
                        )
                        .focus('end')
                        .run(),
        };
    },
});

/** Plain text as the paragraphs of a quotation, one per line that has text */
export function paragraphsFromText(text: string): JSONContent[] {
    return text
        .split(/\r?\n/)
        .filter((line) => /\S/u.test(line))
        .map((line) => ({ type: 'paragraph', content: [{ type: 'text', text: line }] }));
}

/** Letters and digits only, in lower case: what the server compares when it checks a quotation */
function words(text: string): string {
    return text.replace(/[^\p{L}\p{N}]+/gu, '').toLowerCase();
}

/** Whether the server takes `quoted` as words of a post whose plain text is `postText` */
export function quoteFits(postText: string, quoted: string): boolean {
    const wanted = words(quoted);

    return wanted !== '' && words(postText).includes(wanted);
}
