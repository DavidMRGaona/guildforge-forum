<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { Flag, Link2, Pencil, Quote, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PostEditor from '../editor/PostEditor.vue';
import LikeButton from './LikeButton.vue';
import ModerationDialog, { type ModerationDialogFields } from '../moderation/ModerationDialog.vue';
import ModerationMenu, { type ModerationMenuItem } from '../moderation/ModerationMenu.vue';
import ReportDialog from '../moderation/ReportDialog.vue';
import SuspendDialog from '../moderation/SuspendDialog.vue';
import CharacterAvatar from '../characters/CharacterAvatar.vue';
import CharacterDialog from '../characters/CharacterDialog.vue';
import SpeakAsSelect from '../characters/SpeakAsSelect.vue';
import { quoteFits } from '../../editor/extensions/PostQuote';
import { sendJson } from '../../forum/api';
import { useEmbeds } from '../../composables/useEmbeds';
import type { ForumCharacterPicker, ForumEditorSettings, ForumPost } from '../../types/forum';

type PostAction = 'hide' | 'show' | 'approve' | 'reject';
type CharacterAction = 'retire' | 'delete';

/**
 * One post of the conversation. Its author may edit or delete it while they may post in the
 * discussion; anyone who may reply can quote it (the selected text, or the whole post); anyone
 * signed in who reads it may like it, except its author. Its moderators also edit it, hide or
 * show it (with a reason its author reads) and approve or reject it while it awaits approval.
 *
 * A post written as a character shows the character's portrait and name, and below who plays
 * it. Its author may switch character when editing; whoever moderates the character may
 * change, retire or delete it from here.
 */
const props = withDefaults(
    defineProps<{
        post: ForumPost;
        focused: boolean;
        canQuote: boolean;
        editor: ForumEditorSettings;
        mentionsUrl: string | null;
        mentionNames: Record<string, string>;
        hiddenReasonMax?: number;
        reasonMax?: number;
        /** For whoever moderates the whole forum: suspending the author from this post */
        suspendUrl?: string | null;
        viewerId?: string | null;
        /** «Hablar como» for the reader's own posts; null when characters are off for them */
        characters?: ForumCharacterPicker | null;
    }>(),
    { hiddenReasonMax: 300, reasonMax: 500, suspendUrl: null, viewerId: null, characters: null }
);

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
// Embedded posts of other services load only on request or with consent
useEmbeds(body, () => props.post.html);

const moderating = ref<PostAction | null>(null);
const moderationOpen = ref(false);
const moderationBusy = ref(false);
const moderationError = ref<string | null>(null);

const speaker = ref<string | null>(props.post.characterId);
const isOwn = computed(() => props.viewerId !== null && props.post.authorId === props.viewerId);

/** The character form for a moderator whose own characters are off: the server's limits */
const fallbackForm = computed(() => ({
    createUrl: null,
    categories: [],
    limits: {
        nameMax: 60,
        descriptionMax: 300,
        portraitKilobytes: 2048,
        portraitTypes: props.editor.imageTypes,
        perAccount: 30,
    },
}));

const characterAction = ref<CharacterAction | null>(null);
const characterDialogOpen = ref(false);
const characterBusy = ref(false);
const characterError = ref<string | null>(null);
const editingCharacter = ref(false);

const reporting = ref(false);
const reported = ref(false);
const suspending = ref(false);
const suspended = ref(false);

/** Whether the author may be suspended from here: someone else's post, by an account */
const canSuspendAuthor = computed(
    () =>
        props.suspendUrl !== null &&
        props.post.authorId !== null &&
        props.post.authorId !== props.viewerId
);

const moderationItems = computed<ModerationMenuItem[]>(() => {
    if (props.post.moderationUrl === null) {
        return characterItems.value;
    }
    const items: ModerationMenuItem[] = [];
    switch (props.post.status) {
        case 'visible':
            items.push({ key: 'hide', label: t('forum.moderation.post.hide') });
            break;
        case 'hidden':
            items.push({ key: 'show', label: t('forum.moderation.post.show') });
            break;
        case 'pending':
            items.push(
                { key: 'approve', label: t('forum.moderation.post.approve') },
                { key: 'reject', label: t('forum.moderation.post.reject'), danger: true }
            );
            break;
        default:
            break;
    }
    if (canSuspendAuthor.value) {
        items.push({ key: 'suspend', label: t('forum.moderation.post.suspend'), danger: true });
    }

    return [...items, ...characterItems.value];
});

/** What whoever moderates the post's character may do with it */
const characterItems = computed<ModerationMenuItem[]>(() => {
    const actions = props.post.character?.actions ?? null;
    if (actions === null) {
        return [];
    }
    const items: ModerationMenuItem[] = [
        { key: 'character-edit', label: t('forum.characters.moderation.edit') },
    ];
    if (actions.retireUrl) {
        items.push({ key: 'character-retire', label: t('forum.characters.moderation.retire') });
    }
    if (actions.restoreUrl) {
        items.push({ key: 'character-restore', label: t('forum.characters.moderation.restore') });
    }
    // A PNJ with messages (every one on a post) is retired, never deleted
    if (actions.deleteUrl) {
        items.push({
            key: 'character-delete',
            label: t('forum.characters.moderation.delete'),
            danger: true,
        });
    }

    return items;
});

async function restoreCharacter(): Promise<void> {
    const url = props.post.character?.actions?.restoreUrl ?? null;
    if (url === null) {
        return;
    }
    characterBusy.value = true;
    const answer = await sendJson(url, 'POST');
    characterBusy.value = false;
    if (answer.ok) {
        emit('changed');

        return;
    }
    error.value = answer.message ?? t('forum.characters.errors.save');
}

async function moderateCharacter(): Promise<void> {
    const actions = props.post.character?.actions ?? null;
    const action = characterAction.value;
    if (actions === null || action === null) {
        return;
    }
    const url = action === 'retire' ? actions.retireUrl : actions.deleteUrl;
    if (url === null) {
        return;
    }
    characterBusy.value = true;
    characterError.value = null;
    const answer = await sendJson(url, action === 'retire' ? 'POST' : 'DELETE');
    characterBusy.value = false;
    if (answer.ok) {
        characterDialogOpen.value = false;
        characterAction.value = null;
        emit('changed');

        return;
    }
    characterError.value = answer.message ?? t('forum.characters.errors.save');
}

function selectModeration(key: string): void {
    if (key === 'character-edit') {
        editingCharacter.value = true;

        return;
    }
    if (key === 'character-restore') {
        void restoreCharacter();

        return;
    }
    if (key === 'character-retire' || key === 'character-delete') {
        characterAction.value = key === 'character-retire' ? 'retire' : 'delete';
        characterError.value = null;
        characterDialogOpen.value = true;

        return;
    }
    if (key === 'suspend') {
        suspending.value = true;

        return;
    }
    const action = key as PostAction;
    moderationError.value = null;
    if (action === 'hide' || action === 'reject') {
        moderating.value = action;
        moderationOpen.value = true;

        return;
    }
    void moderate(action, { reason: '', title: '', category: '' });
}

async function moderate(action: PostAction, fields: ModerationDialogFields): Promise<void> {
    if (props.post.moderationUrl === null) {
        return;
    }
    moderationBusy.value = true;
    const answer = await sendJson(props.post.moderationUrl, 'POST', {
        action,
        reason: fields.reason === '' ? undefined : fields.reason,
    });
    moderationBusy.value = false;

    if (answer.ok) {
        moderationOpen.value = false;
        moderating.value = null;
        emit('changed');

        return;
    }

    const message = answer.message ?? t('forum.moderation.errors.generic');
    if (moderationOpen.value) {
        moderationError.value = message;
    } else {
        error.value = message;
    }
}

const anchor = computed(() =>
    props.post.number === null ? `post-${props.post.id}` : `post-${props.post.number}`
);
const isPlaceholder = computed(() => props.post.html === null);
const showsLikes = computed(
    () =>
        props.post.status === 'visible' && (props.post.likes.canLike || props.post.likes.count > 0)
);

function quote(): void {
    // The post's stored text, not what is drawn: rolls, asides and embeds add labels and buttons
    // («Tirada», each die, «Cargar contenido») the server would not find in the post
    const whole = (props.post.quoteText ?? '').trim();
    const selection = window.getSelection();
    const selected =
        selection && !selection.isCollapsed && body.value?.contains(selection.anchorNode)
            ? selection.toString().trim()
            : '';
    // A selection that takes in any of those labels would be refused: the whole post instead
    const text = selected !== '' && quoteFits(whole, selected) ? selected : whole;
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
    // Only its author says who it speaks as; a moderator's edit keeps it
    const answer = await sendJson(
        props.post.updateUrl,
        'PATCH',
        isOwn.value && props.characters ? { document, character: speaker.value } : { document }
    );
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
            <CharacterAvatar
                v-if="post.character"
                :initials="post.character.initials"
                :portrait-url="post.character.portraitUrl"
            />
            <span
                v-else
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-light text-sm font-semibold text-primary"
                aria-hidden="true"
            >
                {{ (post.authorName ?? '?').charAt(0).toUpperCase() }}
            </span>
            <div class="min-w-0 flex-1">
                <template v-if="post.character">
                    <!-- The badge wraps below a long name instead of being cut with it -->
                    <p
                        :id="`${anchor}-author`"
                        class="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-semibold text-base-primary"
                        data-testid="post-character"
                    >
                        <span class="min-w-0 truncate">{{ post.character.name }}</span>
                        <span
                            v-if="post.character.isRetired"
                            class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-base-secondary"
                        >
                            {{ t('forum.characters.retiredBadge') }}
                        </span>
                    </p>
                    <p
                        class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-base-secondary"
                        data-testid="post-character-player"
                    >
                        <span
                            v-if="post.character.isShared"
                            class="shrink-0 rounded-full bg-primary-light px-2 py-0.5 text-xs font-medium text-primary"
                            data-testid="post-character-shared"
                        >
                            {{ t('forum.characters.shared.badge') }}
                        </span>
                        <!-- A shared PNJ names its writer only to whoever may know -->
                        <span
                            v-if="!post.character.isShared || post.authorName !== null"
                            class="min-w-0 truncate"
                            >{{
                                post.character.isShared
                                    ? t('forum.characters.shared.writtenBy', {
                                          name: post.authorName,
                                      })
                                    : t('forum.characters.playedBy', {
                                          name: post.authorName ?? t('forum.posts.deletedAccount'),
                                      })
                            }}</span
                        >
                        <span v-else class="min-w-0 truncate">{{
                            t('forum.characters.shared.hiddenAuthor')
                        }}</span>
                        <span
                            v-if="post.isStarter"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-base-secondary"
                        >
                            {{ t('forum.posts.starter') }}
                        </span>
                    </p>
                </template>
                <p
                    v-else
                    :id="`${anchor}-author`"
                    class="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-semibold text-base-primary"
                >
                    <span class="min-w-0 truncate">{{
                        post.authorName ?? t('forum.posts.deletedAccount')
                    }}</span>
                    <span
                        v-if="post.isStarter"
                        class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-base-secondary"
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
                <SpeakAsSelect
                    v-if="isOwn && characters"
                    v-model="speaker"
                    class="mb-2"
                    :picker="characters"
                    :current="post.character"
                    :disabled="saving"
                />
                <PostEditor
                    :label="t('forum.posts.editLabel', { number: post.number ?? '' })"
                    :model-value="post.document"
                    :draft-key="`forum-edit-${post.id}`"
                    :mentions-url="mentionsUrl"
                    :upload-url="editor.uploadUrl"
                    :max-images="editor.maxImages"
                    :image-types="editor.imageTypes"
                    :external-image-url="editor.externalImageUrl"
                    :external-image-hosts="editor.externalImageHosts"
                    :embed-url="editor.embedUrl"
                    :embed-services="editor.embedServices"
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
                <p
                    v-if="post.hiddenReason"
                    class="mb-2 text-xs text-base-muted"
                    data-testid="post-hidden-reason"
                >
                    {{ t('forum.moderation.post.hiddenReason', { reason: post.hiddenReason }) }}
                </p>
                <p v-if="reported" role="status" class="mb-2 text-xs text-base-muted">
                    {{ t('forum.reports.sent') }}
                </p>
                <p v-if="suspended" role="status" class="mb-2 text-xs text-base-muted">
                    {{ t('forum.suspensions.done') }}
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
            v-if="
                !editing &&
                (((canQuote || post.canEdit || post.canDelete || showsLikes) &&
                    post.html !== null) ||
                    moderationItems.length > 0 ||
                    (post.reportUrl !== null && !reported))
            "
            class="flex flex-wrap items-center justify-end gap-1 border-t border-default px-2 py-1"
        >
            <LikeButton
                v-if="showsLikes"
                class="mr-auto"
                :likes="post.likes"
                :post-id="post.id"
                :post-number="post.number"
            />
            <ModerationMenu
                :items="moderationItems"
                :label="t('forum.moderation.post.menu')"
                :busy="moderationBusy"
                @select="selectModeration"
            />
            <button
                v-if="post.reportUrl && !reported"
                type="button"
                class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                data-testid="post-report"
                @click="reporting = true"
            >
                <Flag class="h-4 w-4" aria-hidden="true" />
                {{ t('forum.reports.action') }}
            </button>
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

        <ModerationDialog
            v-model="moderationOpen"
            :title="
                moderating === 'reject'
                    ? t('forum.moderation.post.rejectTitle')
                    : t('forum.moderation.post.hideTitle')
            "
            :message="
                moderating === 'reject'
                    ? t('forum.moderation.post.rejectMessage')
                    : t('forum.moderation.post.hideMessage')
            "
            :confirm-label="
                moderating === 'reject'
                    ? t('forum.moderation.post.reject')
                    : t('forum.moderation.post.hide')
            "
            :danger="moderating === 'reject'"
            ask-reason
            :reason-max="moderating === 'reject' ? reasonMax : hiddenReasonMax"
            :confirmation="
                moderating === 'reject' ? t('forum.moderation.post.rejectConfirmation') : null
            "
            :busy="moderationBusy"
            :error="moderationError"
            @confirm="(fields) => moderating && moderate(moderating, fields)"
        />

        <ModerationDialog
            v-model="characterDialogOpen"
            :title="
                characterAction === 'delete'
                    ? t('forum.characters.moderation.deleteTitle', {
                          name: post.character?.name ?? '',
                      })
                    : t('forum.characters.moderation.retireTitle', {
                          name: post.character?.name ?? '',
                      })
            "
            :message="
                characterAction === 'delete'
                    ? t('forum.characters.moderation.deleteMessage')
                    : t('forum.characters.moderation.retireMessage')
            "
            :confirm-label="
                characterAction === 'delete'
                    ? t('forum.characters.moderation.delete')
                    : t('forum.characters.moderation.retire')
            "
            :danger="characterAction === 'delete'"
            :confirmation="
                characterAction === 'delete'
                    ? t('forum.characters.moderation.deleteConfirmation')
                    : null
            "
            :busy="characterBusy"
            :error="characterError"
            @confirm="moderateCharacter"
        />

        <CharacterDialog
            v-if="post.character?.actions"
            v-model="editingCharacter"
            :form="characters ?? fallbackForm"
            :start="{
                name: post.character.name,
                description: post.character.description,
                categoryId: null,
                initials: post.character.initials,
                portraitUrl: post.character.portraitUrl,
                updateUrl: post.character.actions.updateUrl,
            }"
            :chooses-category="false"
            @saved="emit('changed')"
        />

        <ReportDialog
            v-if="post.reportUrl"
            v-model="reporting"
            :report-url="post.reportUrl"
            :post-number="post.number"
            @reported="reported = true"
        />

        <SuspendDialog
            v-if="canSuspendAuthor && suspendUrl && post.authorId"
            v-model="suspending"
            :suspend-url="suspendUrl"
            :user-id="post.authorId"
            :user-name="post.authorName"
            @suspended="suspended = true"
        />

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
