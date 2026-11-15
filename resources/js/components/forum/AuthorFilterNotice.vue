<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Funnel } from '@lucide/vue';
import type { ForumAuthorFilter } from '../../types/forum';

/**
 * The notice of a discussion shown as one person's messages only: how many there are and the
 * way back to the whole discussion. Without messages (or for a person that does not exist) it
 * says the same thing for both.
 */
defineProps<{
    filter: ForumAuthorFilter;
}>();

const { t } = useI18n();
</script>

<template>
    <p
        class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-default bg-muted px-4 py-3 text-sm text-base-secondary"
        data-testid="author-filter"
    >
        <Funnel class="h-4 w-4 shrink-0" aria-hidden="true" />
        <span v-if="filter.count > 0 && filter.authorName !== null">{{
            t(
                'forum.posts.authorFilter.showing',
                { count: filter.count, name: filter.authorName },
                filter.count
            )
        }}</span>
        <span v-else-if="filter.pending > 0 && filter.authorName !== null">{{
            t('forum.posts.authorFilter.pendingOnly', { name: filter.authorName })
        }}</span>
        <span v-else>{{ t('forum.posts.authorFilter.none') }}</span>
        <span aria-hidden="true">·</span>
        <Link
            :href="filter.clearUrl"
            class="font-medium text-primary underline-offset-2 hover:underline"
            >{{ t('forum.posts.authorFilter.clear') }}</Link
        >
    </p>
</template>
