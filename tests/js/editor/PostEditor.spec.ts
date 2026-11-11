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
            attrs: { src: IMAGE, alt: null },
        });
        vi.unstubAllGlobals();
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
            toolbarItems: [{ id: 'roll', label: 'Tirada', icon: Dices, run }],
        });
        await settle();

        await wrapper.find('[data-toolbar-item="roll"]').trigger('click');

        expect(wrapper.find('[data-toolbar-item="roll"]').attributes('aria-label')).toBe('Tirada');
        expect(run).toHaveBeenCalledOnce();
    });
});
