<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import PostItem from '../forum/PostItem.vue';
import { useReadTracker } from '../../composables/useReadTracker';
import type { ForumEditorSettings, ForumPost } from '../../types/forum';

/**
 * The posts of a record's conversation. Reading them moves the reader's mark only when the page
 * gives a read address (it does when the mark already reaches the first post shown), so seeing
 * the last posts never marks as read those in between. The tracker watches the posts there when
 * it mounts: the page mounts it again when that changes.
 */
const props = defineProps<{
    posts: ForumPost[];
    readUrl: string | null;
    lastReadNumber: number | null;
    canQuote: boolean;
    editor: ForumEditorSettings;
    mentionsUrl: string | null;
    mentionNames: Record<string, string>;
}>();

const emit = defineEmits<{
    quote: [source: { postId: string; text: string }];
    changed: [];
}>();

const list = ref<HTMLElement | null>(null);

const tracker = useReadTracker({
    readUrl: props.readUrl,
    lastReadNumber: props.lastReadNumber,
    container: list,
});

// Posts that arrive while the page is open are watched too, and count as read once seen
watch(
    () => props.posts.length,
    () => void nextTick(() => tracker.refresh())
);
</script>

<template>
    <div ref="list" class="space-y-4">
        <PostItem
            v-for="post in posts"
            :key="post.id"
            :post="post"
            :focused="false"
            :can-quote="canQuote"
            :editor="editor"
            :mentions-url="mentionsUrl"
            :mention-names="mentionNames"
            @quote="(source) => emit('quote', source)"
            @changed="emit('changed')"
        />
    </div>
</template>
