import { CodeBlock } from '@tiptap/extension-code-block';
import { OrderedList } from '@tiptap/extension-list';
import { normalizeCodeLanguage } from '../document';
import type { Attributes } from '@tiptap/core';

const MAX_START = 100_000;

/** Ordered lists keep only where they start (the server refuses the list style) */
export const ForumOrderedList = OrderedList.extend({
    addAttributes(): Attributes {
        return {
            start: {
                default: 1,
                parseHTML: (element: HTMLElement): number => {
                    const start = Number.parseInt(element.getAttribute('start') ?? '1', 10);

                    return Number.isNaN(start) ? 1 : Math.min(Math.max(start, 1), MAX_START);
                },
            },
        };
    },
});

/** Code blocks keep a language label only when it is one the server accepts */
export const ForumCodeBlock = CodeBlock.extend({
    addAttributes(): Attributes {
        return {
            language: {
                default: null,
                parseHTML: (element: HTMLElement): string | null => {
                    const className =
                        element.firstElementChild?.getAttribute('class') ??
                        element.getAttribute('class') ??
                        '';
                    const language = /(?:^|\s)language-(\S+)/.exec(className)?.[1] ?? null;

                    return normalizeCodeLanguage(language);
                },
                rendered: false,
            },
        };
    },
});
