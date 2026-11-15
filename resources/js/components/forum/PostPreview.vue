<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PostAvatar from './PostAvatar.vue';
import { useEmbeds } from '../../composables/useEmbeds';
import { usePostReferences } from '../../composables/usePostReferences';
import type { ForumPostPreview } from '../../types/forum';

defineOptions({ name: 'PostPreview' });

/**
 * A post as a reference opens it, or as one of the replies to another: a small header, then
 * its body as the server drew it for the reader. A long post comes without its images and
 * embeds (it says how many) and shortened, with a link to read it whole where it is. Its own
 * references open inside it, one level deeper.
 */
const props = defineProps<{
    preview: ForumPostPreview | null;
    failed?: boolean;
    depth: number;
    load: (url: string) => Promise<ForumPostPreview | null>;
}>();

const { t } = useI18n();

const body = ref<HTMLElement | null>(null);
const references = usePostReferences(body, {
    depth: props.depth,
    load: props.load,
    html: () => props.preview?.html,
});
// Its embeds load on request (or with consent) like those of the post itself
useEmbeds(body, () => props.preview?.html ?? null);
// Its own boxes go when the post is drawn again
watch(
    () => props.preview?.html,
    () => references.closeAll(),
    { flush: 'pre' }
);

const name = computed(() => props.preview?.character?.name ?? props.preview?.authorName ?? null);

const hidden = computed((): string | null => {
    const preview = props.preview;
    if (preview === null || !preview.truncated) {
        return null;
    }
    const parts = [
        preview.hiddenImages > 0 ? t('forum.references.images', preview.hiddenImages) : null,
        preview.hiddenEmbeds > 0 ? t('forum.references.embeds', preview.hiddenEmbeds) : null,
    ].filter((part): part is string => part !== null);
    if (parts.length === 0) {
        return null;
    }
    const what =
        parts.length === 2
            ? t('forum.references.and', { first: parts[0], second: parts[1] })
            : parts[0];

    return preview.hiddenImages + preview.hiddenEmbeds === 1
        ? t('forum.references.hiddenOne', { what })
        : t('forum.references.hiddenMany', { what });
});
</script>

<template>
    <article
        class="not-prose my-2 rounded-md border border-default bg-surface px-3 py-2 text-sm"
        data-testid="post-preview"
    >
        <p v-if="failed" role="alert" class="text-base-muted">{{ t('forum.references.error') }}</p>
        <p v-else-if="preview === null" role="status" class="text-base-muted">
            {{ t('forum.references.loading') }}
        </p>
        <template v-else>
            <header class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <PostAvatar
                    :public-id="preview.authorAvatarPublicId ?? null"
                    :name="name"
                    size="sm"
                />
                <span class="font-semibold text-base-primary">{{ name ?? '—' }}</span>
                <span v-if="preview.authorTitle" class="text-xs text-base-muted">{{
                    preview.authorTitle
                }}</span>
                <a
                    v-if="preview.url"
                    :href="preview.url"
                    class="inline-flex min-h-[44px] items-center text-xs text-base-secondary hover:underline"
                    :title="preview.discussionTitle"
                    >#{{ preview.number }}</a
                >
                <time :datetime="preview.publishedAt" class="text-xs text-base-muted">{{
                    preview.publishedLabel
                }}</time>
            </header>
            <!-- Rendered by the server from the stored document, with every text escaped -->
            <!-- eslint-disable vue/no-v-html -->
            <div
                ref="body"
                :class="[
                    'forum-post prose prose-sm dark:prose-invert max-w-none break-words text-base-primary',
                    { 'forum-ref-preview__body--cut': preview.truncated },
                ]"
                v-html="preview.html"
            />
            <!-- eslint-enable vue/no-v-html -->
            <p v-if="hidden" class="mt-1 text-xs text-base-muted">{{ hidden }}</p>
            <a
                v-if="preview.truncated && preview.url"
                :href="preview.url"
                class="mt-1 inline-flex min-h-[44px] items-center text-xs font-semibold text-base-secondary hover:underline"
                data-testid="post-preview-whole"
                >{{ t('forum.references.whole') }} →</a
            >
            <Teleport v-for="item in references.open.value" :key="item.key" :to="item.anchor">
                <PostPreview
                    :preview="item.preview"
                    :failed="item.failed"
                    :depth="depth + 1"
                    :load="load"
                />
            </Teleport>
        </template>
    </article>
</template>
