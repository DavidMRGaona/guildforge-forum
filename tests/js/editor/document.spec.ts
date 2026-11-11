import { describe, expect, it } from 'vitest';
import {
    isAllowedHref,
    isDocumentEmpty,
    normalizeCodeLanguage,
    normalizeHref,
    sanitizeContent,
} from '../../../resources/js/editor/document';
import { looksLikeMarkdown } from '../../../resources/js/editor/extensions/MarkdownPaste';

describe('links', () => {
    it.each([
        'https://example.com',
        'http://example.com:8080/a/b?c=d#e',
        'mailto:someone@example.com',
        'HTTPS://EXAMPLE.COM/MAYÚS',
    ])('accepts %s, as the server does', (href) => {
        expect(isAllowedHref(href)).toBe(true);
    });

    it.each([
        'javascript:alert(1)',
        ' JavaScript:alert(1)',
        'data:text/html;base64,PHNjcmlwdD4=',
        '/foro',
        '//example.com',
        'https://',
        'https://exa mple.com',
        'https://user:pass@example.com',
        'mailto:',
        `https://example.com/${'a'.repeat(2048)}`,
    ])('refuses %s, as the server does', (href) => {
        expect(isAllowedHref(href)).toBe(false);
    });

    it('adds https to an address typed without a scheme and gives up on the rest', () => {
        expect(normalizeHref(' ejemplo.com/ruta ')).toBe('https://ejemplo.com/ruta');
        expect(normalizeHref('https://ejemplo.com')).toBe('https://ejemplo.com');
        expect(normalizeHref('javascript:alert(1)')).toBeNull();
        expect(normalizeHref('no es un enlace')).toBeNull();
    });
});

describe('documents', () => {
    it('is empty with nothing but spaces, and not with an image or a mention', () => {
        expect(
            isDocumentEmpty({
                type: 'doc',
                content: [{ type: 'paragraph', content: [{ type: 'text', text: '  ' }] }],
            })
        ).toBe(true);
        expect(isDocumentEmpty({ type: 'doc', content: [{ type: 'horizontalRule' }] })).toBe(true);
        expect(
            isDocumentEmpty({ type: 'doc', content: [{ type: 'image', attrs: { src: 'x' } }] })
        ).toBe(false);
        expect(
            isDocumentEmpty({
                type: 'doc',
                content: [
                    { type: 'paragraph', content: [{ type: 'mention', attrs: { id: 'x' } }] },
                ],
            })
        ).toBe(false);
        expect(isDocumentEmpty(null)).toBe(true);
    });

    it('fits converted content to the schema without losing the text', () => {
        expect(
            sanitizeContent({
                type: 'doc',
                content: [
                    {
                        type: 'heading',
                        attrs: { level: 1 },
                        content: [{ type: 'text', text: 'Uno' }],
                    },
                    {
                        type: 'heading',
                        attrs: { level: 6 },
                        content: [{ type: 'text', text: 'Seis' }],
                    },
                    { type: 'image', attrs: { src: 'https://tracker.example.com/p.gif' } },
                    {
                        type: 'image',
                        attrs: {
                            src: 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.png',
                            title: 'x',
                        },
                    },
                    {
                        type: 'codeBlock',
                        attrs: { language: 'Python' },
                        content: [{ type: 'text', text: 'x' }],
                    },
                    {
                        type: 'paragraph',
                        content: [
                            {
                                type: 'text',
                                text: 'clic',
                                marks: [
                                    { type: 'link', attrs: { href: 'javascript:x' } },
                                    { type: 'bold' },
                                ],
                            },
                        ],
                    },
                ],
            })
        ).toEqual({
            type: 'doc',
            content: [
                { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Uno' }] },
                { type: 'heading', attrs: { level: 4 }, content: [{ type: 'text', text: 'Seis' }] },
                {
                    type: 'image',
                    attrs: {
                        origin: 'upload',
                        src: 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.png',
                        alt: null,
                    },
                },
                {
                    type: 'codeBlock',
                    attrs: { language: 'python' },
                    content: [{ type: 'text', text: 'x' }],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'clic', marks: [{ type: 'bold' }] }],
                },
            ],
        });
    });

    it('keeps code languages the server accepts', () => {
        expect(normalizeCodeLanguage('TypeScript')).toBe('typescript');
        expect(normalizeCodeLanguage('c#')).toBe('c#');
        expect(normalizeCodeLanguage('php"><script>')).toBeNull();
        expect(normalizeCodeLanguage(null)).toBeNull();
    });
});

describe('markdown detection', () => {
    it.each([
        '# Título',
        '- uno\n- dos',
        '1. uno',
        '> cita',
        '```\ncódigo\n```',
        'texto **fuerte**',
        '[enlace](https://example.com)',
    ])('recognises %s', (text) => {
        expect(looksLikeMarkdown(text)).toBe(true);
    });

    it('leaves ordinary text alone', () => {
        expect(looksLikeMarkdown('Hola, ¿quedamos el viernes a las 18:00? Llevo dados.')).toBe(
            false
        );
    });
});
