import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SubjectConversation from '../../../resources/js/components/subjects/SubjectConversation.vue';
import SubjectConversationThread from '../../../resources/js/components/subjects/SubjectConversationThread.vue';
import type { ForumPost, ForumSubjectConversation } from '../../../resources/js/types/forum';
import { signIn } from '../support/page';

const BASE = '/foro/fichas/game-table/table-1';

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
        lastReadNumber: null,
        readUrl: null,
        reply: {
            canPost: false,
            postUrl: '/foro/temas/discussion-1/mensajes',
            mentionsUrl: null,
            uploadUrl: null,
            maxImages: 10,
            imageTypes: ['image/png'],
            externalImageUrl: null,
            externalImageHosts: [],
            embedUrl: null,
            embedServices: [],
        },
        ...overrides,
    };
}

const READ_URL = '/foro/temas/discussion-1/leido';

/** Posts `from`–`to` of a discussion whose reader read up to `lastRead` (the opening post aside) */
function aGap(from: number, to: number, lastRead: number): ForumSubjectConversation {
    const posts: ForumPost[] = [];
    for (let number = from; number <= to; number++) {
        posts.push(aPost(number));
    }

    return aConversation({
        posts,
        olderCount: from - 2,
        olderNext: Math.min(10, from - 2),
        olderUrl: from > 2 ? `${BASE}?antes=${from}` : null,
        highestNumber: 50,
        readUrl: READ_URL,
        lastReadNumber: lastRead,
    });
}

/** The intersection observers the read tracker creates, so a test can show it every post */
class FakeObserver {
    static last: FakeObserver | null = null;

    elements: Element[] = [];

    constructor(private readonly callback: IntersectionObserverCallback) {
        FakeObserver.last = this;
    }

    observe(element: Element): void {
        this.elements.push(element);
    }

    unobserve(): void {}

    disconnect(): void {}

    takeRecords(): IntersectionObserverEntry[] {
        return [];
    }
}

/** Every post of the current list scrolls fully into view */
function seeEveryPost(): void {
    const observer = FakeObserver.last;
    if (observer === null) {
        return;
    }
    const entries = observer.elements.map(
        (target) =>
            ({
                target,
                isIntersecting: true,
                intersectionRatio: 1,
                intersectionRect: { height: 200 },
                rootBounds: { height: 800 },
            }) as unknown as IntersectionObserverEntry
    );
    (observer as unknown as { callback: IntersectionObserverCallback }).callback(
        entries,
        observer as unknown as IntersectionObserver
    );
}

/** Answers each address with its JSON (or status), and records what was asked */
function serve(
    answers: Record<string, { status?: number; body?: unknown }>
): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string) => {
        const answer = answers[url] ?? { status: 404, body: {} };

        return new Response(JSON.stringify(answer.body ?? {}), {
            status: answer.status ?? 200,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountIt(): Promise<VueWrapper> {
    const wrapper = mount(SubjectConversation, {
        props: { subjectType: 'game-table', subjectId: 'table-1' },
    });
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    localStorage.clear();
    FakeObserver.last = null;
    vi.stubGlobal('IntersectionObserver', FakeObserver);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('SubjectConversation', () => {
    it('draws nothing for a record without a conversation the reader may see', async () => {
        serve({ [BASE]: { body: { conversation: null } } });

        expect((await mountIt()).html()).toBe('<!--v-if-->');
    });

    it('says neutrally that it could not load, and tries again', async () => {
        serve({ [BASE]: { status: 500 } });
        const wrapper = await mountIt();

        expect(wrapper.find('p[role="status"]').text()).toContain(
            'No se ha podido cargar la conversación.'
        );
        expect(wrapper.find('section').exists()).toBe(false);

        serve({ [BASE]: { body: { conversation: aConversation() } } });
        await wrapper.find('p[role="status"] button').trigger('click');
        await flushPromises();

        expect(wrapper.find('p[role="status"]').exists()).toBe(false);
        expect(wrapper.findAll('article')).toHaveLength(5);
    });

    it('shows the last posts, the way to earlier ones and to the whole conversation', async () => {
        serve({ [BASE]: { body: { conversation: aConversation() } } });
        const wrapper = await mountIt();

        expect(wrapper.find('h2').text()).toBe('Conversación');
        expect(wrapper.findAll('article')).toHaveLength(5);
        expect(wrapper.find('a[href="/foro/t/7-partida"]').text()).toBe(
            'Ver conversación completa'
        );
        expect(wrapper.find('button[type="button"]').text()).toBe('Ver los 10 anteriores');
        // Guests are asked to sign in
        expect(wrapper.text()).toContain('Inicia sesión para comentar.');
    });

    it('puts earlier posts above the last ones', async () => {
        const fetchMock = serve({
            [BASE]: { body: { conversation: aConversation() } },
            [`${BASE}?antes=16`]: {
                body: {
                    conversation: aConversation({
                        posts: [14, 15].map(aPost),
                        olderCount: 12,
                        olderNext: 10,
                        olderUrl: `${BASE}?antes=14`,
                    }),
                },
            },
        });
        const wrapper = await mountIt();

        await wrapper.find('button[type="button"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(`${BASE}?antes=16`, expect.anything());
        const numbers = wrapper
            .findAll('article')
            .map((post) => post.attributes('data-post-number'));
        expect(numbers).toEqual(['14', '15', '16', '17', '18', '19', '20']);
    });

    it('says why a closed conversation takes no comments', async () => {
        signIn();
        serve({
            [BASE]: {
                body: {
                    conversation: aConversation({
                        discussion: {
                            ...aConversation().discussion,
                            isClosed: true,
                            closedReason: 'cancelled',
                        },
                    }),
                },
            },
        });

        expect((await mountIt()).text()).toContain(
            'Se ha cancelado: la conversación ya no admite comentarios.'
        );
    });

    it('never moves the read mark over posts it did not show', async () => {
        signIn();
        const fetchMock = serve({ [BASE]: { body: { conversation: aGap(46, 50, 10) } } });
        const wrapper = await mountIt();

        expect(wrapper.findComponent(SubjectConversationThread).props('readUrl')).toBeNull();
        seeEveryPost();
        wrapper.unmount();

        expect(fetchMock).not.toHaveBeenCalledWith(READ_URL, expect.anything());
    });

    it('moves the read mark once earlier posts join it to what was read', async () => {
        signIn();
        const answers: Record<string, { body: unknown }> = {
            [BASE]: { body: { conversation: aGap(46, 50, 10) } },
        };
        for (const before of [46, 36, 26, 16]) {
            answers[`${BASE}?antes=${before}`] = {
                body: { conversation: aGap(Math.max(2, before - 10), before - 1, 10) },
            };
        }
        const fetchMock = serve(answers);
        const wrapper = await mountIt();

        for (let i = 0; i < 4; i++) {
            expect(wrapper.findComponent(SubjectConversationThread).props('readUrl')).toBeNull();
            await wrapper.find('section > div.space-y-2 button').trigger('click');
            await flushPromises();
        }

        // Posts 6–50 are on the page and the mark was at 10: reading goes on from there
        expect(wrapper.findComponent(SubjectConversationThread).props('readUrl')).toBe(READ_URL);
        seeEveryPost();
        wrapper.unmount();

        expect(fetchMock).toHaveBeenCalledWith(
            READ_URL,
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ number: 50 }) })
        );
    });

    it('invites the first comment when there are none yet', async () => {
        signIn();
        serve({
            [BASE]: {
                body: {
                    conversation: aConversation({
                        posts: [],
                        olderCount: 0,
                        olderNext: 0,
                        olderUrl: null,
                        reply: { ...aConversation().reply, canPost: true },
                    }),
                },
            },
        });
        const wrapper = await mountIt();

        expect(wrapper.text()).toContain(
            'Todavía no hay comentarios. Puedes ser la primera persona en escribir.'
        );
        expect(wrapper.find('[role="textbox"]').exists()).toBe(true);
    });
});
