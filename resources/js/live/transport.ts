import type { JsonAnswer } from '../forum/api';
import type { ForumMergedInto } from '../types/forum';
import { createPoller, type PollOutcome, type PollerVisibility } from './poller';

/**
 * How a page learns what changed without reloading. Components only know this contract: they
 * hand a transport what they have (the context) and get updates back. The consultation of the
 * first phase is `pollingTransport`; the real-time one (#17) is another implementation of the
 * same contract, and no component changes.
 */
export interface LiveContext {
    /** The highest post number the page has: what changed is whatever comes after it */
    cursor: () => number;
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

/** A session that ended, a page that is gone or no longer allowed: asking again changes nothing */
const FINAL_STATUSES = [401, 403, 404, 410];

export function pollingTransport<T>(options: PollingOptions<T>): LiveTransport<T> {
    return {
        connect(context, onUpdate, onMerged): LiveConnection {
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

            return { stop: poller.stop, refresh: poller.pollNow };
        },
    };
}
