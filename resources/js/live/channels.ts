import { computed, ref } from 'vue';
import {
    useRealtimeChannel,
    type RealtimeListener,
    type RealtimePayload,
} from '@/composables/useRealtimeChannel';
import type { RealtimeFeed } from './transport';

/** What the server sends on the forum's channels (ForumChannels on the server) */
export const DISCUSSION_EVENTS = ['post.created', 'post.changed', 'discussion.changed'] as const;
export const CATEGORY_EVENTS = ['discussion.activity'] as const;
export const ACCOUNT_EVENTS = ['pulse.changed'] as const;
export const CONVERSATION_EVENTS = ['entry.created', 'entry.changed'] as const;

/** The most categories a list listens to at once; one showing more keeps asking at its pace */
export const MAX_CATEGORY_CHANNELS = 5;

export const discussionChannel = (id: string): string => `forum.discussion.${id.toLowerCase()}`;
export const categoryChannel = (id: string): string => `forum.category.${id.toLowerCase()}`;
export const accountChannel = (id: string): string => `forum.account.${id.toLowerCase()}`;
export const conversationChannel = (id: string): string => `forum.conversation.${id.toLowerCase()}`;

export interface ChannelFeed extends RealtimeFeed {
    /** Sends a client event to the others on the channel; false when it is not live */
    whisper: (event: string, data: Record<string, unknown>) => boolean;
    /** Hears the others' client events; returns what stops it */
    onWhisper: (event: string, listener: RealtimeListener) => () => void;
}

type MessageHandler = (event: string, payload: RealtimePayload) => void;

/** Listeners for the events that hand each message to whoever opened the feed */
function relay(
    events: readonly string[],
    target: () => MessageHandler | null
): Record<string, RealtimeListener> {
    return Object.fromEntries(
        events.map((event) => [
            event,
            (payload: RealtimePayload): void => target()?.(event, payload),
        ])
    );
}

/**
 * One real-time channel for a transport, made while the component is set up (the core's
 * `useRealtimeChannel` needs it). The channel is only joined while a transport holds it open, so
 * a page that does not follow anything (not its last page, no session) never subscribes.
 */
export function useChannelFeed(
    channel: () => string | null,
    events: readonly string[]
): ChannelFeed {
    const opened = ref(false);
    let handler: MessageHandler | null = null;

    const realtime = useRealtimeChannel(
        () => (opened.value ? channel() : null),
        relay(events, () => handler)
    );

    return {
        live: realtime.live,
        open(onMessage): () => void {
            handler = onMessage;
            opened.value = true;

            return () => {
                if (handler === onMessage) {
                    handler = null;
                    opened.value = false;
                }
            };
        },
        whisper: realtime.whisper,
        onWhisper: realtime.onWhisper,
    };
}

/**
 * Several channels of one kind as one feed (the categories a list shows), up to `max` of them:
 * it is live only while every one of them is, so a list never misses one of its categories. With
 * more than `max`, or none, it is never live and the list keeps asking at its pace.
 */
export function useChannelFeeds(
    channels: () => string[],
    events: readonly string[],
    max: number = MAX_CATEGORY_CHANNELS
): RealtimeFeed {
    const opened = ref(false);
    let handler: MessageHandler | null = null;

    const names = computed(() => [...new Set(channels())]);
    const fits = computed(() => names.value.length > 0 && names.value.length <= max);
    const listeners = relay(events, () => handler);
    const subscriptions = Array.from({ length: max }, (_, index) =>
        useRealtimeChannel(
            () => (opened.value && fits.value ? (names.value[index] ?? null) : null),
            listeners
        )
    );

    return {
        live: computed(
            () =>
                fits.value &&
                subscriptions
                    .slice(0, names.value.length)
                    .every((subscription) => subscription.live.value)
        ),
        open(onMessage): () => void {
            handler = onMessage;
            opened.value = true;

            return () => {
                if (handler === onMessage) {
                    handler = null;
                    opened.value = false;
                }
            };
        },
    };
}
