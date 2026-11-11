import { Editor } from '@tiptap/core';
import { afterEach, describe, expect, it } from 'vitest';
import { createForumExtensions } from '../../../resources/js/editor/extensions';
import { shiftHeadings } from '../../../resources/js/editor/extensions/MarkdownPaste';

let editor: Editor | null = null;

function paste(target: Editor, data: Record<string, string>): void {
    const event = new Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'clipboardData', {
        value: { getData: (type: string) => data[type] ?? '', files: [], types: Object.keys(data) },
    });
    target.view.dom.dispatchEvent(event);
}

afterEach(() => {
    editor?.destroy();
    editor = null;
});

describe('pasting markdown', () => {
    it('turns pasted markdown into formatted content that fits the schema', () => {
        editor = new Editor({
            extensions: createForumExtensions({
                imageUrl: () => null,
                mentionNames: { nameOf: () => null },
                mentionSuggestion: null,
            }),
        });

        paste(editor, {
            'text/plain':
                '# Sesión 3\n\nLos **orcos** atacan.\n\n- Uno\n- Dos\n\n![píxel](https://tracker.example.com/p.gif)\n\n[malo](javascript:alert(1))',
        });

        const json = editor.getJSON();
        expect(json.content?.[0]).toEqual({
            type: 'heading',
            attrs: { level: 2 },
            content: [{ type: 'text', text: 'Sesión 3' }],
        });
        expect(json.content?.[1]).toEqual({
            type: 'paragraph',
            content: [
                { type: 'text', text: 'Los ' },
                { type: 'text', text: 'orcos', marks: [{ type: 'bold' }] },
                { type: 'text', text: ' atacan.' },
            ],
        });
        expect(json.content?.[2]?.type).toBe('bulletList');
        const serialized = JSON.stringify(json);
        expect(serialized).not.toContain('tracker.example.com');
        expect(serialized).not.toContain('javascript');
    });

    it('moves headings one level down and leaves code alone', () => {
        expect(
            shiftHeadings('# Uno\n## Dos\n##### Cinco\n```\n# comentario\n```\n#sin espacio')
        ).toBe('## Uno\n### Dos\n#### Cinco\n```\n# comentario\n```\n#sin espacio');
    });

    it('pastes ordinary text as it is', () => {
        editor = new Editor({
            extensions: createForumExtensions({
                imageUrl: () => null,
                mentionNames: { nameOf: () => null },
                mentionSuggestion: null,
            }),
        });

        paste(editor, { 'text/plain': 'Hola, ¿quedamos el viernes?' });

        expect(editor.getText()).toBe('Hola, ¿quedamos el viernes?');
    });
});
