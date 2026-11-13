import { nextTick, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { RealtimePayload } from '@/composables/useRealtimeChannel';
import type { JsonAnswer } from '../../../resources/js/forum/api';
import {
    realtimeTransport,
    type LiveConnection,
    type RealtimeFeed,
} from '../../../resources/js/live/transport';
import { liveNewPosts, livePulse } from '../../../resources/js/live/transports';

type Handler = (event: string, payload: RealtimePayload) => void;

/** A channel the test drives: whether it is live, and the messages it delivers */
function aFeed(): RealtimeFeed & {
    send: (event: string, payload?: Record<string, unknown>) => void;
    isOpen: () => boolean;
    setLive: (live: boolean) => Promise<void>;
} {
    const live = ref(false);
    let handler: Handler | null = null;

    return {
        live,
        open(onMessage): () => void {
            handler = onMessage;

            return () => {
                handler = null;
            };
        },
        send: (event, payload = {}) => handler?.(event, Object.freeze({ ...payload })),
        isOpen: () => handler !== null,
        setLive: async (value): Promise<void> => {
            live.value = value;
            await nextTick();
        },
    };
}

function answer<T>(status: number, data: T | null = null): JsonAnswer<T> {
    return { ok: status >= 200 && status < 300, status, data, message: null, errors: {} };
}

const VISIBLE = { hidden: false, addEventListener: vi.fn(), removeEventListener: vi.fn() };

function noContent(): Response {
    return new Response(null, { status: 204 });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('realtimeTransport', () => {
    function connect(
        feed: RealtimeFeed,
        extra: { minGapMs?: number } = {}
    ): { request: ReturnType<typeof vi.fn>; connection: LiveConnection } {
        const request = vi.fn(async (): Promise<JsonAnswer<{ n: number }>> =>
            answer<{ n: number }>(204)
        );
        const connection = realtimeTransport<{ n: number }>({
            intervalMs: 1000,
            request,
            feed,
            visibility: VISIBLE,
            random: () => 0.5,
            ...extra,
        }).connect({ cursor: () => 0 }, () => undefined);

        return { request, connection };
    }

    it('asks at its pace while the channel is not live', async () => {
        const feed = aFeed();
        const { request } = connect(feed);

        await vi.advanceTimersByTimeAsync(3000);

        expect(request).toHaveBeenCalledTimes(3);
    });

    it('stops asking at its pace while live and asks once when a message comes', async () => {
        const feed = aFeed();
        const { request } = connect(feed);
        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        // Just connected: it asks once for what came before
        expect(request).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(request).toHaveBeenCalledTimes(1);

        feed.send('post.created', { number: 4 });
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(2);
    });

    it('goes back to its pace when the connection is lost and catches up once when it returns', async () => {
        const feed = aFeed();
        const { request } = connect(feed);
        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        request.mockClear();

        await feed.setLive(false);
        await vi.advanceTimersByTimeAsync(2000);
        expect(request).toHaveBeenCalledTimes(2);

        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(request).toHaveBeenCalledTimes(3);
    });

    it('tries a failed question again while live', async () => {
        const feed = aFeed();
        const { request } = connect(feed);
        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        request.mockClear();
        request.mockResolvedValueOnce(answer(500));

        feed.send('post.created', { number: 4 });
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(1);

        // Twice the pace after one failure, then nothing more once it worked
        await vi.advanceTimersByTimeAsync(2000);
        expect(request).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(request).toHaveBeenCalledTimes(2);
    });

    it('answers messages that come close together with one question at the end of the gap', async () => {
        const feed = aFeed();
        const { request } = connect(feed, { minGapMs: 10_000 });
        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        request.mockClear();
        await vi.advanceTimersByTimeAsync(10_000);

        feed.send('pulse.changed');
        feed.send('pulse.changed');
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(1);

        feed.send('pulse.changed');
        feed.send('pulse.changed');
        await vi.advanceTimersByTimeAsync(9_000);
        expect(request).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(request).toHaveBeenCalledTimes(2);
    });

    it('leaves the channel and stops asking when the connection stops', async () => {
        const feed = aFeed();
        const { request, connection } = connect(feed);
        expect(feed.isOpen()).toBe(true);

        connection.stop();
        await vi.advanceTimersByTimeAsync(5000);

        expect(feed.isOpen()).toBe(false);
        expect(request).not.toHaveBeenCalled();
    });
});

describe('liveNewPosts', () => {
    const UPDATES = '/foro/temas/discussion-1/novedades';

    function connect(feed: RealtimeFeed, held: number[] = [1, 2, 3]): ReturnType<typeof vi.fn> {
        const fetchMock = vi.fn(async () => noContent());
        vi.stubGlobal('fetch', fetchMock);
        liveNewPosts('discussion-1', feed).connect(
            { cursor: () => Math.max(...held), holds: (number) => held.includes(number) },
            () => undefined
        );

        return fetchMock;
    }

    async function liveAndQuiet(
        feed: ReturnType<typeof aFeed>,
        fetchMock: ReturnType<typeof vi.fn>
    ): Promise<void> {
        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        fetchMock.mockClear();
    }

    it('asks for posts after its last one, and not for one it already has', async () => {
        const feed = aFeed();
        const fetchMock = connect(feed);
        await liveAndQuiet(feed, fetchMock);

        feed.send('post.created', { number: 3 });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).not.toHaveBeenCalled();

        feed.send('post.created', { number: 4 });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledWith(`${UPDATES}?despues=3`, expect.anything());
    });

    it('asks again for a post it holds that changed, and ignores the others', async () => {
        const feed = aFeed();
        const fetchMock = connect(feed);
        await liveAndQuiet(feed, fetchMock);

        feed.send('post.changed', { number: 9 });
        feed.send('post.changed', { number: 'two' });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).not.toHaveBeenCalled();

        feed.send('post.changed', { number: 2 });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledWith(
            `${UPDATES}?despues=3&cambiados=2`,
            expect.anything()
        );

        // Once answered, it is not asked for again
        feed.send('post.created', { number: 4 });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenLastCalledWith(`${UPDATES}?despues=3`, expect.anything());
    });

    it('keeps a changed post for the next question when the answer fails', async () => {
        const feed = aFeed();
        const fetchMock = connect(feed);
        await liveAndQuiet(feed, fetchMock);
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 503 }));

        feed.send('post.changed', { number: 1 });
        await vi.advanceTimersByTimeAsync(0);
        await vi.advanceTimersByTimeAsync(40_000);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock).toHaveBeenLastCalledWith(
            `${UPDATES}?despues=3&cambiados=1`,
            expect.anything()
        );
    });

    it('asks when the discussion changed as a whole', async () => {
        const feed = aFeed();
        const fetchMock = connect(feed);
        await liveAndQuiet(feed, fetchMock);

        feed.send('discussion.changed');
        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledWith(`${UPDATES}?despues=3`, expect.anything());
    });

    it('asks every 20 seconds while real time is off', async () => {
        const feed = aFeed();
        const fetchMock = connect(feed);

        await vi.advanceTimersByTimeAsync(40_000);

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});

describe('livePulse', () => {
    it('asks only for messages about what the page shows', async () => {
        const fetchMock = vi.fn(
            async () =>
                new Response(JSON.stringify({ unreadFollowed: 0, discussions: {} }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                })
        );
        vi.stubGlobal('fetch', fetchMock);
        const feed = aFeed();
        livePulse(feed, () => ['d-1'], {
            immediate: false,
            wants: (_event, payload) => payload.discussion === 'd-1',
        }).connect({ cursor: () => 0 }, () => undefined);
        await feed.setLive(true);
        await vi.advanceTimersByTimeAsync(0);
        fetchMock.mockClear();
        await vi.advanceTimersByTimeAsync(10_000);

        feed.send('discussion.activity', { discussion: 'd-2', number: 5 });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).not.toHaveBeenCalled();

        feed.send('discussion.activity', { discussion: 'd-1', number: 5 });
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledWith('/foro/novedades?temas=d-1', expect.anything());
    });
});
