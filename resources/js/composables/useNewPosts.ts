import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    toValue,
    watch,
    type ComputedRef,
    type MaybeRefOrGetter,
    type Ref,
} from 'vue';
import type { LiveConnection, LiveTransport } from '../live/transport';
import type { ForumMergedInto, ForumNewPosts, ForumPost } from '../types/forum';

export interface NewPostsOptions {
    /** The posts the page drew */
    posts: MaybeRefOrGetter<ForumPost[]>;
    /** Where the news come from: consultation today, real time later (same contract) */
    transport: LiveTransport<ForumNewPosts>;
    /** Whether to follow the discussion: only where its end is on the page */
    enabled?: MaybeRefOrGetter<boolean>;
    /**
     * Posts at or below this number are never asked for, whatever the page holds. A page that
     * leaves some out on purpose (a record's page never shows the opening post) says so here.
     */
    floor?: MaybeRefOrGetter<number>;
}

export interface NewPosts {
    /** The page's posts with the new ones at the end (a later version of a post replaces it) */
    posts: ComputedRef<ForumPost[]>;
    mentionNames: Ref<Record<string, string>>;
    /** The posts that came in since the reader last acknowledged them, in order */
    unseen: ComputedRef<ForumPost[]>;
    /** The reader saw or jumped to them: the notice goes away */
    acknowledge: () => void;
    /** Where the discussion went, once it was merged into another (the page stops following it) */
    mergedInto: Ref<ForumMergedInto | null>;
    /** The page learned it another way (a reply refused because of it) */
    markMerged: (target: ForumMergedInto) => void;
    /** Forgets the new posts (the page reloaded its own) and asks again at once */
    reset: () => void;
}

/**
 * Follows a discussion while it is open: the posts that arrive go after the page's own, and the
 * ones that arrived since the reader last looked are kept apart for the «new posts» notice.
 * Arriving changes nothing about what the reader has read: only the read tracker moves that mark,
 * and only for what was on screen.
 */
export function useNewPosts(options: NewPostsOptions): NewPosts {
    const arrived = ref<ForumPost[]>([]);
    const unseenIds = ref<string[]>([]);
    const mentionNames = ref<Record<string, string>>({});
    const mergedInto = ref<ForumMergedInto | null>(null);
    let connection: LiveConnection | null = null;

    const base = computed(() => toValue(options.posts));

    const posts = computed<ForumPost[]>(() => {
        const replacements = new Map(arrived.value.map((post) => [post.id, post]));
        const known = new Set(base.value.map((post) => post.id));

        return [
            ...base.value.map((post) => replacements.get(post.id) ?? post),
            ...arrived.value.filter((post) => !known.has(post.id)),
        ];
    });

    const cursor = computed(() =>
        posts.value.reduce(
            (highest, post) => Math.max(highest, post.number ?? 0),
            toValue(options.floor) ?? 0
        )
    );

    const unseen = computed(() => {
        const ids = new Set(unseenIds.value);

        return posts.value.filter((post) => ids.has(post.id));
    });

    function receive(update: ForumNewPosts): void {
        const known = new Set(posts.value.map((post) => post.id));
        const fresh = update.posts.filter((post) => !known.has(post.id));

        const incoming = new Map(update.posts.map((post) => [post.id, post]));
        arrived.value = [
            ...arrived.value.map((post) => incoming.get(post.id) ?? post),
            ...update.posts.filter((post) => !arrived.value.some((have) => have.id === post.id)),
        ];
        mentionNames.value = { ...mentionNames.value, ...update.mentionNames };
        unseenIds.value = [...unseenIds.value, ...fresh.map((post) => post.id)];

        // A batch is capped: the rest is asked for right away, not a whole interval later
        if (update.more) {
            connection?.refresh();
        }
    }

    function markMerged(target: ForumMergedInto): void {
        mergedInto.value = target;
        disconnect();
    }

    function connect(): void {
        if (connection !== null || mergedInto.value !== null) {
            return;
        }

        connection = options.transport.connect({ cursor: () => cursor.value }, receive, markMerged);
    }

    function disconnect(): void {
        connection?.stop();
        connection = null;
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

    return {
        posts,
        mentionNames,
        unseen,
        mergedInto,
        markMerged,
        acknowledge: (): void => {
            unseenIds.value = [];
        },
        reset: (): void => {
            arrived.value = [];
            unseenIds.value = [];
            connection?.refresh();
        },
    };
}
