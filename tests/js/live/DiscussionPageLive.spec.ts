import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Discussion from '../../../resources/js/pages/Forum/Discussion.vue';
import type { ForumPost } from '../../../resources/js/types/forum';

const UPDATES = '/foro/temas/discussion-1/novedades';

function aPost(number: number): ForumPost {
    return {
        id: `post-id-${number}`,
        number,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: false,
        status: 'visible',
        html: `<p>Mensaje ${number}</p>`,
        placeholder: null,
        quoteText: null,
        publishedAt: '2026-11-10T10:00:00+01:00',
        publishedLabel: '10/11/2026 10:00',
        editedAt: null,
        editedLabel: null,
        url: `/foro/t/7-partida/${number}`,
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
    };
}

function props(nextUrl: string | null): Record<string, unknown> {
    return {
        discussion: {
            id: 'discussion-1',
            number: 7,
            title: 'Partida del viernes',
            url: '/foro/t/7-partida',
            isClosed: false,
            isPinned: false,
            isHidden: false,
            isAwaitingApproval: false,
            topics: [],
            follow: null,
            category: { name: 'General', url: '/foro/c/general', parent: null },
        },
        posts: [1, 2, 3].map(aPost),
        pagination: {
            page: 1,
            pageCount: nextUrl === null ? 1 : 2,
            perPage: 3,
            highestNumber: 3,
            firstUrl: null,
            previousUrl: null,
            nextUrl,
            lastUrl: null,
        },
        focusNumber: null,
        lastReadNumber: null,
        mentionNames: {},
        reply: {
            canPost: false,
            postUrl: '/foro/temas/discussion-1/mensajes',
            mentionsUrl: null,
            uploadUrl: null,
            maxImages: 10,
            imageTypes: [],
            externalImageUrl: null,
            externalImageHosts: [],
            embedUrl: null,
            embedServices: [],
        },
        readUrl: null,
        moderation: null,
    };
}

function serve(replies: Record<string, unknown>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string) => {
        const body = replies[url];

        return body === undefined
            ? new Response(null, { status: 204 })
            : new Response(JSON.stringify(body), {
                  status: 200,
                  headers: { 'Content-Type': 'application/json' },
              });
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

const mounted: VueWrapper[] = [];

function mountPage(nextUrl: string | null): VueWrapper {
    const wrapper = mount(Discussion, { props: props(nextUrl) as never, attachTo: document.body });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    vi.stubGlobal('IntersectionObserver', undefined);
    vi.mocked(router.reload).mockClear();
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

const MERGED = { title: 'Destino', url: '/foro/t/2-destino' };

function serveMerged(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(
        async () =>
            new Response(JSON.stringify({ message: 'Este tema se ha unido', mergedInto: MERGED }), {
                status: 410,
                headers: { 'Content-Type': 'application/json' },
            })
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

describe('Discussion page, when its discussion is merged into another', () => {
    it('stops asking and offers the way to the target without taking the reader there', async () => {
        const fetchMock = serveMerged();
        const wrapper = mountPage(null);

        await vi.advanceTimersByTimeAsync(20_000);
        await flushPromises();

        const notice = wrapper.get('[data-testid="merged-notice"]');
        expect(notice.attributes('role')).toBe('status');
        expect(notice.text()).toContain('Destino');
        expect(notice.get('a').attributes('href')).toBe('/foro/t/2-destino');
        expect(router.visit).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(600_000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        // What the page had stays where it was
        expect(wrapper.findAll('article')).toHaveLength(3);
    });

    it('shows no notice while nothing was merged', async () => {
        serve({});
        const wrapper = mountPage(null);

        await vi.advanceTimersByTimeAsync(20_000);
        await flushPromises();

        expect(wrapper.find('[data-testid="merged-notice"]').exists()).toBe(false);
    });

    it('answers a reply from the old page with a clear error and the same way on', async () => {
        const fetchMock = serveMerged();
        const wrapper = mountPage(null);

        wrapper
            .findComponent({ name: 'ReplyBox' })
            .vm.$emit('submit', { type: 'doc', content: [] }, null);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/foro/temas/discussion-1/mensajes',
            expect.anything()
        );
        expect(wrapper.get('[data-testid="merged-notice"] a').attributes('href')).toBe(
            '/foro/t/2-destino'
        );
        expect(wrapper.findComponent({ name: 'ReplyBox' }).props('error')).toContain(
            'no se ha publicado'
        );
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('offers the same way on when following it from the old page is refused', async () => {
        const fetchMock = serveMerged();
        const page = props('/foro/t/7-partida/4');
        (page.discussion as Record<string, unknown>).follow = {
            level: 'normal',
            url: '/foro/temas/discussion-1/seguimiento',
        };
        const wrapper = mount(Discussion, { props: page as never, attachTo: document.body });
        mounted.push(wrapper);

        await wrapper.get('select').setValue('following');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/foro/temas/discussion-1/seguimiento',
            expect.anything()
        );
        expect(wrapper.get('[data-testid="merged-notice"] a').attributes('href')).toBe(
            '/foro/t/2-destino'
        );
    });

    it('offers the same way on when the old page reports what was read', async () => {
        let see: (entries: Partial<IntersectionObserverEntry>[]) => void = () => {};
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(callback: typeof see) {
                    see = callback;
                }
                observe(): void {}
                disconnect(): void {}
            }
        );
        const fetchMock = serveMerged();
        const page = props('/foro/t/7-partida/4');
        page.readUrl = '/foro/temas/discussion-1/leido';
        const wrapper = mount(Discussion, { props: page as never, attachTo: document.body });
        mounted.push(wrapper);

        see([
            {
                target: wrapper.get('[data-post-number="2"]').element,
                isIntersecting: true,
                intersectionRatio: 1,
                intersectionRect: { height: 100 } as DOMRectReadOnly,
                rootBounds: { height: 800 } as DOMRectReadOnly,
            },
        ]);
        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/foro/temas/discussion-1/leido', expect.anything());
        expect(wrapper.get('[data-testid="merged-notice"] a').attributes('href')).toBe(
            '/foro/t/2-destino'
        );
    });
});

describe('Discussion page, while it stays open', () => {
    it('follows the end of the discussion: the new posts go after the page’s own', async () => {
        const fetchMock = serve({
            [`${UPDATES}?despues=3`]: {
                posts: [aPost(4)],
                mentionNames: {},
                highestNumber: 4,
                more: false,
            },
        });
        const wrapper = mountPage(null);

        await vi.advanceTimersByTimeAsync(20_000);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(`${UPDATES}?despues=3`, expect.anything());
        const numbers = wrapper
            .findAll('article')
            .map((post) => post.attributes('data-post-number'));
        expect(numbers).toEqual(['1', '2', '3', '4']);
        expect(wrapper.get('[data-testid="new-posts-notice"]').text()).toContain(
            'Un mensaje nuevo'
        );
    });

    it('asks nothing on a page that is not the last: what comes in belongs to a later page', async () => {
        const fetchMock = serve({});
        mountPage('/foro/t/7-partida/4');

        await vi.advanceTimersByTimeAsync(120_000);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('asks again from the server’s posts after one was edited', async () => {
        serve({});
        const wrapper = mountPage(null);
        await vi.advanceTimersByTimeAsync(0);

        wrapper.findAllComponents({ name: 'PostItem' })[0]?.vm.$emit('changed');
        await flushPromises();

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['posts', 'mentionNames'] })
        );
    });
});
