<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { EyeOff, Lock, MessageCircle, Pin } from '@lucide/vue';
import type { ForumDiscussionRow } from '../../types/forum';

/** One discussion of a list: title, where it is, who started it and its last post. */
const props = defineProps<{
    discussion: ForumDiscussionRow;
    showCategory?: boolean;
    /** The forum says it has newer posts than the ones this row was drawn with */
    changed?: boolean;
}>();

const { t } = useI18n();

const unread = computed(() => props.discussion.isUnread === true);
/** Signed-in readers resume where they left off */
const href = computed(() => props.discussion.resumeUrl ?? props.discussion.url);
</script>

<template>
    <li
        class="flex flex-col gap-2 border-t border-default p-4 first:border-t-0 sm:flex-row sm:items-center sm:gap-4"
    >
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span
                    v-if="unread"
                    class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-primary"
                    aria-hidden="true"
                />
                <Link
                    :href="href"
                    class="min-w-0 rounded-sm break-words text-base hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    :class="
                        unread
                            ? 'font-semibold text-base-primary'
                            : 'font-medium text-base-secondary'
                    "
                >
                    {{ discussion.title }}
                    <span v-if="unread" class="sr-only">
                        ({{
                            t(
                                'forum.discussions.unreadCount',
                                { count: discussion.unreadCount ?? 0 },
                                discussion.unreadCount ?? 0
                            )
                        }})
                    </span>
                </Link>
                <span
                    v-if="changed"
                    class="inline-flex items-center rounded-full bg-primary-light px-2 py-0.5 text-xs font-medium text-primary"
                    data-testid="discussion-changed"
                >
                    {{ t('forum.live.updated') }}
                </span>
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
            </div>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-base-muted">
                <span v-if="discussion.authorName">{{
                    t('forum.discussions.startedBy', { name: discussion.authorName })
                }}</span>
                <template v-if="showCategory && discussion.category">
                    <span aria-hidden="true">·</span>
                    <Link
                        :href="discussion.category.url"
                        class="font-medium text-base-secondary hover:text-primary hover:underline"
                    >
                        {{ discussion.category.name }}
                    </Link>
                </template>
                <span
                    v-for="topic in discussion.topics"
                    :key="topic.key"
                    class="rounded-full border border-default px-2 py-0.5 text-xs text-base-secondary"
                >
                    {{ topic.label }}
                </span>
            </p>
        </div>

        <div
            class="flex shrink-0 items-center gap-4 text-sm text-base-muted sm:w-64 sm:justify-end"
        >
            <span class="inline-flex items-center gap-1" :title="t('forum.discussions.replies')">
                <MessageCircle class="h-4 w-4" aria-hidden="true" />
                <span class="sr-only">{{ t('forum.discussions.replies') }}:</span>
                {{ discussion.replyCount }}
            </span>
            <span class="min-w-0 text-right">
                <Link
                    v-if="discussion.lastPost.url"
                    :href="discussion.lastPost.url"
                    class="hover:text-primary hover:underline"
                    :aria-label="t('forum.discussions.lastPostOf', { title: discussion.title })"
                >
                    <time :datetime="discussion.lastPost.at ?? undefined">{{
                        discussion.lastPost.atLabel
                    }}</time>
                </Link>
                <span v-if="discussion.lastPost.authorName" class="block truncate">
                    {{ discussion.lastPost.authorName }}
                </span>
            </span>
        </div>
    </li>
</template>
