<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CornerDownRight } from '@lucide/vue';
import type { ForumMovedPosts } from '../../types/forum';

/**
 * What stands where moderation moved posts away: «2 mensajes movidos a …», with a link to where
 * they are now when the reader may read it.
 */
const props = defineProps<{
    moved: ForumMovedPosts;
}>();

const { t } = useI18n();

const numbers = computed(() =>
    t('forum.posts.moved.numbers', {
        numbers:
            props.moved.fromNumber === props.moved.throughNumber
                ? `${props.moved.fromNumber}`
                : `${props.moved.fromNumber}–${props.moved.throughNumber}`,
    })
);
</script>

<template>
    <p
        class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-dashed border-default bg-muted px-4 py-3 text-sm text-base-secondary"
        data-testid="moved-posts"
    >
        <CornerDownRight class="h-4 w-4 shrink-0" aria-hidden="true" />
        <template v-if="moved.url && moved.title">
            <span>{{ t('forum.posts.moved.to', moved.count) }}</span>
            <Link
                :href="moved.url"
                class="font-medium break-words text-primary underline-offset-2 hover:underline"
                >{{ moved.title }}</Link
            >
        </template>
        <span v-else>{{ t('forum.posts.moved.elsewhere', moved.count) }}</span>
        <span class="text-base-muted">({{ numbers }})</span>
    </p>
</template>
