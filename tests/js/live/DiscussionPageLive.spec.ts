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
