import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SubjectConversation from '../../../resources/js/components/subjects/SubjectConversation.vue';
import type { ForumPost, ForumSubjectConversation } from '../../../resources/js/types/forum';
import { signIn } from '../support/page';

const BASE = '/foro/fichas/game-table/table-1';
const READ_URL = '/foro/temas/discussion-1/leido';
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
    };
}

function aConversation(
    overrides: Partial<ForumSubjectConversation> = {}
): ForumSubjectConversation {
    return {
        discussion: {
            id: 'discussion-1',
            number: 7,
            title: 'Partida del viernes',
            url: '/foro/t/7-partida',
            isClosed: false,
            closedReason: null,
        },
        posts: [16, 17, 18, 19, 20].map(aPost),
        mentionNames: {},
        olderCount: 14,
        olderNext: 10,
        olderUrl: `${BASE}?antes=16`,
        highestNumber: 20,
        lastReadNumber: 19,
        readUrl: READ_URL,
        reply: {
            canPost: false,
            postUrl: '/foro/temas/discussion-1/mensajes',
            mentionsUrl: null,
            uploadUrl: null,
            maxImages: 10,
            imageTypes: ['image/png'],
        },
        ...overrides,
    };
}

class FakeObserver {
    static all: FakeObserver[] = [];

    elements: Element[] = [];

    constructor(private readonly callback: IntersectionObserverCallback) {
        FakeObserver.all.push(this);
    }

    observe(element: Element): void {
        this.elements.push(element);
    }

    unobserve(): void {}

    disconnect(): void {
        this.elements = [];
    }

    takeRecords(): IntersectionObserverEntry[] {
        return [];
    }

    /** Shows it the elements it watches, as fully on screen */
    see(only?: (element: Element) => boolean): void {
        const entries = this.elements
            .filter((element) => only?.(element) ?? true)
            .map(
                (target) =>
                    ({
                        target,
                        isIntersecting: true,
                        intersectionRatio: 1,
                        intersectionRect: { height: 200 },
                        rootBounds: { height: 800 },
                    }) as unknown as IntersectionObserverEntry
            );
        this.callback(entries, this as unknown as IntersectionObserver);
    }
}

function seeAll(): void {
    FakeObserver.all.forEach((observer) => observer.see());
}

type Reply = { status?: number; body?: unknown };

/** Answers each address in turn with what was queued for it; a path never queued is a 404 */
function serve(queues: Record<string, Reply[]>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
        const key = init?.method === 'POST' ? `POST ${url}` : url;
        const queue = queues[key];
        const reply =
            queue !== undefined && queue.length > 0
                ? queue.length > 1
                    ? queue.shift()
                    : queue[0]
                : { status: 404 };
        if (reply?.status === 204) {
            return new Response(null, { status: 204 });
        }

        return new Response(JSON.stringify(reply?.body ?? {}), {
            status: reply?.status ?? 200,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function newPosts(numbers: number[], more = false): Reply {
    return {
        body: {
            posts: numbers.map(aPost),
            mentionNames: {},
            highestNumber: Math.max(...numbers),
            more,
        },
    };
}

async function tick(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
}

const mounted: VueWrapper[] = [];

async function mountIt(conversation: ForumSubjectConversation): Promise<VueWrapper> {
    const wrapper = mount(SubjectConversation, {
        props: { subjectType: 'game-table', subjectId: 'table-1' },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await tick(0);
    expect(wrapper.findAll('article')).toHaveLength(conversation.posts.length);

    return wrapper;
}

function setHidden(hidden: boolean): void {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    document.dispatchEvent(new Event('visibilitychange'));
}

const askedFor = (fetchMock: ReturnType<typeof vi.fn>, url: string): number =>
    fetchMock.mock.calls.filter(([asked]) => asked === url).length;

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    FakeObserver.all = [];
    vi.stubGlobal('IntersectionObserver', FakeObserver);
    Element.prototype.scrollIntoView = vi.fn();
    // Waits are spread ±10 %: the middle of the spread makes them exact
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    signIn();
});

afterEach(() => {
    // Gone before the fetch stand-in is: leaving reports the read mark one last time
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    setHidden(false);
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('SubjectConversation, while it stays open', () => {
    it('asks every 20 s for the posts after the last one and puts them at the end', async () => {
        const conversation = aConversation();
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [{ status: 204 }, newPosts([21, 22])],
        });
        const wrapper = await mountIt(conversation);

        await tick(19_000);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(0);
        await tick(2_000);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(1);
        expect(wrapper.findAll('article')).toHaveLength(5);

        await tick(20_000);
        const numbers = wrapper
            .findAll('article')
            .map((post) => post.attributes('data-post-number'));
        expect(numbers).toEqual(['16', '17', '18', '19', '20', '21', '22']);
        // The next question starts from the new last post
        expect(wrapper.get('[data-testid="new-posts-notice"]').text()).toContain(
            '2 mensajes nuevos'
        );
    });

    it('announces the new posts politely, in a region that was already there, without taking the focus', async () => {
        const conversation = aConversation();
        serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [newPosts([21])],
        });
        const wrapper = await mountIt(conversation);

        const region = wrapper.get('[data-testid="new-posts-notice"] [role="status"]');
        expect(region.attributes('aria-live')).toBe('polite');
        expect(region.text()).toBe('');
        const before = document.activeElement;

        await tick(21_000);

        // The same element, now with the text
        expect(wrapper.get('[data-testid="new-posts-notice"] [role="status"]').element).toBe(
            region.element
        );
        expect(region.text()).toContain('Un mensaje nuevo');
        expect(document.activeElement).toBe(before);
    });

    it('asks again at once when a batch said there is more', async () => {
        const conversation = aConversation();
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [newPosts([21, 22], true)],
            [`${UPDATES}?despues=22`]: [{ status: 204 }],
        });
        await mountIt(conversation);

        await tick(21_000);
        await tick(0);

        expect(askedFor(fetchMock, `${UPDATES}?despues=22`)).toBe(1);
    });

    it('goes to the first new post when asked, and the notice goes away', async () => {
        const conversation = aConversation();
        serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [newPosts([21, 22])],
        });
        const wrapper = await mountIt(conversation);
        await tick(21_000);

        await wrapper.get('[data-testid="new-posts-notice"] button').trigger('click');

        expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
        expect(document.activeElement?.id).toBe('post-21');
        expect(wrapper.find('[data-testid="new-posts-notice"] button').exists()).toBe(false);
    });

    it('drops the notice once its last post was on screen', async () => {
        const conversation = aConversation();
        serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [newPosts([21])],
        });
        const wrapper = await mountIt(conversation);
        await tick(21_000);
        expect(wrapper.find('[data-testid="new-posts-notice"] button').exists()).toBe(true);

        seeAll();
        await tick(0);

        expect(wrapper.find('[data-testid="new-posts-notice"] button').exists()).toBe(false);
    });

    it('never marks as read what only arrived, and only what was seen', async () => {
        const conversation = aConversation();
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [newPosts([21, 22])],
            [`POST ${READ_URL}`]: [{ body: { read: true } }],
        });
        await mountIt(conversation);
        await tick(21_000);
        await tick(5_000);

        // They are below the screen: nothing reported
        expect(askedFor(fetchMock, READ_URL)).toBe(0);
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);

        // Only the first of them is seen
        FakeObserver.all.forEach((observer) => observer.see((element) => element.id === 'post-21'));
        await tick(1_500);
        const reads = fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST');
        expect(reads).toHaveLength(1);
        expect(JSON.parse(String(reads[0]?.[1]?.body))).toEqual({ number: 21 });
    });

    it('does not follow the read mark when the page never reads continuously', async () => {
        const conversation = aConversation({ lastReadNumber: 3 });
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [newPosts([21])],
        });
        await mountIt(conversation);
        await tick(21_000);

        seeAll();
        await tick(2_000);

        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
    });

    it('never asks for the opening post of a conversation that has no comments yet', async () => {
        const conversation = aConversation({
            posts: [],
            highestNumber: 1,
            olderCount: 0,
            olderUrl: null,
        });
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=1`]: [newPosts([2])],
        });
        const wrapper = await mountIt(conversation);

        await tick(21_000);

        expect(askedFor(fetchMock, `${UPDATES}?despues=1`)).toBe(1);
        expect(wrapper.findAll('article')).toHaveLength(1);
        expect(wrapper.get('[data-testid="new-posts-notice"]').text()).toContain(
            'Un mensaje nuevo'
        );
    });

    it('pauses with the tab hidden and asks as soon as it is shown again', async () => {
        const conversation = aConversation();
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [{ status: 204 }],
        });
        await mountIt(conversation);

        setHidden(true);
        await tick(120_000);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(0);

        setHidden(false);
        await tick(0);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(1);
    });

    it('waits longer after an error', async () => {
        const conversation = aConversation();
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [{ status: 500 }],
        });
        await mountIt(conversation);

        await tick(22_000);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(1);
        await tick(30_000);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(1);
        await tick(20_000);
        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(2);
    });

    it('does not ask about a conversation that is closed', async () => {
        const conversation = aConversation({
            discussion: { ...aConversation().discussion, isClosed: true, closedReason: 'closed' },
        });
        const fetchMock = serve({ [BASE]: [{ body: { conversation } }] });
        await mountIt(conversation);

        await tick(120_000);

        expect(
            fetchMock.mock.calls.filter(([url]) => String(url).includes('novedades'))
        ).toHaveLength(0);
    });

    it('stops for good when the discussion is no longer readable', async () => {
        const conversation = aConversation();
        const fetchMock = serve({
            [BASE]: [{ body: { conversation } }],
            [`${UPDATES}?despues=20`]: [{ status: 404 }],
        });
        await mountIt(conversation);

        await tick(21_000);
        await tick(600_000);

        expect(askedFor(fetchMock, `${UPDATES}?despues=20`)).toBe(1);
    });
});
