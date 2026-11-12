import { mergeAttributes, Node } from '@tiptap/core';
import type { RawCommands } from '@tiptap/core';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        ooc: {
            /** Wraps the selected blocks in an aside out of character, or unwraps them */
            toggleOoc: () => ReturnType;
        };
    }
}

/**
 * An aside out of character (`ooc` in the server's schema): what whoever plays the character
 * says as themselves. It holds blocks and has no attributes; the server draws it apart from
 * the story, with its label in words, and rolls inside it name nobody.
 */
export interface ForumOocOptions {
    /** Shown above the aside while writing («Fuera de personaje»); not part of the document */
    label: string;
}

export const ForumOoc = Node.create<ForumOocOptions>({
    name: 'ooc',
    group: 'block',
    content: 'block+',
    defining: true,

    addOptions(): ForumOocOptions {
        return { label: '' };
    },

    parseHTML(): TagParseRule[] {
        return [{ tag: 'aside[data-ooc]' }, { tag: 'aside.forum-ooc' }];
    },

    renderHTML({ HTMLAttributes }): DOMOutputSpec {
        return [
            'aside',
            mergeAttributes(HTMLAttributes, {
                class: 'forum-ooc',
                'data-ooc': '',
                'data-label': this.options.label,
            }),
            0,
        ];
    },

    addCommands(): Partial<RawCommands> {
        return {
            toggleOoc:
                () =>
                ({ commands }) =>
                    commands.toggleWrap(this.name),
        };
    },
});
