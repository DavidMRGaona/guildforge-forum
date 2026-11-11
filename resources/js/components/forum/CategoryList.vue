<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { ForumCategoryCard } from '../../types/forum';
import CategoryIcon from './CategoryIcon.vue';

/** Categories as a list of cards, each with its subcategories as links. */
defineProps<{
    categories: ForumCategoryCard[];
    headingLevel?: 'h2' | 'h3';
}>();

const { t } = useI18n();
</script>

<template>
    <ul class="overflow-hidden rounded-lg border border-default bg-surface">
        <li
            v-for="category in categories"
            :key="category.id"
            class="flex flex-col gap-3 border-t border-default p-4 first:border-t-0 sm:flex-row sm:items-start sm:gap-4"
        >
            <div class="flex min-w-0 flex-1 gap-3">
                <CategoryIcon :name="category.icon" :color="category.color" />
                <div class="min-w-0 flex-1">
                    <component
                        :is="headingLevel ?? 'h3'"
                        class="text-base font-semibold text-base-primary"
                    >
                        <Link
                            :href="category.url"
                            class="rounded-sm hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        >
                            {{ category.name }}
                        </Link>
                    </component>
                    <p v-if="category.description" class="mt-1 text-sm text-base-secondary">
                        {{ category.description }}
                    </p>
                    <ul
                        v-if="category.children.length > 0"
                        class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm"
                        :aria-label="t('forum.categories.subcategoriesOf', { name: category.name })"
                    >
                        <li v-for="child in category.children" :key="child.id">
                            <Link
                                :href="child.url"
                                class="inline-flex min-h-[32px] items-center font-medium text-primary hover:underline"
                            >
                                {{ child.name }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>

            <dl
                class="flex shrink-0 gap-4 pl-[52px] text-sm text-base-secondary sm:w-28 sm:flex-col sm:gap-0 sm:pl-0 sm:text-right"
            >
                <div>
                    <dt class="sr-only">{{ t('forum.categories.discussions') }}</dt>
                    <dd>
                        {{
                            t(
                                'forum.categories.discussionCount',
                                { count: category.discussionCount },
                                category.discussionCount
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="sr-only">{{ t('forum.categories.posts') }}</dt>
                    <dd>
                        {{
                            t(
                                'forum.categories.postCount',
                                { count: category.postCount },
                                category.postCount
                            )
                        }}
                    </dd>
                </div>
            </dl>

            <div class="min-w-0 pl-[52px] text-sm sm:w-56 sm:shrink-0 sm:pl-0">
                <template v-if="category.lastPost">
                    <Link
                        :href="category.lastPost.url"
                        class="line-clamp-2 font-medium text-base-primary hover:text-primary hover:underline"
                    >
                        {{ category.lastPost.discussionTitle }}
                    </Link>
                    <p class="text-base-muted">
                        <span v-if="category.lastPost.authorName"
                            >{{ category.lastPost.authorName }} ·
                        </span>
                        <time :datetime="category.lastPost.at ?? undefined">{{
                            category.lastPost.atLabel
                        }}</time>
                    </p>
                </template>
                <p v-else class="text-base-muted">{{ t('forum.categories.empty') }}</p>
            </div>
        </li>
    </ul>
</template>
