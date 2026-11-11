<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ThumbsUp } from '@lucide/vue';
import { sendJson } from '../../forum/api';
import type { ForumLikes, ForumPostLikes } from '../../types/forum';

/**
 * «Me gusta» on a post: one click gives it, another takes it back. The count opens the list of
 * who gave it (on hover, or with a tap or Enter, which also keeps it open).
 */
const props = defineProps<{
    likes: ForumPostLikes;
    postId: string;
    postNumber: number | null;
}>();

const { t } = useI18n();

const current = ref<ForumLikes>({ ...props.likes });
const busy = ref(false);
const error = ref<string | null>(null);
const pinned = ref(false);
const hovered = ref(false);

watch(
    () => props.likes,
    (likes) => {
        current.value = { ...likes };
    }
);

const listId = computed(() => `forum-likes-${props.postId}`);
const open = computed(() => current.value.count > 0 && (pinned.value || hovered.value));
const canToggle = computed(
    () => props.likes.url !== null && (props.likes.canLike || current.value.liked)
);
const number = computed(() => props.postNumber ?? '');

async function toggle(): Promise<void> {
    if (props.likes.url === null || busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    const answer = await sendJson<{ likes: ForumLikes }>(
        props.likes.url,
        current.value.liked ? 'DELETE' : 'POST'
    );
    busy.value = false;

    if (answer.ok && answer.data) {
        current.value = answer.data.likes;

        return;
    }

    error.value = answer.message ?? t('forum.likes.error');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        pinned.value = false;
        hovered.value = false;
    }
}
</script>

<template>
    <div
        v-if="canToggle || current.count > 0"
        class="relative inline-flex items-center"
        @keydown="onKeydown"
    >
        <button
            v-if="canToggle"
            type="button"
            class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-3 text-sm hover:bg-muted"
            :class="
                current.liked
                    ? 'font-medium text-primary'
                    : 'text-base-secondary hover:text-base-primary'
            "
            :aria-pressed="current.liked"
            :aria-label="
                current.liked
                    ? t('forum.likes.unlikeLabel', { number })
                    : t('forum.likes.likeLabel', { number })
            "
            :disabled="busy"
            @click="toggle"
        >
            <ThumbsUp
                class="h-4 w-4"
                :class="{ 'fill-current': current.liked }"
                aria-hidden="true"
            />
            <span>{{ current.liked ? t('forum.likes.liked') : t('forum.likes.like') }}</span>
        </button>
        <button
            v-if="current.count > 0"
            type="button"
            class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center gap-1 rounded-md px-2 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-label="t('forum.likes.whoLabel', { number })"
            @click="pinned = !pinned"
            @mouseenter="hovered = true"
            @mouseleave="hovered = false"
        >
            <ThumbsUp v-if="!canToggle" class="h-4 w-4" aria-hidden="true" />
            <span>{{ current.count }}</span>
        </button>
        <div
            v-show="open"
            :id="listId"
            class="absolute bottom-full left-0 z-20 mb-1 w-max max-w-[16rem] rounded-md border border-default bg-surface px-3 py-2 text-sm shadow-lg"
        >
            <p class="mb-1 font-medium text-base-primary">
                {{ t('forum.likes.count', current.count) }}
            </p>
            <ul class="space-y-0.5 text-base-secondary">
                <li v-for="name in current.names" :key="name" class="truncate">{{ name }}</li>
                <li v-if="current.others > 0">
                    {{ t('forum.likes.others', { count: current.others }) }}
                </li>
            </ul>
        </div>
        <p v-if="error" role="alert" class="ml-2 text-sm text-red-600">{{ error }}</p>
    </div>
</template>
