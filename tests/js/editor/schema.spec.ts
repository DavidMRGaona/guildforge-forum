import { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import { afterEach, describe, expect, it } from 'vitest';
import { createForumExtensions } from '../../../resources/js/editor/extensions';
import fixture from '../../fixtures/editor-document.json';

const IMAGE = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';
const ACCOUNT = '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6';
const POST = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

let editor: Editor | null = null;

function makeEditor(content: string | JSONContent): Editor {
    editor = new Editor({
        content,
        extensions: createForumExtensions({
            imageUrl: (path) => `https://cdn.example.com/${path}`,
            mentionNames: { nameOf: (id) => (id === ACCOUNT ? 'Ana' : null) },
            mentionSuggestion: null,
        }),
    });

    return editor;
}

afterEach(() => {
    editor?.destroy();
    editor = null;
});

describe('the editor document', () => {
    // tests/fixtures/editor-document.json is also checked by the server's schema
    // (EditorFixtureTest), so both sides agree on every node and attribute
    it('holds exactly the nodes and attributes the server accepts', () => {
        const json = makeEditor(
            '<h2>Crónica</h2>' +
                '<p><strong>Negrita</strong> <em>cursiva</em> <s>tachado</s> <code>código</code> ' +
                '<a href="https://example.com/a">enlace</a><br>' +
                `<span data-account-id="${ACCOUNT}">@Ana</span></p>` +
                '<blockquote><p>Cita libre</p></blockquote>' +
                `<blockquote data-post-id="${POST}"><p>Cita de un mensaje</p></blockquote>` +
                '<ul><li><p>Uno</p></li></ul>' +
                '<ol start="3"><li><p>Tres</p></li></ol>' +
                '<pre><code class="language-php">echo 1;</code></pre>' +
                '<hr>' +
                `<img data-path="${IMAGE}" alt="Mapa">` +
                '<p>Fin</p>'
        ).getJSON();

        expect(json).toEqual(fixture);
    });

    it('leaves out what the server would refuse when parsing pasted HTML', () => {
        const json = makeEditor(
            '<h1>Título</h1>' +
                '<p><a href="javascript:alert(1)">malo</a> <u>subrayado</u> <a href="https://ok.example.com" target="_self" class="x">bueno</a></p>' +
                '<img src="https://tracker.example.com/pixel.gif">' +
                '<ol type="a"><li><p>a</p></li></ol>' +
                '<pre><code class="language-C++ highlight">x</code></pre>'
        ).getJSON();

        expect(json).toEqual({
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'Título' }] },
                {
                    type: 'paragraph',
                    content: [
                        { type: 'text', text: 'malo subrayado ' },
                        {
                            type: 'text',
                            text: 'bueno',
                            marks: [{ type: 'link', attrs: { href: 'https://ok.example.com' } }],
                        },
                    ],
                },
                {
                    type: 'orderedList',
                    attrs: { start: 1 },
                    content: [
                        {
                            type: 'listItem',
                            content: [
                                { type: 'paragraph', content: [{ type: 'text', text: 'a' }] },
                            ],
                        },
                    ],
                },
                {
                    type: 'codeBlock',
                    attrs: { language: 'c++' },
                    content: [{ type: 'text', text: 'x' }],
                },
            ],
        });
    });

    it('renders mentions with the name the editor knows and images from their stored path', () => {
        const html = makeEditor({
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'mention', attrs: { id: ACCOUNT } }] },
                { type: 'image', attrs: { origin: 'upload', src: IMAGE, alt: null } },
            ],
        }).getHTML();

        expect(html).toContain('@Ana');
        expect(html).toContain(`data-path="${IMAGE}"`);
        expect(html).toContain(`src="https://cdn.example.com/${IMAGE}"`);
    });
});
