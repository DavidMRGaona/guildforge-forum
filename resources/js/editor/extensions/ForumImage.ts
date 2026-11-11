import Image from '@tiptap/extension-image';
import { FORUM_IMAGE_PATTERN } from '../document';
import type { Attributes } from '@tiptap/core';
import type { ImageOptions } from '@tiptap/extension-image';
import type { DOMOutputSpec, TagParseRule } from '@tiptap/pm/model';

export interface ForumImageOptions extends ImageOptions {
    /** The address to show a stored path while writing */
    urlFor: (path: string) => string | null;
}

/**
 * Images uploaded to the forum. The node keeps the stored path (`src`) and a description
 * (`alt`), nothing else; pasted images from other sites are not taken in.
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
            src: { default: null, renderHTML: () => ({}) },
            alt: { default: null },
        };
    },

    parseHTML(): TagParseRule[] {
        return [
            {
                tag: 'img[data-path]',
                getAttrs: (element: HTMLElement): Record<string, unknown> | false => {
                    const path = element.getAttribute('data-path') ?? '';

                    return FORUM_IMAGE_PATTERN.test(path)
                        ? { src: path, alt: element.getAttribute('alt') }
                        : false;
                },
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }): DOMOutputSpec {
        const path = String(node.attrs['src'] ?? '');

        return [
            'img',
            { ...HTMLAttributes, src: this.options.urlFor(path) ?? '', 'data-path': path },
        ];
    },
});
