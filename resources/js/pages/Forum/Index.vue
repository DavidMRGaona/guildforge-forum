<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus } from '@lucide/vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import type { ForumCategoryCard, ForumDiscussionList, ForumListFilters } from '../../types/forum';
import CategoryList from '../../components/forum/CategoryList.vue';
import DiscussionFilters from '../../components/forum/DiscussionFilters.vue';
import DiscussionList from '../../components/forum/DiscussionList.vue';
import MarkReadButton from '../../components/forum/MarkReadButton.vue';

defineProps<{
    categories: ForumCategoryCard[];
    discussions: ForumDiscussionList;
    filters: ForumListFilters;
    canStart: boolean;
    newDiscussionUrl: string;
    markReadUrl: string | null;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="bg-surface shadow">
        <div
            class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-8 sm:flex-row sm:items-end sm:justify-between sm:px-6 lg:px-8"
        >
            <div class="min-w-0">
                <h1 class="text-3xl font-bold tracking-tight text-base-primary">
                    {{ t('forum.title') }}
                </h1>
                <p class="mt-2 text-lg text-base-secondary">{{ t('forum.description') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <MarkReadButton
                    v-if="markReadUrl"
                    :url="markReadUrl"
                    :label="t('forum.reads.markForumRead')"
                />
                <Link
                    v-if="canStart"
                    :href="newDiscussionUrl"
                    class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    {{ t('forum.discussions.new') }}
                </Link>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-7xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
        <section aria-labelledby="forum-categories">
            <h2 id="forum-categories" class="mb-4 text-xl font-semibold text-base-primary">
                {{ t('forum.categories.title') }}
            </h2>
            <CategoryList v-if="categories.length > 0" :categories="categories" />
            <EmptyState
                v-else
                icon="book"
                :title="t('forum.categories.none')"
                :description="t('forum.categories.noneDescription')"
            />
        </section>

        <section aria-labelledby="forum-recent" class="space-y-4">
            <h2 id="forum-recent" class="text-xl font-semibold text-base-primary">
                {{ t('forum.discussions.recent') }}
            </h2>
            <DiscussionFilters :filters="filters" />
            <DiscussionList
                :discussions="discussions"
                show-category
                :empty-description="
                    filters.unread
                        ? t('forum.discussions.noUnread')
                        : t('forum.discussions.emptyForum')
                "
            />
        </section>
    </div>
</template>
