<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PostPreview from './PostPreview.vue';
import { sendJson } from '../../forum/api';
import type { ForumPostPreview } from '../../types/forum';

/**
 * The posts that reply to one (point at it with «#N» or quote it), oldest first, as boxes like
 * the ones references open; twenty at a time, with «Ver más» while there are more.
 */
const props = defineProps<{
    id: string;
    url: string;
    label: string;
    load: (url: string) => Promise<ForumPostPreview | null>;
}>();

const { t } = useI18n();

const replies = ref<ForumPostPreview[]>([]);
const next = ref<string | null>(null);
const busy = ref(false);
const failed = ref(false);

async function fetchPage(url: string): Promise<void> {
    busy.value = true;
    failed.value = false;
    const answer = await sendJson<{ data: ForumPostPreview[]; next: string | null }>(url, 'GET');
    busy.value = false;
    if (!answer.ok || answer.data === null) {
        failed.value = true;

        return;
    }
    replies.value = [...replies.value, ...answer.data.data];
    next.value = answer.data.next;
}

onMounted(() => void fetchPage(props.url));
</script>

<template>
    <section :id="id" :aria-label="label" class="mt-1" data-testid="post-replies-list">
        <PostPreview
            v-for="reply in replies"
            :key="reply.id"
            :preview="reply"
            :depth="1"
            :load="load"
        />
        <p v-if="busy && replies.length === 0" role="status" class="text-sm text-base-muted">
            {{ t('forum.references.loading') }}
        </p>
        <p v-if="failed" role="alert" class="text-sm text-base-muted">
            {{ t('forum.references.repliesError') }}
        </p>
        <button
            v-if="next"
            type="button"
            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
            :disabled="busy"
            data-testid="post-replies-more"
            @click="fetchPage(next)"
        >
            {{ t('forum.references.more') }}
        </button>
    </section>
</template>
