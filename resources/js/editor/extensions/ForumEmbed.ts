import { Node } from '@tiptap/core';
import type { Attributes, RawCommands } from '@tiptap/core';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';
import { EMBED_ID_PATTERN, EMBED_SERVICE_PATTERN } from '../document';

export interface EmbedDescription {
    /** The service's name */
    label: string;
    /** The post's title or text, when the server's card has one */
    title: string | null;
}

export interface ForumEmbedOptions {
    /** How to show a post while writing (from what the server answered, or just the service) */
    describe: (service: string, id: string) => EmbedDescription;
}

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        forumEmbed: {
            /** Inserts a post of another service, as the server read it from a link */
            insertEmbed: (service: string, id: string) => ReturnType;
        };
    }
}

/**
 * A post of another service (EmbedNode on the server): only its service and id, never HTML
 * or an address. While writing it shows as a box with the service and the post's title; the
 * forum's card and the player come once it is published.
 */
export const ForumEmbed = Node.create<ForumEmbedOptions>({
    name: 'embed',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addOptions(): ForumEmbedOptions {
        return { describe: (service) => ({ label: service, title: null }) };
    },

    addAttributes(): Attributes {
        return {
            service: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('data-service'),
                renderHTML: (attributes: Record<string, unknown>) => ({
                    'data-service': attributes['service'],
                }),
            },
            id: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('data-id'),
                renderHTML: (attributes: Record<string, unknown>) => ({
                    'data-id': attributes['id'],
                }),
            },
        };
    },

    parseHTML(): TagParseRule[] {
        return [
            {
                tag: 'div[data-forum-embed]',
                getAttrs: (element: HTMLElement): Record<string, unknown> | false => {
                    const service = element.getAttribute('data-service') ?? '';
                    const id = element.getAttribute('data-id') ?? '';

                    return EMBED_SERVICE_PATTERN.test(service) && EMBED_ID_PATTERN.test(id)
                        ? { service, id }
                        : false;
                },
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }): DOMOutputSpec {
        const description = this.options.describe(
            String(node.attrs['service'] ?? ''),
            String(node.attrs['id'] ?? '')
        );

        return [
            'div',
            { ...HTMLAttributes, 'data-forum-embed': '', class: 'forum-embed-draft' },
            ['p', { class: 'forum-embed-draft__service' }, description.label],
            [
                'p',
                { class: 'forum-embed-draft__title' },
                description.title ?? String(node.attrs['id'] ?? ''),
            ],
        ];
    },

    addCommands(): Partial<RawCommands> {
        return {
            insertEmbed:
                (service, id) =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name, attrs: { service, id } }),
        };
    },
});
