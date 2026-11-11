<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus } from '@lucide/vue';
import type {
    ForumCategoryCard,
    ForumCategoryHeader,
    ForumDiscussionList,
    ForumListFilters,
} from '../../types/forum';
import CategoryFollowButton from '../../components/forum/CategoryFollowButton.vue';
import CategoryIcon from '../../components/forum/CategoryIcon.vue';
import CategoryList from '../../components/forum/CategoryList.vue';
import DiscussionFilters from '../../components/forum/DiscussionFilters.vue';
import DiscussionList from '../../components/forum/DiscussionList.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import MarkReadButton from '../../components/forum/MarkReadButton.vue';
import SpaceNotices from '../../components/spaces/SpaceNotices.vue';

const props = defineProps<{
    category: ForumCategoryHeader;
    subcategories: ForumCategoryCard[];
    discussions: ForumDiscussionList;
    filters: ForumListFilters;
    canStart: boolean;
    newDiscussionUrl: string;
    markReadUrl: string | null;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { name: t('forum.title'), url: '/foro' },
    ...(props.category.parent ? [props.category.parent] : []),
]);
</script>

<template>
    <div class="bg-surface shadow">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <ForumBreadcrumbs :items="breadcrumbs" />
            <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 gap-3">
                    <CategoryIcon :name="category.icon" :color="category.color" />
                    <div class="min-w-0">
                        <h1 class="text-3xl font-bold tracking-tight break-words text-base-primary">
                            {{ category.name }}
                        </h1>
                        <p v-if="category.description" class="mt-2 text-lg text-base-secondary">
                            {{ category.description }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <CategoryFollowButton v-if="category.follow" :follow="category.follow" />
                    <MarkReadButton
                        v-if="markReadUrl"
                        :url="markReadUrl"
                        :label="t('forum.reads.markCategoryRead', { name: category.name })"
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
            <SpaceNotices
                class="mt-4"
                :is-publication-space="category.isPublicationSpace"
                :read-only="category.readOnly"
            />
        </div>
    </div>

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <section v-if="subcategories.length > 0" aria-labelledby="forum-subcategories">
            <h2 id="forum-subcategories" class="mb-4 text-xl font-semibold text-base-primary">
                {{ t('forum.categories.subcategories') }}
            </h2>
            <CategoryList :categories="subcategories" />
        </section>

        <section aria-labelledby="forum-discussions" class="space-y-4">
            <h2 id="forum-discussions" class="text-xl font-semibold text-base-primary">
                {{ t('forum.discussions.title') }}
            </h2>
            <DiscussionFilters :filters="filters" />
            <DiscussionList
                :discussions="discussions"
                :show-category="subcategories.length > 0"
                :empty-description="
                    filters.following
                        ? t('forum.discussions.noFollowed')
                        : filters.unread
                          ? t('forum.discussions.noUnread')
                          : t('forum.discussions.emptyCategory')
                "
            />
        </section>
    </div>
</template>
