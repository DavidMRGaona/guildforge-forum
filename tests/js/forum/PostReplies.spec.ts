import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost, ForumPostPreview } from '../../../resources/js/types/forum';

const REPLIES = '/foro/mensajes/post-1/respuestas';

function aReply(number: number): ForumPostPreview {
    return {
        id: `reply-${number}`,
        number,
        url: `/foro/t/7-partida/${number}`,
        status: 'visible',
        authorId: null,
        authorName: `Persona ${number}`,
        authorAvatarPublicId: null,
        character: null,
        publishedAt: '2026-11-12T10:00:00+01:00',
        publishedLabel: '12/11/2026 10:00',
        html: `<p>Respuesta ${number}</p>`,
        discussionTitle: 'Partida',
        truncated: false,
        hiddenImages: 0,
        hiddenEmbeds: 0,
    };
}

function aPost(replyCount: number): ForumPost {
    return {
        id: 'post-1',
        number: 1,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: true,
        status: 'visible',
        html: '<p>Primer mensaje</p>',
        placeholder: null,
        quoteText: 'Primer mensaje',
        publishedAt: '2026-11-12T10:00:00+01:00',
        publishedLabel: '12/11/2026 10:00',
        editedAt: null,
        editedLabel: null,
        url: '/foro/t/7-partida/1',
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
        replyCount,
        repliesUrl: replyCount > 0 ? REPLIES : null,
    };
}

const wrappers: VueWrapper[] = [];

function mounted(replyCount: number): VueWrapper {
    const wrapper = mount(PostItem, {
        attachTo: document.body,
        props: {
            post: aPost(replyCount),
            focused: false,
            canQuote: false,
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
    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    const pages: Record<string, { data: ForumPostPreview[]; next: string | null }> = {
        [REPLIES]: { data: [aReply(2), aReply(3)], next: `${REPLIES}?pagina=2` },
        [`${REPLIES}?pagina=2`]: { data: [aReply(4)], next: null },
    };
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string) =>
                new Response(JSON.stringify(pages[url]), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                })
        )
    );
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('the replies of a post', () => {
    it('offers nothing without replies', () => {
        expect(mounted(0).find('[data-testid="post-replies"]').exists()).toBe(false);
    });

    it('says how many there are and lists them in order when opened', async () => {
        const wrapper = mounted(3);
        const button = wrapper.get('[data-testid="post-replies"]');
        expect(button.text()).toContain('3 respuestas');
        expect(button.attributes('aria-expanded')).toBe('false');

        await button.trigger('click');
        await flushPromises();

        expect(button.attributes('aria-expanded')).toBe('true');
        const list = wrapper.get(`#${button.attributes('aria-controls')}`);
        expect(list.text()).toMatch(/Respuesta 2[\s\S]*Respuesta 3/u);
    });

    it('asks for more while there are more', async () => {
        const wrapper = mounted(3);
        await wrapper.get('[data-testid="post-replies"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="post-replies-more"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Respuesta 4');
        expect(wrapper.find('[data-testid="post-replies-more"]').exists()).toBe(false);
    });

    it('loads an embed of a reply when asked', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({
                            data: [
                                {
                                    ...aReply(2),
                                    html:
                                        '<figure class="forum-embed" data-forum-embed data-embed-service="youtube" data-embed-src="https://www.youtube-nocookie.com/embed/x" data-embed-aspect="video" data-embed-referrer="strict-origin" data-embed-sandbox="allow-scripts" data-embed-title="YouTube">' +
                                        '<div class="forum-embed__consent"><button type="button" data-embed-load>Cargar contenido de YouTube</button></div></figure>',
                                },
                            ],
                            next: null,
                        }),
                        { status: 200, headers: { 'Content-Type': 'application/json' } }
                    )
            )
        );
        const wrapper = mounted(1);
        await wrapper.get('[data-testid="post-replies"]').trigger('click');
        await flushPromises();

        (wrapper.element as Element).querySelector<HTMLButtonElement>('[data-embed-load]')!.click();

        expect(
            (wrapper.element as Element).querySelector('[data-testid="post-replies-list"] iframe')
        ).not.toBeNull();
    });

    it('says one reply in the singular', () => {
        expect(mounted(1).get('[data-testid="post-replies"]').text()).toContain('1 respuesta');
    });
});
