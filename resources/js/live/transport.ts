import { watch, type Ref } from 'vue';
import type { RealtimePayload } from '@/composables/useRealtimeChannel';
import type { JsonAnswer } from '../forum/api';
import type { ForumMergedInto } from '../types/forum';
import { createPoller, type PollOutcome, type PollerVisibility } from './poller';

/**
 * How a page learns what changed without reloading. Components only know this contract: they
 * hand a transport what they have (the context) and get updates back. The consultation of the
 * first phase is `pollingTransport`; `realtimeTransport` (#17) listens to a real-time channel and
 * keeps the consultation for whenever the channel is not live. No component knows which one runs.
 */
export interface LiveContext {
    /** The highest post number the page has: what changed is whatever comes after it */
    cursor: () => number;
    /** Whether the page holds the post with this number (so a change to it matters) */
    holds?: (number: number) => boolean;
}

export interface LiveConnection {
    stop: () => void;
    /** Asks for news now (after an answer that said there is more, or an action of the reader) */
    refresh: () => void;
}

export interface LiveTransport<T> {
    /**
     * `onMerged` hears that the discussion was merged into another and where it went; the
     * connection ends there.
     */
    connect: (
        context: LiveContext,
        onUpdate: (update: T) => void,
        onMerged?: (target: ForumMergedInto) => void
    ) => LiveConnection;
}

export interface PollingOptions<T> {
    request: (context: LiveContext) => Promise<JsonAnswer<T>>;
    intervalMs: number;
    /** Asks as soon as it connects, not after the first interval (for what shows from the start) */
    immediate?: boolean;
    /** The longest wait after failures (eight times the pace by default, at most five minutes) */
    maxIntervalMs?: number;
    visibility?: PollerVisibility;
    random?: () => number;
}

/** A polling connection can also stop asking at its pace while a channel is live */
export interface PollingConnection extends LiveConnection {
    setLive: (live: boolean) => void;
}

export interface PollingTransport<T> extends LiveTransport<T> {
    connect: (
        context: LiveContext,
        onUpdate: (update: T) => void,
        onMerged?: (target: ForumMergedInto) => void
    ) => PollingConnection;
}

/** A session that ended, a page that is gone or no longer allowed: asking again changes nothing */
const FINAL_STATUSES = [401, 403, 404, 410];

export function pollingTransport<T>(options: PollingOptions<T>): PollingTransport<T> {
    return {
        connect(context, onUpdate, onMerged): PollingConnection {
            const poller = createPoller({
                intervalMs: options.intervalMs,
                maxIntervalMs: options.maxIntervalMs ?? Math.min(options.intervalMs * 8, 300_000),
                visibility: options.visibility,
                random: options.random,
                run: async (): Promise<PollOutcome> => {
                    const answer = await options.request(context);
                    if (answer.ok) {
                        // 204: nothing new
                        if (answer.status !== 204 && answer.data !== null) {
                            onUpdate(answer.data);
                        }

                        return 'ok';
                    }

                    // 410: merged into another discussion, and the person may read where
                    if (answer.status === 410 && answer.mergedInto) {
                        onMerged?.(answer.mergedInto);
                    }

                    // Network failures (0), limits (429) and server errors wait longer
                    return FINAL_STATUSES.includes(answer.status) ? 'stop' : 'retry';
                },
            });
            poller.start();
            if (options.immediate === true) {
                poller.pollNow();
            }

            return { stop: poller.stop, refresh: poller.pollNow, setLive: poller.setLive };
        },
    };
}

/**
 * A real-time channel as a transport sees it (made by `useChannelFeed` while the component is set
 * up): whether its messages arrive now, and a way to hear them while the connection is open.
 */
export interface RealtimeFeed {
    /** True while messages arrive; false when real time is off, still connecting or lost */
    live: Readonly<Ref<boolean>>;
    /** Joins the channel and hears its messages until the returned function is called */
    open: (onMessage: (event: string, payload: RealtimePayload) => void) => () => void;
}

export interface RealtimeOptions<T> extends PollingOptions<T> {
    feed: RealtimeFeed;
    /**
     * Whether a message calls for asking now (every message does by default). It may also note
     * what the next question should carry (the posts said to have changed).
     */
    onMessage?: (event: string, payload: RealtimePayload, context: LiveContext) => boolean;
    /**
     * The shortest time between two questions that messages call for: messages that come closer
     * together are answered by one question at the end of the wait (none by default).
     */
    minGapMs?: number;
    now?: () => number;
}

/**
 * Listens to a real-time channel and asks only when a message says something changed, through the
 * same request as the consultation. Whenever the channel is not live (real time off, connecting,
 * lost or refused) it is the consultation, at its pace. Each time the channel turns live again it
 * asks once, for whatever happened while it was not.
 */
export function realtimeTransport<T>(options: RealtimeOptions<T>): LiveTransport<T> {
    const polling = pollingTransport<T>(options);
    const now = options.now ?? Date.now;

    return {
        connect(context, onUpdate, onMerged): LiveConnection {
            const connection = polling.connect(context, onUpdate, onMerged);
            // A transport that asked as it connected waits its gap before the catch-up
            let lastAsked = options.immediate === true ? now() : Number.NEGATIVE_INFINITY;
            let waiting: ReturnType<typeof setTimeout> | null = null;

            function ask(): void {
                const gap = options.minGapMs ?? 0;
                const due = lastAsked + gap - now();
                if (due > 0) {
                    // One question at the end of the wait answers every message until then
                    waiting ??= setTimeout(() => {
                        waiting = null;
                        ask();
                    }, due);

                    return;
                }

                lastAsked = now();
                connection.refresh();
            }

            const close = options.feed.open((event, payload) => {
                if (options.onMessage?.(event, payload, context) ?? true) {
                    ask();
                }
            });
            const stopWatching = watch(
                options.feed.live,
                (isLive, wasLive) => {
                    connection.setLive(isLive);
                    // Live from now on (just connected, or back after a gap): what came before
                    // was not heard
                    if (isLive && wasLive !== true) {
                        ask();
                    }
                },
                { immediate: true }
            );

            return {
                stop: (): void => {
                    stopWatching();
                    close();
                    if (waiting !== null) {
                        clearTimeout(waiting);
                        waiting = null;
                    }
                    connection.stop();
                },
                refresh: connection.refresh,
            };
        },
    };
}
