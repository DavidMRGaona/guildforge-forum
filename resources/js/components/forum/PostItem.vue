<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { Link2, Pencil, Quote, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PostEditor from '../editor/PostEditor.vue';
import { sendJson } from '../../forum/api';
import type { ForumEditorSettings, ForumPost } from '../../types/forum';

/**
 * One post of the conversation. Its author may edit or delete it while they may post in the
 * discussion; anyone who may reply can quote it (the selected text, or the whole post).
 */
const props = defineProps<{
    post: ForumPost;
    focused: boolean;
    canQuote: boolean;
    editor: ForumEditorSettings;
    mentionsUrl: string | null;
    mentionNames: Record<string, string>;
}>();

const emit = defineEmits<{
    quote: [source: { postId: string; text: string }];
    changed: [];
}>();

const { t } = useI18n();

const editing = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const confirmDelete = ref(false);
const body = ref<HTMLElement | null>(null);

const anchor = computed(() =>
    props.post.number === null ? `post-${props.post.id}` : `post-${props.post.number}`
);
const isPlaceholder = computed(() => props.post.html === null);

function quote(): void {
    const selection = window.getSelection();
    const selected =
        selection && !selection.isCollapsed && body.value?.contains(selection.anchorNode)
            ? selection.toString()
            : '';
    const text = (selected.trim() !== '' ? selected : (body.value?.innerText ?? '')).trim();
    if (text !== '') {
        emit('quote', { postId: props.post.id, text });
    }
}

async function save(document: JSONContent): Promise<void> {
    if (props.post.updateUrl === null) {
        return;
    }
    saving.value = true;
    error.value = null;
    const answer = await sendJson(props.post.updateUrl, 'PATCH', { document });
    saving.value = false;

    if (answer.ok) {
        editing.value = false;
        emit('changed');

        return;
    }

    error.value = answer.message ?? t('forum.posts.errors.save');
}

async function remove(): Promise<void> {
    if (props.post.deleteUrl === null) {
        return;
    }
    const answer = await sendJson(props.post.deleteUrl, 'DELETE');
    if (answer.ok) {
        emit('changed');

        return;
    }

    error.value = answer.message ?? t('forum.posts.errors.delete');
}
</script>

<template>
    <article
        :id="anchor"
        tabindex="-1"
        class="scroll-mt-24 rounded-lg border bg-surface focus:outline-none"
        :class="focused ? 'border-primary ring-2 ring-primary-500/40' : 'border-default'"
        :data-post-number="post.number ?? undefined"
        :data-post-url="post.url ?? undefined"
        :aria-labelledby="`${anchor}-author`"
    >
        <header
            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-default px-4 py-3"
        >
            <span
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-light text-sm font-semibold text-primary"
                aria-hidden="true"
            >
                {{ (post.authorName ?? '?').charAt(0).toUpperCase() }}
            </span>
            <div class="min-w-0 flex-1">
                <p :id="`${anchor}-author`" class="truncate font-semibold text-base-primary">
                    {{ post.authorName ?? t('forum.posts.deletedAccount') }}
                    <span
                        v-if="post.isStarter"
                        class="ml-1 rounded-full bg-muted px-2 py-0.5 align-middle text-xs font-medium text-base-secondary"
                    >
                        {{ t('forum.posts.starter') }}
                    </span>
                </p>
                <p class="text-xs text-base-muted">
                    <time :datetime="post.publishedAt ?? undefined">{{ post.publishedLabel }}</time>
                    <span v-if="post.editedLabel">
                        · {{ t('forum.posts.edited', { at: post.editedLabel }) }}</span
                    >
                    <span v-if="post.status === 'pending'">
                        · {{ t('forum.posts.pendingBadge') }}</span
                    >
                </p>
            </div>
            <a
                v-if="post.url && post.number"
                :href="post.url"
                class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center gap-1 rounded-md text-sm text-base-muted hover:text-primary"
                :aria-label="t('forum.posts.permalink', { number: post.number })"
            >
                <Link2 class="h-4 w-4" aria-hidden="true" />
                <span>#{{ post.number }}</span>
            </a>
        </header>

        <div class="px-4 py-3">
            <template v-if="editing">
                <PostEditor
                    :label="t('forum.posts.editLabel', { number: post.number ?? '' })"
                    :model-value="post.document"
                    :draft-key="`forum-edit-${post.id}`"
                    :mentions-url="mentionsUrl"
                    :upload-url="editor.uploadUrl"
                    :max-images="editor.maxImages"
                    :image-types="editor.imageTypes"
                    :mention-names="mentionNames"
                    :submit-label="t('forum.posts.saveEdit')"
                    :submitting="saving"
                    :error="error"
                    @submit="save"
                />
                <button
                    type="button"
                    class="mt-2 inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted"
                    @click="editing = false"
                >
                    {{ t('forum.posts.cancelEdit') }}
                </button>
            </template>
            <template v-else>
                <p v-if="post.placeholder && isPlaceholder" class="text-sm italic text-base-muted">
                    {{ post.placeholder }}
                </p>
                <p v-else-if="post.placeholder" class="mb-2 text-xs font-medium text-base-muted">
                    {{ post.placeholder }}
                </p>
                <!-- Rendered by the server from the stored document, with every text escaped -->
                <!-- eslint-disable vue/no-v-html -->
                <div
                    v-if="post.html !== null"
                    ref="body"
                    class="forum-post prose dark:prose-invert max-w-none break-words text-base-primary"
                    v-html="post.html"
                />
                <!-- eslint-enable vue/no-v-html -->
                <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
            </template>
        </div>

        <footer
            v-if="!editing && (canQuote || post.canEdit || post.canDelete) && post.html !== null"
            class="flex flex-wrap justify-end gap-1 border-t border-default px-2 py-1"
        >
            <button
                v-if="canQuote && post.status === 'visible'"
                type="button"
                class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                @click="quote"
            >
                <Quote class="h-4 w-4" aria-hidden="true" />
                {{ t('forum.posts.quote') }}
            </button>
            <button
                v-if="post.canEdit && post.document && post.updateUrl"
                type="button"
                class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                @click="editing = true"
            >
                <Pencil class="h-4 w-4" aria-hidden="true" />
                {{ t('forum.posts.edit') }}
            </button>
            <button
                v-if="post.canDelete && post.deleteUrl"
                type="button"
                class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                @click="confirmDelete = true"
            >
                <Trash2 class="h-4 w-4" aria-hidden="true" />
                {{ t('forum.posts.delete') }}
            </button>
        </footer>

        <ConfirmDialog
            v-model="confirmDelete"
            :title="t('forum.posts.deleteTitle')"
            :message="t('forum.posts.deleteMessage')"
            :confirm-label="t('forum.posts.delete')"
            :cancel-label="t('common.cancel')"
            confirm-variant="danger"
            @confirm="remove"
        />
    </article>
</template>
