import { mergeAttributes, Node } from '@tiptap/core';
import type { Attributes, Editor, KeyboardShortcutCommand, RawCommands } from '@tiptap/core';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import RollNodeView from '../RollNodeView.vue';
import { rollFromShortcut, type RollShortcut } from '../rollText';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        forumRoll: {
            /** Adds a roll to fill in, below the block the cursor is in */
            insertRoll: (roll?: Partial<RollShortcut>) => ReturnType;
        };
    }
}

/**
 * A dice roll. The node holds the expression and the reason the person wrote and, once the
 * server has thrown it, the id of the throw: never a result (the server throws the dice when
 * the post is published and keeps the throw apart). Copying a roll never copies its id, so a
 * copy is a new roll; a copy of a hidden roll is hidden too.
 *
 * `hidden` asks for a hidden roll. Only a new roll's counts: the server keeps a thrown roll as it
 * was thrown, so the editor locks it once the roll has an id.
 */
export const ForumRoll = Node.create({
    name: 'roll',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes(): Attributes {
        return {
            expression: {
                default: '',
                parseHTML: (element: HTMLElement) => element.getAttribute('data-expression') ?? '',
                renderHTML: (attributes: Record<string, unknown>) => ({
                    'data-expression': attributes['expression'],
                }),
            },
            reason: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('data-reason'),
                renderHTML: (attributes: Record<string, unknown>) =>
                    typeof attributes['reason'] === 'string' && attributes['reason'] !== ''
                        ? { 'data-reason': attributes['reason'] }
                        : {},
            },
            rollId: {
                default: null,
                parseHTML: () => null,
                renderHTML: () => ({}),
            },
            hidden: {
                default: false,
                parseHTML: (element: HTMLElement) => element.hasAttribute('data-hidden'),
                renderHTML: (attributes: Record<string, unknown>) =>
                    attributes['hidden'] === true ? { 'data-hidden': '' } : {},
            },
        };
    },

    parseHTML(): TagParseRule[] {
        return [{ tag: 'div[data-forum-roll]' }];
    },

    renderHTML({ HTMLAttributes }): DOMOutputSpec {
        return [
            'div',
            mergeAttributes(HTMLAttributes, { 'data-forum-roll': '', class: 'forum-roll' }),
        ];
    },

    renderText({ node }): string {
        const expression = String(node.attrs['expression'] ?? '');
        const reason = typeof node.attrs['reason'] === 'string' ? node.attrs['reason'] : '';

        return reason === '' ? expression : `${expression} ${reason}`;
    },

    addNodeView() {
        return VueNodeViewRenderer(RollNodeView);
    },

    addCommands(): Partial<RawCommands> {
        return {
            insertRoll:
                (roll) =>
                ({ chain }) =>
                    chain()
                        .insertContent([
                            {
                                type: this.name,
                                attrs: {
                                    expression: roll?.expression ?? '',
                                    reason:
                                        roll?.reason === undefined || roll.reason === ''
                                            ? null
                                            : roll.reason,
                                    rollId: null,
                                    hidden: roll?.hidden === true,
                                },
                            },
                            { type: 'paragraph' },
                        ])
                        .run(),
        };
    },

    addKeyboardShortcuts(): Record<string, KeyboardShortcutCommand> {
        return {
            // «/tirada 2d6+3 Ataque» (or «/tirada oculta …») then Enter becomes a roll
            Enter: ({ editor }) => turnShortcutIntoRoll(editor, this.name),
        };
    },
});

/** Replaces the paragraph holding a `/tirada` line by the roll it asks for; false when it is not one */
function turnShortcutIntoRoll(editor: Editor, nodeName: string): boolean {
    const { $from, empty } = editor.state.selection;
    const paragraph = $from.parent;

    if (!empty || paragraph.type.name !== 'paragraph') {
        return false;
    }

    const roll = rollFromShortcut(paragraph.textContent);
    if (roll === null) {
        return false;
    }

    const isLast = $from.index($from.depth - 1) === $from.node($from.depth - 1).childCount - 1;

    return editor
        .chain()
        .insertContentAt({ from: $from.before(), to: $from.after() }, [
            {
                type: nodeName,
                attrs: {
                    expression: roll.expression,
                    reason: roll.reason === '' ? null : roll.reason,
                    rollId: null,
                    hidden: roll.hidden,
                },
            },
            ...(isLast ? [{ type: 'paragraph' }] : []),
        ])
        .focus()
        .run();
}
