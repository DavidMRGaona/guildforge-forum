import Link from '@tiptap/extension-link';
import { mergeAttributes } from '@tiptap/core';
import { isAllowedHref } from '../document';
import type { Attributes } from '@tiptap/core';
import type { DOMOutputSpec } from '@tiptap/pm/model';

/**
 * Links keep only `href` (the server refuses any other attribute) and only to http, https or
 * mailto. Addresses typed or pasted without a scheme become https.
 */
export const ForumLink = Link.extend({
    addAttributes(): Attributes {
        return {
            href: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('href'),
            },
        };
    },

    renderHTML({ HTMLAttributes }): DOMOutputSpec {
        return [
            'a',
            mergeAttributes(HTMLAttributes, {
                target: '_blank',
                rel: 'nofollow ugc noopener noreferrer',
            }),
            0,
        ];
    },
}).configure({
    openOnClick: false,
    autolink: true,
    linkOnPaste: true,
    defaultProtocol: 'https',
    isAllowedUri: (url: string) => isAllowedHref(url),
    shouldAutoLink: (url: string) => isAllowedHref(url),
});
