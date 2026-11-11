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
        },
        ...overrides,
    };
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
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('SubjectConversation', () => {
    it('draws nothing for a record without a conversation the reader may see', async () => {
        serve({ [BASE]: { body: { conversation: null } } });

        expect((await mountIt()).html()).toBe('<!--v-if-->');
    });

    it('draws nothing when the forum does not answer', async () => {
        serve({ [BASE]: { status: 500 } });

        expect((await mountIt()).html()).toBe('<!--v-if-->');
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

    it('moves the read mark only while reading is continuous', async () => {
        signIn();
        serve({
            [BASE]: {
                body: {
                    conversation: aConversation({
                        readUrl: '/foro/temas/discussion-1/leido',
                        lastReadNumber: 3,
                    }),
                },
            },
        });
        const gap = await mountIt();
        expect(gap.findComponent(SubjectConversationThread).props('readUrl')).toBeNull();

        serve({
            [BASE]: {
                body: {
                    conversation: aConversation({
                        readUrl: '/foro/temas/discussion-1/leido',
                        lastReadNumber: 15,
                    }),
                },
            },
        });
        const continuous = await mountIt();
        expect(continuous.findComponent(SubjectConversationThread).props('readUrl')).toBe(
            '/foro/temas/discussion-1/leido'
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
