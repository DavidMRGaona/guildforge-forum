import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { RealtimeListener } from '@/composables/useRealtimeChannel';
import Discussion from '../../../resources/js/pages/Forum/Discussion.vue';
import type { ForumPost } from '../../../resources/js/types/forum';
import { signIn } from '../support/page';

/** The core's channel as the page uses it, live from the start, driven by the test */
const channels = vi.hoisted(() => ({
    all: [] as Array<{
        name: () => string | null;
        listeners: Readonly<Record<string, RealtimeListener>>;
        whispers: Map<string, RealtimeListener>;
        sent: Array<[string, Record<string, unknown>]>;
    }>,
}));

vi.mock('@/composables/useRealtimeChannel', async () => {
    const { computed, readonly, ref, toValue } = await import('vue');

    return {
        useRealtimeChannel: (
            channel: () => string | null,
            listeners: Readonly<Record<string, RealtimeListener>> = {}
        ): unknown => {
            const entry = {
                name: (): string | null => toValue(channel),
                listeners,
                whispers: new Map<string, RealtimeListener>(),
                sent: [] as Array<[string, Record<string, unknown>]>,
            };
            channels.all.push(entry);
            const status = computed(() => (entry.name() === null ? 'off' : 'live'));

            return {
                status: readonly(ref(status)),
                live: computed(() => status.value === 'live'),
                whisper: (event: string, data: Record<string, unknown>): boolean => {
                    entry.sent.push([event, data]);

                    return entry.name() !== null;
                },
                onWhisper: (event: string, listener: RealtimeListener): (() => boolean) => {
                    entry.whispers.set(event, listener);

                    return (): boolean => entry.whispers.delete(event);
                },
            };
        },
    };
});

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

const mounted: VueWrapper[] = [];

function discussionChannel(): (typeof channels.all)[number] {
    const entry = channels.all.find(
        (channel) => channel.name() === 'forum.discussion.discussion-1'
    );
    if (entry === undefined) {
        throw new Error('The page does not listen to its discussion');
    }

    return entry;
}

function serve(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string) =>
        url === `${UPDATES}?despues=3`
            ? new Response(
                  JSON.stringify({
                      posts: [aPost(4)],
                      mentionNames: {},
                      highestNumber: 4,
                      more: false,
                  }),
                  { status: 200, headers: { 'Content-Type': 'application/json' } }
              )
            : new Response(null, { status: 204 })
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    vi.stubGlobal('IntersectionObserver', undefined);
    channels.all.splice(0);
    signIn();
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

function mountPage(nextUrl: string | null = null): VueWrapper {
    const wrapper = mount(Discussion, { props: props(nextUrl) as never, attachTo: document.body });
    mounted.push(wrapper);

    return wrapper;
}

describe('Discussion page with real time', () => {
    it('shows a new post as soon as its channel says so, without asking at its pace', async () => {
        const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPage();
        await flushPromises();
        // Once, as it went live, for what came before
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        serve();
        discussionChannel().listeners['post.created']?.(Object.freeze({ number: 4 }));
        await vi.advanceTimersByTimeAsync(0);
        await flushPromises();

        expect(wrapper.findAll('article')).toHaveLength(4);
        expect(wrapper.text()).toContain('Mensaje 4');
    });

    it('does not join the channel on a page that is not the last one', async () => {
        serve();
        mountPage('/foro/t/7-partida/4');
        await flushPromises();

        expect(
            channels.all.some(
                (channel) =>
                    channel.name() !== null && channel.name()?.startsWith('forum.discussion.')
            )
        ).toBe(false);
    });

    it('says who is typing, by the name the page shows', async () => {
        serve();
        const wrapper = mountPage();
        await flushPromises();

        discussionChannel().whispers.get('typing')?.(Object.freeze({ user: 'author-1' }));
        await flushPromises();
        expect(wrapper.get('[data-testid="typing"]').text()).toBe('Jane Doe está escribiendo…');

        discussionChannel().whispers.get('typing')?.(Object.freeze({ user: 'someone-else' }));
        await flushPromises();
        expect(wrapper.get('[data-testid="typing"]').text()).toBe(
            'Jane Doe y alguien más están escribiendo…'
        );

        await vi.advanceTimersByTimeAsync(6_000);
        expect(wrapper.get('[data-testid="typing"]').text()).toBe('');
    });

    it('tells the others the reader is typing, with their own id only', async () => {
        serve();
        const wrapper = mountPage();
        await flushPromises();

        const box = wrapper.findComponent({ name: 'ReplyBox' });
        box.vm.$emit('typing');
        box.vm.$emit('typing');

        expect(discussionChannel().sent).toEqual([['typing', { user: 'user-1' }]]);
    });
});
