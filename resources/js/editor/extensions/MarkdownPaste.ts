import { Extension } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { sanitizeContent } from '../document';

/** Signs that pasted plain text was written in Markdown */
const MARKDOWN_HINTS = [
    /^#{1,6}\s/m,
    /^\s*[-*+]\s+\S/m,
    /^\s*\d+[.)]\s+\S/m,
    /^>\s?/m,
    /^```/m,
    /\*\*[^*\n]+\*\*/,
    /(^|\s)_[^_\n]+_(\s|$)/,
    /~~[^~\n]+~~/,
    /`[^`\n]+`/,
    /\[[^\]\n]+\]\([^)\s]+\)/,
];

export function looksLikeMarkdown(text: string): boolean {
    return MARKDOWN_HINTS.some((hint) => hint.test(text));
}

/**
 * Markdown headings one level down, so «#» gives the first level a post may use (2) and the
 * deepest ones stay at 4. Lines inside fenced code are left alone.
 */
export function shiftHeadings(markdown: string): string {
    let inFence = false;

    return markdown
        .split('\n')
        .map((line) => {
            if (/^\s*(```|~~~)/.test(line)) {
                inFence = !inFence;

                return line;
            }

            return inFence
                ? line
                : line.replace(
                      /^(#{1,6})(\s)/,
                      (_match, hashes: string, space: string) =>
                          '#'.repeat(Math.min(hashes.length + 1, 4)) + space
                  );
        })
        .join('\n');
}

/**
 * Pasting Markdown as plain text (from a notes app, an old forum) gives formatted content.
 * What comes as HTML is left to the editor's own parser, and the result is made to fit the
 * schema either way. Needs the Markdown extension, which does the parsing.
 */
export const MarkdownPaste = Extension.create({
    name: 'forumMarkdownPaste',

    addProseMirrorPlugins(): Plugin[] {
        const editor = this.editor;

        return [
            new Plugin({
                key: new PluginKey('forumMarkdownPaste'),
                props: {
                    handlePaste: (_view, event): boolean => {
                        const data = event.clipboardData;
                        const text = data?.getData('text/plain') ?? '';
                        if (
                            data === null ||
                            data.getData('text/html') !== '' ||
                            !looksLikeMarkdown(text)
                        ) {
                            return false;
                        }
                        if (editor.markdown === undefined || editor.isActive('codeBlock')) {
                            return false;
                        }

                        const parsed: JSONContent = editor.markdown.parse(shiftHeadings(text));
                        const content = sanitizeContent(parsed)?.content ?? [];
                        if (content.length === 0) {
                            return false;
                        }

                        return editor.commands.insertContent(content);
                    },
                },
            }),
        ];
    },
});
