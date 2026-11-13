import { discussionUpdatesUrl, forumUpdatesUrl, sendJson } from '../forum/api';
import type { ForumNewPosts, ForumPulse } from '../types/forum';
import type { RealtimePayload } from '@/composables/useRealtimeChannel';
import {
    pollingTransport,
    realtimeTransport,
    type LiveTransport,
    type RealtimeFeed,
} from './transport';

/** How often an open discussion asks for new posts */
export const POSTS_INTERVAL_MS = 20_000;

/** How often the menu and the lists ask for what changed */
export const PULSE_INTERVAL_MS = 60_000;

/** The most unread followed discussions the server counts (`ForumPulse::UNREAD_CAP`) */
export const PULSE_UNREAD_CAP = 100;

/**
 * The shortest time between two questions for the forum's summary that real-time messages call
 * for: the menu and a list share a limit of 20 a minute, so each asks at most 6 times a minute.
 */
export const PULSE_MIN_GAP_MS = 10_000;

/** Changed posts one question carries (`NewPostsReader::MAX`) */
export const MAX_CHANGED_POSTS = 50;

/** A post number from a message, which comes from outside the page: anything else is no number */
function postNumber(payload: RealtimePayload): number | null {
    const value = payload.number;

    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : null;
}

/** New posts of a discussion by consultation: the posts after the page's last one */
export function pollNewPosts(
    discussionId: string | (() => string),
    intervalMs: number = POSTS_INTERVAL_MS
): LiveTransport<ForumNewPosts> {
    return pollingTransport<ForumNewPosts>({
        intervalMs,
        request: (context) =>
            sendJson<ForumNewPosts>(
                discussionUpdatesUrl(
                    typeof discussionId === 'string' ? discussionId : discussionId(),
                    context.cursor()
                ),
                'GET'
            ),
    });
}

/** The forum's summary by consultation; `watching` gives the discussions a list shows now */
export function pollPulse(
    watching: () => string[] = () => [],
    intervalMs: number = PULSE_INTERVAL_MS,
    immediate: boolean = true
): LiveTransport<ForumPulse> {
    return pollingTransport<ForumPulse>({
        intervalMs,
        // The menu's mark is there from the first page, not a minute later; a list that was just
        // drawn has nothing to compare yet
        immediate,
        request: () => sendJson<ForumPulse>(forumUpdatesUrl(watching()), 'GET'),
    });
}

/**
 * New and changed posts of a discussion through its real-time channel, and by consultation
 * whenever the channel is not live. A message only says which post: the page asks for it through
 * the usual request, which draws it for the reader (moderation, hidden rolls) and checks access.
 */
export function liveNewPosts(
    discussionId: string | (() => string),
    feed: RealtimeFeed,
    intervalMs: number = POSTS_INTERVAL_MS
): LiveTransport<ForumNewPosts> {
    const changed = new Set<number>();

    return realtimeTransport<ForumNewPosts>({
        intervalMs,
        feed,
        request: async (context) => {
            const asked = [...changed].slice(0, MAX_CHANGED_POSTS);
            for (const number of asked) {
                changed.delete(number);
            }

            const answer = await sendJson<ForumNewPosts>(
                discussionUpdatesUrl(
                    typeof discussionId === 'string' ? discussionId : discussionId(),
                    context.cursor(),
                    asked
                ),
                'GET'
            );
            // Not answered: they go with the next question
            if (!answer.ok) {
                for (const number of asked) {
                    changed.add(number);
                }
            }

            return answer;
        },
        onMessage: (event, payload, context) => {
            const number = postNumber(payload);
            if (event === 'post.created') {
                // A post the page has already (its own reply) needs no question
                return number === null || number > context.cursor();
            }
            if (event === 'post.changed') {
                if (number === null || !(context.holds?.(number) ?? false)) {
                    return false;
                }
                changed.add(number);

                return true;
            }

            // The discussion changed as a whole: asking tells a merge or a lost access too
            return true;
        },
    });
}

/**
 * The forum's summary through real-time channels (the person's own, or the categories of a list),
 * and by consultation whenever they are not live. `wants` says whether a message concerns what the
 * page shows; the summary itself always comes from the usual request.
 */
export function livePulse(
    feed: RealtimeFeed,
    watching: () => string[] = () => [],
    options: {
        intervalMs?: number;
        immediate?: boolean;
        wants?: (event: string, payload: RealtimePayload) => boolean;
    } = {}
): LiveTransport<ForumPulse> {
    return realtimeTransport<ForumPulse>({
        intervalMs: options.intervalMs ?? PULSE_INTERVAL_MS,
        immediate: options.immediate ?? true,
        feed,
        minGapMs: PULSE_MIN_GAP_MS,
        request: () => sendJson<ForumPulse>(forumUpdatesUrl(watching()), 'GET'),
        onMessage: (event, payload) => options.wants?.(event, payload) ?? true,
    });
}
