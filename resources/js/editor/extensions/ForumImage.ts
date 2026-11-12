import Image from '@tiptap/extension-image';
import {
    EXTERNAL_IMAGE_PATTERN,
    FORUM_IMAGE_PATTERN,
    IMAGE_ORIGIN_EXTERNAL,
    IMAGE_ORIGIN_UPLOAD,
    remoteImageHost,
} from '../document';
import type { Attributes } from '@tiptap/core';
import type { ImageOptions } from '@tiptap/extension-image';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';

export interface ForumImageOptions extends ImageOptions {
    /** The address to show a stored path while writing */
    urlFor: (path: string) => string | null;
}

/**
 * Images of the forum. The node keeps where it comes from (`origin`), a description (`alt`)
 * and, for an upload, its stored path (`src`); for an image from another site, its https
 * address (`src`) and the forum's copy (`path`), which is what the editor shows. Images of
 * other sites pasted as they are (an <img> with their address) are not taken in.
 */
export const ForumImage = Image.extend<ForumImageOptions>({
    addOptions(): ForumImageOptions {
        return {
            HTMLAttributes: {},
            resize: false,
            ...this.parent?.(),
            inline: false,
            allowBase64: false,
            urlFor: () => null,
        };
    },

    addAttributes(): Attributes {
        return {
            // Where the image comes from: uploaded to the forum, or copied by it from another site
            origin: {
                default: IMAGE_ORIGIN_UPLOAD,
                parseHTML: (element: HTMLElement): string | null =>
                    element.getAttribute('data-origin'),
                renderHTML: (attributes: Record<string, unknown>): Record<string, unknown> => ({
                    'data-origin': attributes['origin'],
                }),
            },
            src: { default: null, renderHTML: () => ({}) },
            // The forum's copy of an image from another site; null for uploads
            path: { default: null, renderHTML: () => ({}) },
            alt: { default: null },
        };
    },

    parseHTML(): TagParseRule[] {
        return [
            {
                tag: 'img[data-path]',
                getAttrs: (element: HTMLElement): Record<string, unknown> | false => {
                    const path = element.getAttribute('data-path') ?? '';
                    const alt = element.getAttribute('alt');

                    if (element.getAttribute('data-origin') === IMAGE_ORIGIN_EXTERNAL) {
                        const src = element.getAttribute('data-src') ?? '';

                        return EXTERNAL_IMAGE_PATTERN.test(path) && remoteImageHost(src) !== null
                            ? { origin: IMAGE_ORIGIN_EXTERNAL, src, path, alt }
                            : false;
                    }

                    return FORUM_IMAGE_PATTERN.test(path)
                        ? { origin: IMAGE_ORIGIN_UPLOAD, src: path, path: null, alt }
                        : false;
                },
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }): DOMOutputSpec {
        const external = node.attrs['origin'] === IMAGE_ORIGIN_EXTERNAL;
        // What is shown is always a file of the forum: the copy, never the other site
        const path = String((external ? node.attrs['path'] : node.attrs['src']) ?? '');

        return [
            'img',
            {
                ...HTMLAttributes,
                src: this.options.urlFor(path) ?? '',
                'data-path': path,
                ...(external ? { 'data-src': String(node.attrs['src'] ?? '') } : {}),
            },
        ];
    },
});
