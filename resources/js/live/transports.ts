import { discussionUpdatesUrl, forumUpdatesUrl, sendJson } from '../forum/api';
import type { ForumNewPosts, ForumPulse } from '../types/forum';
import { pollingTransport, type LiveTransport } from './transport';

/** How often an open discussion asks for new posts */
export const POSTS_INTERVAL_MS = 20_000;

/** How often the menu and the lists ask for what changed */
export const PULSE_INTERVAL_MS = 60_000;

/** The most unread followed discussions the server counts (`ForumPulse::UNREAD_CAP`) */
export const PULSE_UNREAD_CAP = 100;

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
