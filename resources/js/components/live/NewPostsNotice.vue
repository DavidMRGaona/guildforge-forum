<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ForumPost } from '../../types/forum';

/**
 * «N mensajes nuevos» under a conversation that went on while it was open. It sits at the foot
 * of the screen without moving the page (the new posts are drawn below what the reader has),
 * says so to screen readers politely through a live region that is always there, never takes
 * the focus, and goes away when its last post was on screen or the reader asked to see them.
 */
const props = defineProps<{
    /** The posts that arrived since the reader last looked, in order */
    posts: ForumPost[];
}>();

const emit = defineEmits<{ acknowledge: [] }>();

const { t } = useI18n();

const count = computed(() => props.posts.length);
const firstNumber = computed(() => props.posts[0]?.number ?? null);
const lastNumber = computed(() => props.posts[props.posts.length - 1]?.number ?? null);

let observer: IntersectionObserver | null = null;

function stopWatching(): void {
    observer?.disconnect();
    observer = null;
}

watch(
    lastNumber,
    async (number) => {
        stopWatching();
        if (number === null || typeof IntersectionObserver === 'undefined') {
            return;
        }

        await nextTick();
        const target = document.getElementById(`post-${number}`);
        if (target === null) {
            return;
        }

        observer = new IntersectionObserver(
            (entries) => {
                // Seen as the read tracker counts it: most of the post, or most of the screen
                const seen = entries.some(
                    (entry) =>
                        entry.isIntersecting &&
                        (entry.intersectionRatio >= 0.6 ||
                            entry.intersectionRect.height >=
                                (entry.rootBounds?.height ?? Infinity) * 0.6)
                );
                if (seen) {
                    emit('acknowledge');
                }
            },
            { threshold: [0, 0.6] }
        );
        observer.observe(target);
    },
    { immediate: true }
);

onBeforeUnmount(stopWatching);

function view(): void {
    const target =
        firstNumber.value === null ? null : document.getElementById(`post-${firstNumber.value}`);
    // The reader asked for it: this is the one time the page moves and the focus follows
    target?.scrollIntoView({ block: 'start' });
    target?.focus({ preventScroll: true });
    emit('acknowledge');
}
</script>

<template>
    <div
        class="pointer-events-none sticky bottom-4 z-10 flex justify-center px-4"
        style="margin-top: 0"
        data-testid="new-posts-notice"
    >
        <div
            role="status"
            aria-live="polite"
            aria-atomic="true"
            :class="
                count > 0
                    ? 'pointer-events-auto flex min-h-[44px] items-center gap-3 rounded-full border border-default bg-elevated py-1 ps-4 pe-1 text-sm text-base-primary shadow-lg'
                    : 'sr-only'
            "
        >
            <template v-if="count > 0">
                <span>{{ t('forum.live.newPosts', { count }, count) }}</span>
                <button
                    type="button"
                    class="inline-flex min-h-[36px] items-center rounded-full bg-primary px-3 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                    @click="view"
                >
                    {{ t('forum.live.view') }}
                </button>
            </template>
        </div>
    </div>
</template>
