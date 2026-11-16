<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ForumProfilePost } from '../../types/forum';

/**
 * «Últimos mensajes en el foro» on a public profile (`forum.latest-posts`, forum#135): the
 * person's latest messages the viewer may read, each with its topic (linking to the message), its
 * category, when it was published and an excerpt. The card and its title are the core's. The
 * excerpt is plain text from the server and is drawn as text.
 */
defineProps<{
    posts: ForumProfilePost[];
}>();
</script>

<template>
    <ul class="divide-y divide-[var(--color-border)]">
        <li
            v-for="post in posts"
            :key="post.url"
            class="min-w-0 py-3 first:pt-0 last:pb-0"
            data-testid="profile-post"
        >
            <Link
                :href="post.url"
                class="rounded-sm font-medium break-words text-base-primary hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >{{ post.title }}</Link
            >
            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-base-muted">
                <Link
                    :href="post.categoryUrl"
                    class="rounded-sm hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    >{{ post.category }}</Link
                >
                <span aria-hidden="true">·</span>
                <time :datetime="post.publishedAt">{{ post.publishedLabel }}</time>
            </p>
            <p
                v-if="post.excerpt !== ''"
                class="mt-1 line-clamp-3 text-sm break-words text-base-secondary"
                data-testid="profile-post-excerpt"
            >
                {{ post.excerpt }}
            </p>
        </li>
    </ul>
</template>
