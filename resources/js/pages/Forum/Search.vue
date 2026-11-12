<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import type { ForumSearchCategoryOption, ForumSearchResults } from '../../types/forum';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import ForumSearchForm from '../../components/forum/ForumSearchForm.vue';

const props = defineProps<{
    query: string;
    category: string | null;
    categories: ForumSearchCategoryOption[];
    searchUrl: string;
    /** The engine failed: the page says so instead of listing nothing */
    failed: boolean;
    /** null while there is nothing to search yet */
    results: ForumSearchResults | null;
}>();

const { t } = useI18n();

const breadcrumbs = [{ name: t('forum.title'), url: '/foro' }];

const heading = computed(() =>
    props.results
        ? t('forum.search.resultCount', { count: props.results.total }, props.results.total)
        : ''
);
</script>

<template>
    <div class="bg-surface shadow">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <ForumBreadcrumbs :items="breadcrumbs" />
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-base-primary">
                {{ t('forum.search.title') }}
            </h1>
            <ForumSearchForm
                class="mt-4"
                :action="searchUrl"
                :query="query"
                :category="category"
                :categories="categories"
            />
        </div>
    </div>

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
        <p v-if="failed" role="alert" class="rounded-md bg-muted p-4 text-base-primary">
            {{ t('forum.search.failed') }}
        </p>

        <EmptyState
            v-else-if="!results"
            icon="document"
            :title="t('forum.search.start')"
            :description="t('forum.search.startDescription')"
        />

        <template v-else>
            <h2 class="text-xl font-semibold text-base-primary" aria-live="polite">
                {{ heading }}
            </h2>

            <EmptyState
                v-if="results.items.length === 0"
                icon="document"
                :title="t('forum.search.none')"
                :description="t('forum.search.noneDescription')"
            />

            <ol
                v-else
                data-testid="forum-search-results"
                class="overflow-hidden rounded-lg border border-default bg-surface"
            >
                <li
                    v-for="item in results.items"
                    :key="item.url"
                    class="border-t border-default p-4 first:border-t-0"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span
                            class="rounded-full border border-default px-2 py-0.5 text-xs font-medium text-base-secondary"
                        >
                            {{
                                item.kind === 'post'
                                    ? t('forum.search.reply', { number: item.postNumber })
                                    : t('forum.search.discussion')
                            }}
                        </span>
                        <Link
                            :href="item.url"
                            class="min-w-0 rounded-sm text-base font-semibold break-words text-base-primary hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        >
                            {{ item.title }}
                        </Link>
                    </div>

                    <p
                        v-if="item.snippet.length > 0"
                        class="mt-1 text-sm break-words text-base-secondary"
                    >
                        <template v-for="(part, index) in item.snippet" :key="index"
                            ><mark
                                v-if="part.match"
                                class="rounded-sm bg-primary-light px-0.5 text-base-primary"
                                >{{ part.text }}</mark
                            ><template v-else>{{ part.text }}</template></template
                        >
                    </p>

                    <p
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-base-muted"
                    >
                        <Link
                            v-if="item.categoryName && item.categoryUrl"
                            :href="item.categoryUrl"
                            class="font-medium text-base-secondary hover:text-primary hover:underline"
                        >
                            {{ item.categoryName }}
                        </Link>
                        <template v-if="item.publishedLabel">
                            <span v-if="item.categoryName" aria-hidden="true">·</span>
                            <time :datetime="item.publishedAt ?? undefined">{{
                                item.publishedLabel
                            }}</time>
                        </template>
                    </p>
                </li>
            </ol>

            <p v-if="results.truncated" class="text-sm text-base-muted">
                {{ t('forum.search.truncated') }}
            </p>

            <nav
                v-if="results.prevUrl || results.nextUrl"
                :aria-label="t('forum.search.pages')"
                class="flex flex-wrap justify-between gap-3"
            >
                <Link
                    v-if="results.prevUrl"
                    :href="results.prevUrl"
                    rel="prev"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-muted px-4 text-sm font-medium text-base-primary hover:bg-neutral-200 dark:hover:bg-neutral-700"
                >
                    {{ t('forum.search.previous') }}
                </Link>
                <span v-else />
                <Link
                    v-if="results.nextUrl"
                    :href="results.nextUrl"
                    rel="next"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-muted px-4 text-sm font-medium text-base-primary hover:bg-neutral-200 dark:hover:bg-neutral-700"
                >
                    {{ t('forum.search.next') }}
                </Link>
            </nav>
        </template>
    </div>
</template>
