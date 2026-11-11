<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
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
</script>

<template>
    <div class="space-y-4">
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
