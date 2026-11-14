import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost } from '../../../resources/js/types/forum';

// As the server renders a post with an embed: its label and button are drawn, not stored
const HTML =
    '<p>Al abordaje, marineros</p>' +
    '<div class="forum-embed"><p>YouTube</p><button>Cargar contenido de YouTube</button></div>';
const STORED = 'Al abordaje, marineros\nhttps://www.youtube.com/watch?v=x';

function aPost(overrides: Partial<ForumPost> = {}): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: false,
        status: 'visible',
        html: HTML,
        placeholder: null,
        quoteText: STORED,
        publishedAt: '2026-11-12T10:00:00+01:00',
        publishedLabel: '12/11/2026 10:00',
        editedAt: null,
        editedLabel: null,
        url: '/foro/t/7-partida/2',
        canEdit: false,
        canDelete: false,
        document: null,
        updateUrl: null,
        deleteUrl: null,
        hiddenReason: null,
        moderationUrl: null,
        reportUrl: null,
        likes: { count: 0, liked: false, names: [], others: 0, canLike: false, url: null },
        character: null,
        characterId: null,
        ...overrides,
    };
}

function mounted(post: ForumPost, canQuote = true): VueWrapper {
    return mount(PostItem, {
        attachTo: document.body,
        props: {
            post,
            focused: false,
            canQuote,
            editor: {
                uploadUrl: null,
                maxImages: 10,
                imageTypes: ['image/png'],
                externalImageUrl: null,
                externalImageHosts: [],
                embedUrl: null,
                embedServices: [],
            },
            mentionsUrl: null,
            mentionNames: {},
        },
    });
}

/** Selects some text of the post's body and lets the selection settle */
function select(wrapper: VueWrapper, text: string): void {
    const node = [...wrapper.element.querySelectorAll('.forum-post p, .forum-post button')]
        .flatMap((element) => [...element.childNodes])
        .find((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.includes(text));
    if (!node) {
        throw new Error(`No text «${text}» in the post`);
    }
    const start = node.textContent!.indexOf(text);
    const selection = window.getSelection()!;
    selection.removeAllRanges();
    selection.setBaseAndExtent(node, start, node, start + text.length);
    document.dispatchEvent(new Event('selectionchange'));
    vi.advanceTimersByTime(150);
}

function floatingButton(): HTMLButtonElement | null {
    return document.body.querySelector<HTMLButtonElement>('[data-testid="selection-quote"]');
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    window.getSelection()?.removeAllRanges();
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('quoting a selection with the floating button', () => {
    it('offers «Citar» over a selection that fits and quotes only it', async () => {
        const wrapper = mounted(aPost());
        select(wrapper, 'abordaje');
        await wrapper.vm.$nextTick();

        const button = floatingButton();
        expect(button).not.toBeNull();
        expect(button!.getAttribute('aria-label')).toBe('Citar lo seleccionado');
        button!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('quote')?.at(-1)).toEqual([{ postId: 'post-1', text: 'abordaje' }]);
        expect(floatingButton()).toBeNull();
    });

    it('offers nothing for a selection that takes in a drawn label', async () => {
        const wrapper = mounted(aPost());
        select(wrapper, 'Cargar contenido');
        await wrapper.vm.$nextTick();

        expect(floatingButton()).toBeNull();
    });

    it('offers nothing on a post that cannot be quoted', async () => {
        const forbidden = mounted(aPost(), false);
        select(forbidden, 'abordaje');
        await forbidden.vm.$nextTick();
        expect(floatingButton()).toBeNull();
        forbidden.unmount();

        const hidden = mounted(aPost({ status: 'hidden' }));
        select(hidden, 'abordaje');
        await hidden.vm.$nextTick();
        expect(floatingButton()).toBeNull();
    });

    it('offers nothing while editing', async () => {
        const wrapper = mounted(
            aPost({
                canEdit: true,
                document: { type: 'doc', content: [] },
                updateUrl: '/foro/mensajes/post-1',
            })
        );
        select(wrapper, 'abordaje');
        await wrapper.get('button[aria-label="Editar"]').trigger('click');
        document.dispatchEvent(new Event('selectionchange'));
        vi.advanceTimersByTime(150);
        await wrapper.vm.$nextTick();

        expect(floatingButton()).toBeNull();
    });

    it('shows one button for the post that holds the selection', async () => {
        const first = mounted(aPost());
        const second = mounted(aPost({ id: 'post-2', number: 3 }));
        select(second, 'marineros');
        await second.vm.$nextTick();

        expect(document.body.querySelectorAll('[data-testid="selection-quote"]')).toHaveLength(1);
        floatingButton()!.click();
        await second.vm.$nextTick();

        expect(second.emitted('quote')?.at(-1)).toEqual([{ postId: 'post-2', text: 'marineros' }]);
        expect(first.emitted('quote')).toBeUndefined();
    });
});
