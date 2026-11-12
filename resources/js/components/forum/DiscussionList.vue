<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/composables/useAuth';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useForumPulse } from '../../composables/useForumPulse';
import { PULSE_INTERVAL_MS, pollPulse } from '../../live/transports';
import type { ForumDiscussionList } from '../../types/forum';
import DiscussionListItem from './DiscussionListItem.vue';

/** Pinned discussions apart, then the others by last activity, with a link to the next page. */
const props = defineProps<{
    discussions: ForumDiscussionList;
    showCategory?: boolean;
    emptyDescription: string;
}>();

const { t } = useI18n();

const isEmpty = computed(
    () => props.discussions.pinned.length === 0 && props.discussions.items.length === 0
);

const { isAuthenticated } = useAuth();
const rows = computed(() => [...props.discussions.pinned, ...props.discussions.items]);

// Every minute, for people with a session, the list asks whether any of its discussions moved:
// the ones that did are marked, and the list is only redrawn when the reader asks
const pulse = useForumPulse({
    transport: pollPulse(() => rows.value.map((row) => row.id), PULSE_INTERVAL_MS, false),
    enabled: () => isAuthenticated.value && rows.value.length > 0,
});
const changedIds = computed(
    () =>
        new Set(
            rows.value
                .filter(
                    (row) =>
                        row.lastPostNumber !== null &&
                        (pulse.lastPosts.value[row.id] ?? 0) > row.lastPostNumber
                )
                .map((row) => row.id)
        )
);

function reload(): void {
    router.reload({ only: ['discussions'] });
}
</script>

<template>
    <div class="space-y-4">
        <!-- Always drawn: a live region must exist before its text changes to be announced -->
        <div
            role="status"
            aria-live="polite"
            :class="
                changedIds.size > 0
                    ? 'flex flex-wrap items-center gap-x-3 rounded-md border border-default bg-muted px-4 py-2 text-sm text-base-primary'
                    : 'sr-only'
            "
            data-testid="list-changes"
        >
            <template v-if="changedIds.size > 0">
                <span>{{
                    t('forum.live.listChanged', { count: changedIds.size }, changedIds.size)
                }}</span>
                <button
                    type="button"
                    class="inline-flex min-h-[44px] items-center font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    @click="reload"
                >
                    {{ t('forum.live.refreshList') }}
                </button>
            </template>
        </div>

        <EmptyState
            v-if="isEmpty"
            icon="document"
            :title="t('forum.discussions.empty')"
            :description="emptyDescription"
        />

        <template v-else>
            <section
                v-if="discussions.pinned.length > 0"
                :aria-label="t('forum.discussions.pinnedList')"
            >
                <ul class="overflow-hidden rounded-lg border border-default bg-surface">
                    <DiscussionListItem
                        v-for="discussion in discussions.pinned"
                        :key="discussion.id"
                        :discussion="discussion"
                        :changed="changedIds.has(discussion.id)"
                        :show-category="showCategory"
                    />
                </ul>
            </section>

            <ul
                v-if="discussions.items.length > 0"
                class="overflow-hidden rounded-lg border border-default bg-surface"
            >
                <DiscussionListItem
                    v-for="discussion in discussions.items"
                    :key="discussion.id"
                    :discussion="discussion"
                    :changed="changedIds.has(discussion.id)"
                    :show-category="showCategory"
                />
            </ul>
        </template>

        <nav
            v-if="discussions.nextUrl || discussions.firstUrl"
            :aria-label="t('forum.discussions.pages')"
            class="flex flex-wrap justify-between gap-3"
        >
            <Link
                v-if="discussions.firstUrl"
                :href="discussions.firstUrl"
                class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-muted px-4 text-sm font-medium text-base-primary hover:bg-neutral-200 dark:hover:bg-neutral-700"
            >
                {{ t('forum.discussions.newest') }}
            </Link>
            <span v-else />
            <Link
                v-if="discussions.nextUrl"
                :href="discussions.nextUrl"
                class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-muted px-4 text-sm font-medium text-base-primary hover:bg-neutral-200 dark:hover:bg-neutral-700"
            >
                {{ t('forum.discussions.older') }}
            </Link>
        </nav>
    </div>
</template>
