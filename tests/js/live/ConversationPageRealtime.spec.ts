import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { RealtimeListener } from '@/composables/useRealtimeChannel';
import Conversation from '../../../resources/js/pages/Forum/Conversation.vue';
import type { ForumConversationEntry } from '../../../resources/js/types/forum';
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

const UPDATES = '/foro/conversaciones/c-1/novedades';

function entry(
    number: number,
    overrides: Partial<ForumConversationEntry> = {}
): ForumConversationEntry {
    return {
        id: `e-${number}`,
        number,
        kind: 'message',
        state: 'shown',
        authorId: 'a-1',
        authorName: 'Ana Pérez',
        subjectName: null,
        isMine: false,
        html: `<p>Mensaje ${number}</p>`,
        hiddenReason: null,
        createdIso: '2026-11-20T10:00:00+01:00',
        createdLabel: '20/11/2026 10:00',
        deleteUrl: null,
        reportUrl: null,
        ...overrides,
    };
}

const mounted: VueWrapper[] = [];

function mountPage(nextUrl: string | null = null): VueWrapper {
    const wrapper = mount(Conversation, {
        props: {
            conversation: { id: 'c-1', title: 'Partida', url: '/foro/conversaciones/c-1' },
            participants: [
                { id: 'user-1', name: 'Luis Gómez', isMe: true, blocked: false },
                { id: 'a-1', name: 'Ana Pérez', isMe: false, blocked: false },
            ],
            entries: [entry(1), entry(2)],
            pagination: { page: 1, lastPage: nextUrl === null ? 1 : 2, previousUrl: null, nextUrl },
            write: {
                refusal: null,
                storeUrl: '/foro/conversaciones/c-1/mensajes',
                readUrl: '/foro/conversaciones/c-1/leido',
            },
            addUrl: null,
            peopleUrl: '/foro/conversaciones/personas?conversacion=c-1',
            leaveUrl: '/foro/conversaciones/c-1/salir',
            blockUrl: '/foro/bloqueos',
            unblockUrlTemplate: '/foro/bloqueos/00000000-0000-4000-8000-000000000000',
            indexUrl: '/foro/conversaciones',
            maxPeople: 10,
        } as never,
        global: { stubs: { PostEditor: { template: '<div />' }, HelpLink: true } },
        attachTo: document.body,
    });
    mounted.push(wrapper);

    return wrapper;
}

function conversationChannel(): (typeof channels.all)[number] {
    const found = channels.all.find((channel) => channel.name() === 'forum.conversation.c-1');
    if (found === undefined) {
        throw new Error('The page does not listen to its conversation');
    }

    return found;
}

function json(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    channels.all.splice(0);
    vi.mocked(router.reload).mockClear();
    signIn();
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('Conversation page with real time', () => {
    it('shows a new message as soon as its channel says so and reads it', async () => {
        let written = false;
        const fetchMock = vi.fn(async (url: string) =>
            written && url === `${UPDATES}?despues=2`
                ? json({ entries: [entry(3)], highestNumber: 3, more: false })
                : new Response(null, { status: 204 })
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPage();
        await flushPromises();
        fetchMock.mockClear();
        written = true;

        conversationChannel().listeners['entry.created']?.(Object.freeze({ number: 3 }));
        await vi.advanceTimersByTimeAsync(0);
        await flushPromises();

        expect(wrapper.get('[data-testid="entry-3"]').text()).toContain('Mensaje 3');
        expect(fetchMock).toHaveBeenCalledWith(`${UPDATES}?despues=2`, expect.anything());
        expect(fetchMock).toHaveBeenCalledWith(
            '/foro/conversaciones/c-1/leido',
            expect.objectContaining({ method: 'POST' })
        );
    });

    it('replaces a message said to have changed, and learns who joined from a note', async () => {
        const deleted = entry(1, { state: 'deleted', html: null });
        const note = entry(3, {
            kind: 'added',
            state: 'note',
            subjectName: 'Eva Ruiz',
            html: null,
        });
        let written = false;
        const fetchMock = vi.fn(async (url: string) =>
            url === `${UPDATES}?despues=2&cambiados=1`
                ? json({ entries: [deleted], highestNumber: 2, more: false })
                : written && url === `${UPDATES}?despues=2`
                  ? json({ entries: [note], highestNumber: 3, more: false })
                  : new Response(null, { status: 204 })
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPage();
        await flushPromises();
        written = true;

        conversationChannel().listeners['entry.changed']?.(Object.freeze({ number: 1 }));
        await vi.advanceTimersByTimeAsync(0);
        await flushPromises();
        expect(wrapper.get('[data-testid="entry-1"]').text()).not.toContain('Mensaje 1');

        conversationChannel().listeners['entry.created']?.(Object.freeze({ number: 3 }));
        await vi.advanceTimersByTimeAsync(0);
        await flushPromises();
        expect(wrapper.find('[data-testid="entry-3"]').exists()).toBe(true);
        expect(router.reload).toHaveBeenCalledWith({ only: ['participants'] });
    });

    it('does not join the channel on an older page', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(null, { status: 204 }))
        );
        mountPage('/foro/conversaciones/c-1?pagina=2');
        await flushPromises();

        expect(channels.all.some((channel) => channel.name() !== null)).toBe(false);
    });
});
