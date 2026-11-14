<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import ReplyBox from '../forum/ReplyBox.vue';
import NewPostsNotice from '../live/NewPostsNotice.vue';
import SubjectConversationThread from './SubjectConversationThread.vue';
import { useForumVisibility } from '../../composables/useForumVisibility';
import { useNewPosts } from '../../composables/useNewPosts';
import { sendJson, subjectConversationUrl } from '../../forum/api';
import { DISCUSSION_EVENTS, discussionChannel, useChannelFeed } from '../../live/channels';
import { liveNewPosts } from '../../live/transports';
import type { ForumPost, ForumSubjectConversation } from '../../types/forum';

/**
 * The conversation of a record (a game table, a campaign, an event), in the core's
 * `subject-discussion` slot of its page: its last posts, earlier ones on demand, and a reply
 * box (read only: no reply box, and a line leading to the forum while there are no posts; the
 * site chooses per type, and a type it hides answers no conversation). It is asked for once the page is drawn, so the page never waits for the forum: nothing
 * shows while it loads. A record without a discussion, or with one the reader may not read,
 * shows nothing; a failed request, a neutral notice that offers to try again.
 */
const props = defineProps<{
    subjectType: string;
    subjectId: string;
}>();

/** The forum's opening post (number 1) is never shown: it repeats the page */
const FIRST_SHOWN = 2;

type Answer = { conversation: ForumSubjectConversation | null };

const { t } = useI18n();
const headingId = useId();
// A forum in preview the reader may not see: nothing is asked, nothing is drawn
const forumVisible = useForumVisibility();

const conversation = ref<ForumSubjectConversation | null>(null);
const failed = ref(false);
const retrying = ref(false);
const earlier = ref<ForumPost[]>([]);
const earlierNames = ref<Record<string, string>>({});
const olderUrl = ref<string | null>(null);
const olderCount = ref(0);
const olderNext = ref(0);
const loadingOlder = ref(false);
const olderError = ref(false);
const loads = ref(0);

const reply = ref<InstanceType<typeof ReplyBox> | null>(null);
const sending = ref(false);
const replyError = ref<string | null>(null);

const posts = computed<ForumPost[]>(() => [...earlier.value, ...(conversation.value?.posts ?? [])]);
// New posts while the page is open, after the last one shown. The opening post is never one of
// them: nothing at or below the highest number the conversation had is asked for
// The discussion's real-time channel, once the conversation says which one it is
const channel = useChannelFeed(() => {
    const id = conversation.value?.discussion.id;

    return id === undefined ? null : discussionChannel(id);
}, DISCUSSION_EVENTS);
const live = useNewPosts({
    posts: () => posts.value,
    transport: liveNewPosts(() => conversation.value?.discussion.id ?? '', channel),
    enabled: () => conversation.value !== null && !conversation.value.discussion.isClosed,
    floor: () => conversation.value?.highestNumber ?? 0,
});

const mentionNames = computed(() => ({
    ...earlierNames.value,
    ...(conversation.value?.mentionNames ?? {}),
    ...live.mentionNames.value,
}));

/**
 * Reading moves the mark only while it is continuous: the mark already reaches the post before
 * the first one shown (the opening post counts as read, since the page holds what it says).
 */
const tracking = computed(() => {
    const loaded = conversation.value;
    const lowest = posts.value.find((post) => post.number !== null)?.number ?? null;
    if (loaded === null || loaded.readUrl === null || lowest === null) {
        return false;
    }

    return lowest <= FIRST_SHOWN || (loaded.lastReadNumber ?? 0) >= lowest - 1;
});

const readOnly = computed(() => conversation.value?.mode === 'read');

const closedNotice = computed(() => {
    const reason = conversation.value?.discussion.closedReason ?? null;

    return reason === null ? null : t(`forum.subjects.closed.${reason}`);
});

async function load(): Promise<void> {
    if (!forumVisible.value) {
        return;
    }

    const answer = await sendJson<Answer>(
        subjectConversationUrl(props.subjectType, props.subjectId),
        'GET'
    );
    // A failed request keeps whatever the page shows; with nothing shown yet, it says so
    if (!answer.ok) {
        failed.value = conversation.value === null;

        return;
    }

    failed.value = false;

    const loaded = answer.data?.conversation ?? null;
    conversation.value = loaded;
    earlier.value = [];
    earlierNames.value = {};
    olderUrl.value = loaded?.olderUrl ?? null;
    olderCount.value = loaded?.olderCount ?? 0;
    olderNext.value = loaded?.olderNext ?? 0;
    olderError.value = false;
    loads.value++;
    // What the server just sent is newer than anything that arrived before
    live.reset();
}

async function loadOlder(): Promise<void> {
    if (olderUrl.value === null || loadingOlder.value) {
        return;
    }

    loadingOlder.value = true;
    olderError.value = false;
    const answer = await sendJson<Answer>(olderUrl.value, 'GET');
    loadingOlder.value = false;

    const batch = answer.ok ? (answer.data?.conversation ?? null) : null;
    if (batch === null) {
        olderError.value = true;

        return;
    }

    earlier.value = [...batch.posts, ...earlier.value];
    earlierNames.value = { ...earlierNames.value, ...batch.mentionNames };
    olderUrl.value = batch.olderUrl;
    olderCount.value = batch.olderCount;
    olderNext.value = batch.olderNext;

    // Reading goes on from the first post that came in
    const first = batch.posts[0]?.number ?? null;
    if (first !== null) {
        await nextTick();
        document.getElementById(`post-${first}`)?.focus();
    }
}

async function retry(): Promise<void> {
    retrying.value = true;
    await load();
    retrying.value = false;
}

function onQuote(source: { postId: string; text: string }): void {
    reply.value?.quote(source);
    reply.value?.focus();
}

async function send(document: JSONContent): Promise<void> {
    const loaded = conversation.value;
    if (loaded === null) {
        return;
    }

    sending.value = true;
    replyError.value = null;
    const answer = await sendJson(loaded.reply.postUrl, 'POST', { document });
    sending.value = false;

    if (answer.ok) {
        reply.value?.clear();
        await load();

        return;
    }

    replyError.value =
        answer.status === 419 || answer.status === 401
            ? t('forum.editor.errors.session')
            : (answer.message ?? t('forum.posts.errors.save'));
}

onMounted(load);
watch(() => [props.subjectType, props.subjectId], load);
</script>

<template>
    <section v-if="conversation" :aria-labelledby="headingId" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-x-4">
            <h2 :id="headingId" class="text-lg font-semibold text-base-primary">
                {{ t('forum.subjects.title') }}
            </h2>
            <Link
                v-if="!readOnly || live.posts.value.length > 0"
                :href="conversation.discussion.url"
                class="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline"
            >
                {{ t('forum.subjects.viewAll') }}
            </Link>
        </div>

        <div v-if="olderUrl !== null && olderCount > 0" class="space-y-2">
            <button
                type="button"
                class="inline-flex min-h-[44px] items-center rounded-md border border-default px-4 text-sm font-medium text-base-primary hover:bg-muted disabled:opacity-60"
                :disabled="loadingOlder"
                :aria-busy="loadingOlder"
                @click="loadOlder"
            >
                {{ t('forum.subjects.older', { count: olderNext }, olderNext) }}
            </button>
            <p v-if="olderError" role="alert" class="text-sm text-red-600 dark:text-red-400">
                {{ t('forum.subjects.olderError') }}
            </p>
        </div>

        <SubjectConversationThread
            v-if="live.posts.value.length > 0"
            :key="`${loads}-${tracking}`"
            :posts="live.posts.value"
            :read-url="tracking ? conversation.readUrl : null"
            :last-read-number="conversation.lastReadNumber"
            :can-quote="conversation.reply.canPost"
            :editor="conversation.reply"
            :mentions-url="conversation.reply.mentionsUrl"
            :mention-names="mentionNames"
            @quote="onQuote"
            @changed="load"
        />
        <p
            v-else-if="readOnly"
            data-testid="subject-conversation-empty"
            class="flex flex-wrap items-center gap-x-2 text-sm text-base-secondary"
        >
            {{ t('forum.subjects.emptyReadOnlyMode') }}
            <span aria-hidden="true">·</span>
            <Link
                :href="conversation.discussion.url"
                class="inline-flex min-h-[44px] items-center font-medium text-primary hover:underline"
            >
                {{ t('forum.subjects.commentInForum') }}
            </Link>
        </p>
        <p v-else class="text-sm text-base-secondary">
            {{
                conversation.reply.canPost
                    ? t('forum.subjects.empty')
                    : t('forum.subjects.emptyReadOnly')
            }}
        </p>
        <!-- Always drawn: a live region must exist before its text changes to be announced -->
        <NewPostsNotice :posts="live.unseen.value" @acknowledge="live.acknowledge" />

        <ReplyBox
            v-if="!readOnly"
            ref="reply"
            :reply="conversation.reply"
            :draft-key="`forum-reply-${conversation.discussion.id}`"
            :is-closed="conversation.discussion.isClosed"
            :closed-notice="closedNotice"
            :label="t('forum.subjects.replyLabel')"
            :submit-label="t('forum.subjects.send')"
            :sign-in-suffix="t('forum.subjects.signInToComment')"
            :sending="sending"
            :error="replyError"
            @submit="send"
        />
    </section>

    <p
        v-else-if="failed"
        role="status"
        class="flex flex-wrap items-center gap-x-3 text-sm text-base-secondary"
    >
        {{ t('forum.subjects.loadError') }}
        <button
            type="button"
            class="inline-flex min-h-[44px] items-center font-medium text-primary hover:underline disabled:opacity-60"
            :disabled="retrying"
            @click="retry"
        >
            {{ t('forum.subjects.retry') }}
        </button>
    </p>
</template>
