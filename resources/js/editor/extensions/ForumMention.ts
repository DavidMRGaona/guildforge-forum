import Mention from '@tiptap/extension-mention';
import type { SuggestionOptions } from '@tiptap/suggestion';
import type { AnyExtension, Attributes } from '@tiptap/core';
import type { TagParseRule } from '@tiptap/pm/model';

export interface MentionNames {
    nameOf: (accountId: string) => string | null;
}

/**
 * Mentions keep only the account id: the name shown is the account's current one, from the
 * server when the post is read and from the names the editor knows while writing.
 */
export function forumMention(
    names: MentionNames,
    suggestion: Omit<SuggestionOptions, 'editor'> | null
): AnyExtension {
    const label = (id: unknown): string =>
        `@${(typeof id === 'string' ? names.nameOf(id) : null) ?? '…'}`;

    return Mention.extend({
        addAttributes(): Attributes {
            return {
                id: {
                    default: null,
                    parseHTML: (element: HTMLElement) => element.getAttribute('data-account-id'),
                    renderHTML: (attributes: Record<string, unknown>) => ({
                        'data-account-id': attributes['id'],
                    }),
                },
            };
        },

        parseHTML(): TagParseRule[] {
            return [{ tag: 'span[data-account-id]' }];
        },
    }).configure({
        HTMLAttributes: { class: 'forum-mention' },
        renderText: ({ node }) => label(node.attrs['id']),
        renderHTML: ({ node, options }) => [
            'span',
            { ...options.HTMLAttributes, 'data-account-id': node.attrs['id'] },
            label(node.attrs['id']),
        ],
        deleteTriggerWithBackspace: true,
        ...(suggestion === null ? { suggestions: [] } : { suggestion }),
    });
}
