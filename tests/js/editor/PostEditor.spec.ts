import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import type { JSONContent } from '@tiptap/core';
import { Dices } from '@lucide/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import PostEditor from '../../../resources/js/components/editor/PostEditor.vue';

const TEXT: JSONContent = {
    type: 'doc',
    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Hola a todos' }] }],
};
const IMAGE = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.png';

interface Exposed {
    getDocument: () => JSONContent | null;
    quote: (source: { postId: string; text?: string }) => void;
    clear: () => void;
}

function mountEditor(props: Record<string, unknown> = {}): VueWrapper {
    return mount(PostEditor, {
        props: { label: 'Tu respuesta', submitLabel: 'Responder', ...props },
        attachTo: document.body,
    });
}

async function settle(): Promise<void> {
    await flushPromises();
    await nextTick();
}

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('PostEditor', () => {
    it('offers a labelled toolbar and writing area', async () => {
        const wrapper = mountEditor();
        await settle();

        expect(wrapper.find('[role="toolbar"]').attributes('aria-label')).toBe(
            'Formato del mensaje'
        );
        expect(wrapper.find('[data-toolbar-item="bold"]').attributes('aria-label')).toBe('Negrita');
        expect(wrapper.find('[role="textbox"]').attributes('aria-label')).toBe('Tu respuesta');
        // No mentions or images unless the page gives their endpoints
        expect(wrapper.find('[data-toolbar-item="image"]').exists()).toBe(false);
    });

    it('offers no dice rolls nor asides in text only (private messages)', async () => {
        const wrapper = mountEditor({ textOnly: true });
        await settle();

        expect(wrapper.find('[data-toolbar-item="bold"]').exists()).toBe(true);
        expect(wrapper.find('[data-toolbar-item="roll"]').exists()).toBe(false);
        expect(wrapper.find('[data-toolbar-item="ooc"]').exists()).toBe(false);

        const full = mountEditor();
        await settle();
        expect(full.find('[data-toolbar-item="roll"]').exists()).toBe(true);
    });

    it('sends the document and refuses an empty one', async () => {
        const wrapper = mountEditor();
        await settle();

        await wrapper.find('button:not([data-toolbar-item])').trigger('click');
        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.find('[role="alert"]').text()).toBe('Escribe algo antes de enviar.');

        await wrapper.setProps({ modelValue: TEXT });
        const filled = mountEditor({ modelValue: TEXT });
        await settle();
        await filled.find('button:not([data-toolbar-item])').trigger('click');

        expect(filled.emitted('submit')?.[0]?.[0]).toEqual(TEXT);
    });

    it('applies bold from the toolbar', async () => {
        const wrapper = mountEditor({ modelValue: TEXT });
        await settle();
        const exposed = wrapper.vm as unknown as Exposed;

        await wrapper.find('[data-toolbar-item="bold"]').trigger('click');
        // With the caret at the start nothing is selected: typing would be bold
        expect(wrapper.find('[data-toolbar-item="bold"]').attributes('aria-pressed')).toBeDefined();
        expect(exposed.getDocument()).toEqual(TEXT);
    });

    it('quotes a post at the end with room to answer', async () => {
        const wrapper = mountEditor({ modelValue: TEXT });
        await settle();
        const exposed = wrapper.vm as unknown as Exposed;

        exposed.quote({
            postId: '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f',
            text: 'Primera línea\n\nSegunda',
        });

        expect(exposed.getDocument()?.content?.slice(1)).toEqual([
            {
                type: 'postQuote',
                attrs: { postId: '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f' },
                content: [
                    { type: 'paragraph', content: [{ type: 'text', text: 'Primera línea' }] },
                    { type: 'paragraph', content: [{ type: 'text', text: 'Segunda' }] },
                ],
            },
            { type: 'paragraph' },
        ]);
    });

    it('quotes into an empty editor without a blank line before the quote', async () => {
        const wrapper = mountEditor();
        await settle();
        const exposed = wrapper.vm as unknown as Exposed;

        exposed.quote({ postId: '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f', text: 'Lo que dijo' });

        expect(exposed.getDocument()?.content?.map((node) => node.type)).toEqual([
            'postQuote',
            'paragraph',
        ]);
    });

    it('keeps a draft while writing, restores it and forgets it once cleared', async () => {
        vi.useFakeTimers();
        const first = mountEditor({ draftKey: 'discussion-1' });
        await settle();

        (first.vm as unknown as Exposed).quote({
            postId: '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f',
            text: 'Lo que dijo',
        });
        vi.advanceTimersByTime(1000);
        first.unmount();

        const second = mountEditor({ draftKey: 'discussion-1' });
        await settle();
        expect(second.text()).toContain('Hemos recuperado el borrador');
        expect(JSON.stringify((second.vm as unknown as Exposed).getDocument())).toContain(
            'Lo que dijo'
        );

        (second.vm as unknown as Exposed).clear();
        second.unmount();
        expect(localStorage.getItem('guildforge:forum:draft:discussion-1')).toBeNull();
    });

    it('turns an address typed in the link form into a link', async () => {
        const wrapper = mountEditor({
            modelValue: { type: 'doc', content: [{ type: 'paragraph' }] },
        });
        await settle();

        await wrapper.find('[data-toolbar-item="link"]').trigger('click');
        await wrapper.find('#forum-editor-link').setValue('ejemplo.com');
        await wrapper.find('form').trigger('submit');

        expect((wrapper.vm as unknown as Exposed).getDocument()).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [
                        {
                            type: 'text',
                            text: 'https://ejemplo.com',
                            marks: [{ type: 'link', attrs: { href: 'https://ejemplo.com' } }],
                        },
                    ],
                },
            ],
        });

        await wrapper.find('[data-toolbar-item="link"]').trigger('click');
        await wrapper.find('#forum-editor-link').setValue('javascript:alert(1)');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.find('[role="alert"]').text()).toContain('Los enlaces deben ir');
    });

    it('uploads a chosen image and inserts its stored path', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ path: IMAGE, url: `https://cdn.example.com/${IMAGE}` }),
                    { status: 201 }
                )
            );
        vi.stubGlobal('fetch', fetch);
        const wrapper = mountEditor({ modelValue: TEXT, uploadUrl: '/foro/imagenes' });
        await settle();

        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', {
            value: [new File(['x'], 'mapa.png', { type: 'image/png' })],
        });
        await input.trigger('change');
        await settle();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/imagenes',
            expect.objectContaining({ method: 'POST' })
        );
        expect((wrapper.vm as unknown as Exposed).getDocument()?.content).toContainEqual({
            type: 'image',
            attrs: { origin: 'upload', src: IMAGE, path: null, alt: null },
        });
        vi.unstubAllGlobals();
    });

    it('asks the server for its copy of an image from another site and shows the copy', async () => {
        const remote = 'https://i.imgur.com/AbC123.png';
        const copy = 'forum/external/2026/11/7c1e2d3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f.png';
        const fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ url: remote, path: copy, previewUrl: null }), {
                status: 201,
            })
        );
        vi.stubGlobal('fetch', fetch);
        const wrapper = mountEditor({
            modelValue: TEXT,
            externalImageUrl: '/foro/imagenes/externas',
            externalImageHosts: ['imgur.com'],
        });
        await settle();

        await wrapper.find('[data-toolbar-item="externalImage"]').trigger('click');
        await wrapper.find('#forum-editor-external-image').setValue(`  ${remote} `);
        await (
            wrapper.find('#forum-editor-external-image').element as HTMLInputElement
        ).form?.requestSubmit();
        await settle();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/imagenes/externas',
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ url: remote }) })
        );
        expect((wrapper.vm as unknown as Exposed).getDocument()?.content).toContainEqual({
            type: 'image',
            attrs: { origin: 'external', src: remote, path: copy, alt: null },
        });
        expect(wrapper.find('.forum-editor__content').html()).not.toContain(` src="${remote}"`);
        vi.unstubAllGlobals();
    });

    it('says which sites images may come from instead of asking for another one', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const wrapper = mountEditor({
            modelValue: TEXT,
            externalImageUrl: '/foro/imagenes/externas',
            externalImageHosts: ['imgur.com'],
        });
        await settle();

        await wrapper.find('[data-toolbar-item="externalImage"]').trigger('click');
        await wrapper
            .find('#forum-editor-external-image')
            .setValue('https://tracker.example.com/p.png');
        await (
            wrapper.find('#forum-editor-external-image').element as HTMLInputElement
        ).form?.requestSubmit();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.find('[role="alert"]').text()).toContain('imgur.com');
        vi.unstubAllGlobals();
    });

    it('reads a link to a post of another service through the server and embeds it', async () => {
        const fetch = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    service: 'youtube',
                    id: 'dQw4w9WgXcQ',
                    label: 'YouTube',
                    url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    card: {
                        available: true,
                        title: 'Partida épica',
                        authorName: null,
                        thumbnailUrl: null,
                    },
                }),
                { status: 201 }
            )
        );
        vi.stubGlobal('fetch', fetch);
        const wrapper = mountEditor({
            modelValue: TEXT,
            embedUrl: '/foro/incrustaciones',
            embedServices: [
                { key: 'youtube', label: 'YouTube', hosts: ['youtube.com', 'youtu.be'] },
            ],
        });
        await settle();

        await wrapper.find('[data-toolbar-item="embed"]').trigger('click');
        await wrapper.find('#forum-editor-embed').setValue('https://youtu.be/dQw4w9WgXcQ');
        await (
            wrapper.find('#forum-editor-embed').element as HTMLInputElement
        ).form?.requestSubmit();
        await settle();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/incrustaciones',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ url: 'https://youtu.be/dQw4w9WgXcQ' }),
            })
        );
        expect((wrapper.vm as unknown as Exposed).getDocument()?.content).toContainEqual({
            type: 'embed',
            attrs: { service: 'youtube', id: 'dQw4w9WgXcQ' },
        });
        const draft = wrapper.find('.forum-embed-draft');
        expect(draft.text()).toContain('YouTube');
        expect(draft.text()).toContain('Partida épica');
        expect(wrapper.find('.forum-editor__content iframe').exists()).toBe(false);
        vi.unstubAllGlobals();
    });

    it('offers to embed a pasted link of a service the forum embeds', async () => {
        const wrapper = mountEditor({
            modelValue: TEXT,
            embedUrl: '/foro/incrustaciones',
            embedServices: [{ key: 'vimeo', label: 'Vimeo', hosts: ['vimeo.com'] }],
        });
        await settle();

        const content = wrapper.find('.forum-editor__content');
        const paste = (text: string): Event => {
            const event = new Event('paste', { bubbles: true, cancelable: true });
            Object.defineProperty(event, 'clipboardData', {
                value: {
                    files: [],
                    getData: (type: string) => (type === 'text/plain' ? text : ''),
                },
            });

            return event;
        };
        content.element.dispatchEvent(paste('https://vimeo.com/76979871'));
        await settle();
        expect(wrapper.text()).toContain('Este enlace es de Vimeo');

        content.element.dispatchEvent(paste('https://example.com/otra-cosa'));
        await settle();
        expect(wrapper.text()).not.toContain('Este enlace es de');
    });

    it('offers no embeds without services', async () => {
        const wrapper = mountEditor({ embedUrl: '/foro/incrustaciones', embedServices: [] });
        await settle();

        expect(wrapper.find('[data-toolbar-item="embed"]').exists()).toBe(false);
    });

    it('offers no images from other sites without the permission or without sites', async () => {
        const without = mountEditor({ externalImageUrl: null, externalImageHosts: ['imgur.com'] });
        const empty = mountEditor({
            externalImageUrl: '/foro/imagenes/externas',
            externalImageHosts: [],
        });
        await settle();

        expect(without.find('[data-toolbar-item="externalImage"]').exists()).toBe(false);
        expect(empty.find('[data-toolbar-item="externalImage"]').exists()).toBe(false);
    });

    it('shows the server message when an upload is refused', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(JSON.stringify({ message: 'La imagen pesa demasiado.' }), {
                    status: 422,
                })
            )
        );
        const wrapper = mountEditor({ modelValue: TEXT, uploadUrl: '/foro/imagenes' });
        await settle();

        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', {
            value: [new File(['x'], 'mapa.png', { type: 'image/png' })],
        });
        await input.trigger('change');
        await settle();

        expect(wrapper.find('[role="alert"]').text()).toBe('La imagen pesa demasiado.');
        vi.unstubAllGlobals();
    });

    it('adds the buttons other features bring', async () => {
        const run = vi.fn();
        const wrapper = mountEditor({
            toolbarItems: [{ id: 'aside', label: 'Aparte', icon: Dices, run }],
        });
        await settle();

        await wrapper.find('[data-toolbar-item="aside"]').trigger('click');

        expect(wrapper.find('[data-toolbar-item="aside"]').attributes('aria-label')).toBe('Aparte');
        expect(run).toHaveBeenCalledOnce();
    });
});
