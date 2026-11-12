<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { ForumMergedInto } from '../../types/forum';

/**
 * «Este tema se ha unido a "…"» for a page that was open when moderation merged its discussion
 * into another. It says so politely to screen readers (a live region, always there), offers the
 * way to the other discussion as a link and takes nobody there: the reader keeps what is on the
 * page and goes when they choose.
 */
defineProps<{
    /** Where the discussion went; null while nothing happened */
    target: ForumMergedInto | null;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="pointer-events-none sticky bottom-4 z-10 flex justify-center px-4">
        <div
            role="status"
            aria-live="polite"
            aria-atomic="true"
            :class="
                target !== null
                    ? 'pointer-events-auto flex min-h-[44px] flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-default bg-elevated px-4 py-2 text-sm text-base-primary shadow-lg'
                    : 'sr-only'
            "
            :data-testid="target !== null ? 'merged-notice' : undefined"
        >
            <template v-if="target !== null">
                <span>{{ t('forum.live.merged', { title: target.title }) }}</span>
                <Link
                    :href="target.url"
                    class="inline-flex min-h-[36px] items-center rounded-full bg-primary px-3 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                    >{{ t('forum.live.mergedGo') }}</Link
                >
            </template>
        </div>
    </div>
</template>
