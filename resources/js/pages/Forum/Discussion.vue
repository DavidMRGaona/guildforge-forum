<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { EyeOff, Lock, Pin } from '@lucide/vue';
import { useAuth } from '@/composables/useAuth';
import FollowSelect from '../../components/forum/FollowSelect.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import NewPostsNotice from '../../components/live/NewPostsNotice.vue';
import PostItem from '../../components/forum/PostItem.vue';
import ReplyBox from '../../components/forum/ReplyBox.vue';
import DiscussionModeration from '../../components/moderation/DiscussionModeration.vue';
import { useNewPosts } from '../../composables/useNewPosts';
import { useReadTracker } from '../../composables/useReadTracker';
import { pollNewPosts } from '../../live/transports';
import { sendJson } from '../../forum/api';
import type {
    ForumConversation,
    ForumDiscussionModeration,
    ForumPagination,
    ForumPost,
    ForumReplySettings,
} from '../../types/forum';

const props = defineProps<{
    discussion: ForumConversation;
    posts: ForumPost[];
    pagination: ForumPagination;
    focusNumber: number | null;
    lastReadNumber: number | null;
    mentionNames: Record<string, string>;
    reply: ForumReplySettings;
    readUrl: string | null;
    moderation: ForumDiscussionModeration | null;
}>();

const { t } = useI18n();
const { user } = useAuth();

const list = ref<HTMLElement | null>(null);
const editor = ref<InstanceType<typeof ReplyBox> | null>(null);
const sending = ref(false);
const replyError = ref<string | null>(null);

const breadcrumbs = computed(() => [
    { name: t('forum.title'), url: '/foro' },
    ...(props.discussion.category.parent ? [props.discussion.category.parent] : []),
    { name: props.discussion.category.name, url: props.discussion.category.url },
]);

const isLastPage = computed(() => props.pagination.nextUrl === null);

const tracker = useReadTracker({
    readUrl: props.readUrl,
    lastReadNumber: props.lastReadNumber,
    container: list,
    // The address follows the post at the top of the screen, without a new history entry
    onTopPost: (url) => {
        if (window.location.pathname !== url) {
            window.history.replaceState(window.history.state, '', url);
        }
    },
});

// New posts after the last one, while the page is open: only where the end of the discussion
// is on the page (the last one), so what comes in goes right after what the reader has
const live = useNewPosts({
    posts: () => props.posts,
    transport: pollNewPosts(props.discussion.id),
    enabled: isLastPage,
});
const allMentionNames = computed(() => ({ ...props.mentionNames, ...live.mentionNames.value }));

// The tracker watches the posts that arrive too, and counts them read only once they were seen
watch(
    () => live.posts.value.length,
    () => void nextTick(() => tracker.refresh())
);

onMounted(() => {
    if (props.focusNumber === null) {
        return;
    }

    void nextTick(() => {
        const target = document.getElementById(`post-${props.focusNumber}`);
        target?.scrollIntoView({ block: 'start' });
        target?.focus({ preventScroll: true });
    });
});

function onQuote(source: { postId: string; text: string }): void {
    editor.value?.quote(source);
    editor.value?.focus();
}

function refresh(): void {
    router.reload({
        only: ['posts', 'mentionNames'],
        // What the page holds is now the server's: what came in afterwards is asked for again
        onSuccess: () => live.reset(),
    });
}

async function send(document: JSONContent, characterId: string | null): Promise<void> {
    sending.value = true;
    replyError.value = null;
    const answer = await sendJson<{ post: { number: number | null } }>(
        props.reply.postUrl,
        'POST',
        { document, character: characterId }
    );
    sending.value = false;

    if (answer.ok) {
        editor.value?.clear();
        const number = answer.data?.post.number ?? null;
        router.visit(
            number === null || number === 1
                ? props.discussion.url
                : `${props.discussion.url}/${number}`
        );

        return;
    }

    replyError.value =
        answer.status === 419 || answer.status === 401
            ? t('forum.editor.errors.session')
            : (answer.message ?? t('forum.posts.errors.save'));
}
</script>

<template>
    <div class="bg-surface shadow">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <ForumBreadcrumbs :items="breadcrumbs" />
                <div v-if="moderation" class="flex flex-wrap items-center gap-1">
                    <Link
                        :href="moderation.queueUrl"
                        class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                        >{{ t('forum.moderation.queueLink') }}</Link
                    >
                    <DiscussionModeration :discussion="discussion" :moderation="moderation" />
                </div>
            </div>
            <h1
                class="mt-2 text-2xl font-bold tracking-tight break-words text-base-primary sm:text-3xl"
            >
                {{ discussion.title }}
            </h1>
            <p
                v-if="discussion.isAwaitingApproval"
                class="mt-3 rounded-md border border-default bg-muted px-3 py-2 text-sm text-base-secondary"
                data-testid="discussion-awaiting-approval"
            >
                {{ t('forum.moderation.awaitingApproval') }}
            </p>
            <div
                v-if="
                    discussion.isPinned ||
                    discussion.isClosed ||
                    discussion.isHidden ||
                    discussion.topics.length > 0
                "
                class="mt-3 flex flex-wrap items-center gap-2 text-sm"
            >
                <span
                    v-if="discussion.isPinned"
                    class="inline-flex items-center gap-1 rounded-full bg-primary-light px-2 py-0.5 text-xs font-medium text-primary"
                >
                    <Pin class="h-3 w-3" aria-hidden="true" />
                    {{ t('forum.discussions.pinned') }}
                </span>
                <span
                    v-if="discussion.isClosed"
                    class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-base-secondary"
                >
                    <Lock class="h-3 w-3" aria-hidden="true" />
                    {{ t('forum.discussions.closed') }}
                </span>
                <span
                    v-if="discussion.isHidden"
                    class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-base-secondary"
                >
                    <EyeOff class="h-3 w-3" aria-hidden="true" />
                    {{ t('forum.discussions.hidden') }}
                </span>
                <span
                    v-for="topic in discussion.topics"
                    :key="topic.key"
                    class="rounded-full border border-default px-2 py-0.5 text-xs text-base-secondary"
                >
                    {{ topic.label }}
                </span>
            </div>
            <FollowSelect
                v-if="discussion.follow"
                class="mt-4"
                :follow="discussion.follow"
                :discussion-id="discussion.id"
            />
        </div>
    </div>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <nav
            v-if="pagination.pageCount > 1"
            :aria-label="t('forum.posts.pagesTop')"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
        >
            <div class="flex gap-2">
                <Link
                    v-if="pagination.firstUrl"
                    :href="pagination.firstUrl"
                    class="forum-page-link"
                    >{{ t('forum.posts.first') }}</Link
                >
                <Link
                    v-if="pagination.previousUrl"
                    :href="pagination.previousUrl"
                    class="forum-page-link"
                    rel="prev"
                    >{{ t('forum.posts.previous') }}</Link
                >
            </div>
            <span class="text-base-secondary">{{
                t('forum.posts.pageOf', { page: pagination.page, count: pagination.pageCount })
            }}</span>
            <div class="flex gap-2">
                <Link
                    v-if="pagination.nextUrl"
                    :href="pagination.nextUrl"
                    class="forum-page-link"
                    rel="next"
                    >{{ t('forum.posts.next') }}</Link
                >
                <Link
                    v-if="pagination.lastUrl"
                    :href="pagination.lastUrl"
                    class="forum-page-link"
                    >{{ t('forum.posts.last') }}</Link
                >
            </div>
        </nav>

        <div ref="list" class="space-y-4">
            <PostItem
                v-for="post in live.posts.value"
                :key="post.id"
                :post="post"
                :focused="post.number !== null && post.number === focusNumber"
                :can-quote="reply.canPost"
                :editor="reply"
                :characters="reply.characters ?? null"
                :mentions-url="reply.mentionsUrl"
                :mention-names="allMentionNames"
                :hidden-reason-max="moderation?.limits.hiddenReasonMax"
                :reason-max="moderation?.limits.reasonMax"
                :suspend-url="moderation?.suspendUrl ?? null"
                :viewer-id="user?.id ?? null"
                @quote="onQuote"
                @changed="refresh"
            />
            <NewPostsNotice :posts="live.unseen.value" @acknowledge="live.acknowledge" />
        </div>

        <nav
            v-if="pagination.pageCount > 1"
            :aria-label="t('forum.posts.pagesBottom')"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
        >
            <Link
                v-if="pagination.previousUrl"
                :href="pagination.previousUrl"
                class="forum-page-link"
                rel="prev"
                >{{ t('forum.posts.previous') }}</Link
            >
            <span v-else />
            <span class="text-base-secondary">{{
                t('forum.posts.pageOf', { page: pagination.page, count: pagination.pageCount })
            }}</span>
            <Link
                v-if="pagination.nextUrl"
                :href="pagination.nextUrl"
                class="forum-page-link"
                rel="next"
                >{{ t('forum.posts.next') }}</Link
            >
            <span v-else />
        </nav>

        <section aria-labelledby="forum-reply" class="space-y-3">
            <h2 id="forum-reply" class="text-lg font-semibold text-base-primary">
                {{ t('forum.posts.reply') }}
            </h2>

            <ReplyBox
                ref="editor"
                :reply="reply"
                :draft-key="`forum-reply-${discussion.id}`"
                :is-closed="discussion.isClosed"
                :is-awaiting-approval="discussion.isAwaitingApproval"
                :label="t('forum.posts.replyLabel')"
                :submit-label="t('forum.posts.send')"
                :sign-in-suffix="t('forum.posts.signInToReply')"
                :sending="sending"
                :error="replyError"
                @submit="send"
            >
                <template v-if="!isLastPage" #intro>
                    <p class="text-sm text-base-secondary">
                        {{ t('forum.posts.replyGoesLast') }}
                    </p>
                </template>
            </ReplyBox>
        </section>
    </div>
</template>

<style scoped>
.forum-page-link {
    display: inline-flex;
    min-height: 44px;
    align-items: center;
    border-radius: 0.375rem;
    padding: 0 0.75rem;
    font-weight: 500;
    color: var(--color-primary-link);
}

.forum-page-link:hover {
    text-decoration: underline;
}
</style>
