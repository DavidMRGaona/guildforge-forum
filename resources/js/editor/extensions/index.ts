import type { AnyExtension } from '@tiptap/core';
import { Placeholder } from '@tiptap/extensions';
import { Markdown } from '@tiptap/markdown';
import StarterKit from '@tiptap/starter-kit';
import type { SuggestionOptions } from '@tiptap/suggestion';
import { HEADING_LEVELS } from '../document';
import { ForumEmbed, type EmbedDescription } from './ForumEmbed';
import { ForumImage } from './ForumImage';
import { ForumLink } from './ForumLink';
import { ForumCodeBlock, ForumOrderedList } from './ForumLists';
import { forumMention, type MentionNames } from './ForumMention';
import { ForumOoc } from './ForumOoc';
import { HeadingShortcut } from './HeadingShortcut';
import { ForumRoll } from './ForumRoll';
import { MarkdownPaste } from './MarkdownPaste';
import { PostQuote } from './PostQuote';

export interface ForumExtensionOptions {
    placeholder?: string;
    /** The address to show a stored image path while writing */
    imageUrl: (path: string) => string | null;
    /** Names of the accounts mentioned, as the editor knows them */
    mentionNames: MentionNames;
    /** How «@» finds people, or null where mentions are not offered */
    mentionSuggestion: Omit<SuggestionOptions, 'editor'> | null;
    /** The label shown above an aside out of character while writing */
    oocLabel?: string;
    /** How an embedded post shows while writing */
    describeEmbed?: (service: string, id: string) => EmbedDescription;
    /**
     * Nodes and marks other features add (dice rolls, out-of-character asides). Each must have
     * its node type registered with the server's document schema, or the post is refused.
     */
    extra?: AnyExtension[];
}

/**
 * The editor's extensions: the nodes and marks of the server's document schema, configured
 * so their JSON holds only the attributes the server accepts, plus the writing aids
 * (Markdown shortcuts and paste, placeholder, undo).
 */
export function createForumExtensions(options: ForumExtensionOptions): AnyExtension[] {
    return [
        StarterKit.configure({
            heading: { levels: [...HEADING_LEVELS] },
            // Replaced by the forum's versions, which keep only the attributes the server takes
            link: false,
            orderedList: false,
            codeBlock: false,
            // Not in the forum's schema
            underline: false,
        }),
        ForumOrderedList,
        ForumCodeBlock,
        ForumLink,
        ForumImage.configure({ urlFor: options.imageUrl }),
        PostQuote,
        ForumRoll,
        ForumOoc.configure({ label: options.oocLabel ?? '' }),
        ForumEmbed.configure({
            describe:
                options.describeEmbed ??
                ((service: string): EmbedDescription => ({ label: service, title: null })),
        }),
        forumMention(options.mentionNames, options.mentionSuggestion),
        Placeholder.configure({ placeholder: options.placeholder ?? '' }),
        HeadingShortcut,
        Markdown,
        MarkdownPaste,
        ...(options.extra ?? []),
    ];
}
