import {
    onBeforeUnmount,
    onMounted,
    ref,
    toValue,
    watch,
    type MaybeRefOrGetter,
    type Ref,
} from 'vue';
import type { LiveConnection, LiveTransport } from '../live/transport';
import type { ForumPulse } from '../types/forum';

export interface ForumPulseOptions {
    transport: LiveTransport<ForumPulse>;
    /** Without a session nothing is asked */
    enabled?: MaybeRefOrGetter<boolean>;
}

export interface ForumPulseState {
    /** How many followed discussions have posts the person did not read (up to the server's cap) */
    unreadFollowed: Ref<number>;
    /** The last post of each discussion the page asked about */
    lastPosts: Ref<Record<string, number>>;
    refresh: () => void;
}

/**
 * What changed in the forum while a page is open, for the menu and the lists. The same contract
 * as `useNewPosts`: consultation now, real time later.
 */
export function useForumPulse(options: ForumPulseOptions): ForumPulseState {
    const unreadFollowed = ref(0);
    const lastPosts = ref<Record<string, number>>({});
    let connection: LiveConnection | null = null;

    function connect(): void {
        if (connection !== null) {
            return;
        }

        connection = options.transport.connect({ cursor: () => 0 }, (update) => {
            unreadFollowed.value = update.unreadFollowed;
            lastPosts.value = update.discussions;
        });
    }

    function disconnect(): void {
        connection?.stop();
        connection = null;
        unreadFollowed.value = 0;
        lastPosts.value = {};
    }

    onMounted(() => {
        if (toValue(options.enabled) ?? true) {
            connect();
        }
    });
    watch(
        () => toValue(options.enabled) ?? true,
        (enabled) => (enabled ? connect() : disconnect())
    );
    onBeforeUnmount(disconnect);

    return { unreadFollowed, lastPosts, refresh: () => connection?.refresh() };
}
