<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { EyeOff, Lock, Pin } from '@lucide/vue';
import { useAuth } from '@/composables/useAuth';
import { useRoutes } from '@/composables/useRoutes';
import PostEditor from '../../components/editor/PostEditor.vue';
import FollowSelect from '../../components/forum/FollowSelect.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import PostItem from '../../components/forum/PostItem.vue';
import { useReadTracker } from '../../composables/useReadTracker';
import { sendJson } from '../../forum/api';
import type {
    ForumConversation,
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
}>();

const { t } = useI18n();
const { isAuthenticated, mustVerifyEmail } = useAuth();
const routes = useRoutes();

const list = ref<HTMLElement | null>(null);
const editor = ref<InstanceType<typeof PostEditor> | null>(null);
const sending = ref(false);
const replyError = ref<string | null>(null);

const breadcrumbs = computed(() => [
    { name: t('forum.title'), url: '/foro' },
    ...(props.discussion.category.parent ? [props.discussion.category.parent] : []),
    { name: props.discussion.category.name, url: props.discussion.category.url },
]);

const isLastPage = computed(() => props.pagination.nextUrl === null);

/** Why there is no reply box, when there is none */
const noReplyReason = computed<string | null>(() => {
    if (props.reply.canPost) {
        return null;
    }
    if (!isAuthenticated.value) {
        return 'guest';
    }
    if (mustVerifyEmail.value) {
        return 'verify';
    }

    return props.discussion.isClosed ? 'closed' : 'forbidden';
});

useReadTracker({
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
    router.reload({ only: ['posts', 'mentionNames'] });
}

async function send(document: JSONContent): Promise<void> {
    sending.value = true;
    replyError.value = null;
    const answer = await sendJson<{ post: { number: number | null } }>(
        props.reply.postUrl,
        'POST',
        { document }
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
            <ForumBreadcrumbs :items="breadcrumbs" />
            <h1
                class="mt-2 text-2xl font-bold tracking-tight break-words text-base-primary sm:text-3xl"
            >
                {{ discussion.title }}
            </h1>
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
                v-for="post in posts"
                :key="post.id"
                :post="post"
                :focused="post.number !== null && post.number === focusNumber"
                :can-quote="reply.canPost"
                :editor="reply"
                :mentions-url="reply.mentionsUrl"
                :mention-names="mentionNames"
                @quote="onQuote"
                @changed="refresh"
            />
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

            <template v-if="reply.canPost">
                <p v-if="!isLastPage" class="text-sm text-base-secondary">
                    {{ t('forum.posts.replyGoesLast') }}
                </p>
                <PostEditor
                    ref="editor"
                    :label="t('forum.posts.replyLabel')"
                    :draft-key="`forum-reply-${discussion.id}`"
                    :mentions-url="reply.mentionsUrl"
                    :upload-url="reply.uploadUrl"
                    :max-images="reply.maxImages"
                    :image-types="reply.imageTypes"
                    :submit-label="t('forum.posts.send')"
                    :submitting="sending"
                    :error="replyError"
                    @submit="send"
                />
            </template>

            <p
                v-else
                class="rounded-lg border border-default bg-surface p-4 text-sm text-base-secondary"
            >
                <template v-if="noReplyReason === 'guest'">
                    <Link
                        :href="routes.auth.login"
                        class="font-medium text-primary hover:underline"
                        >{{ t('forum.posts.signIn') }}</Link
                    >
                    {{ t('forum.posts.signInToReply') }}
                </template>
                <template v-else-if="noReplyReason === 'verify'">{{
                    t('forum.posts.verifyToReply')
                }}</template>
                <template v-else-if="noReplyReason === 'closed'">{{
                    t('forum.posts.closedNotice')
                }}</template>
                <template v-else>{{ t('forum.posts.cannotReply') }}</template>
            </p>
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
