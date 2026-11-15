<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, Flag, Mail, Pencil, Quote, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PostEditor from '../editor/PostEditor.vue';
import LikeButton from './LikeButton.vue';
import PostAvatar from './PostAvatar.vue';
import PostPreview from './PostPreview.vue';
import PostReplies from './PostReplies.vue';
import ModerationDialog, { type ModerationDialogFields } from '../moderation/ModerationDialog.vue';
import ModerationMenu, { type ModerationMenuItem } from '../moderation/ModerationMenu.vue';
import ReportDialog from '../moderation/ReportDialog.vue';
import SuspendDialog from '../moderation/SuspendDialog.vue';
import TitleDialog from '../moderation/TitleDialog.vue';
import CharacterAvatar from '../characters/CharacterAvatar.vue';
import CharacterDialog from '../characters/CharacterDialog.vue';
import SpeakAsSelect from '../characters/SpeakAsSelect.vue';
import { quoteFits } from '../../editor/extensions/PostQuote';
import { selectionText } from '../../forum/selectionText';
import { revealRollUrl, sendJson } from '../../forum/api';
import { useEmbeds } from '../../composables/useEmbeds';
import { usePostReferences } from '../../composables/usePostReferences';
import { useRollReveals } from '../../composables/useRollReveals';
import { useSelectionQuote } from '../../composables/useSelectionQuote';
import type {
    ForumCharacterPicker,
    ForumEditorSettings,
    ForumPost,
    ForumPostPreview,
} from '../../types/forum';

type PostAction = 'hide' | 'show' | 'approve' | 'reject';
type CharacterAction = 'retire' | 'delete';

/**
 * One post of the conversation. Its author may edit or delete it while they may post in the
 * discussion; anyone who may reply can quote it (the selected text, or the whole post); anyone
 * signed in who reads it may like it, except its author. Its moderators also edit it, hide or
 * show it (with a reason its author reads) and approve or reject it while it awaits approval.
 *
 * A hidden roll the reader may reveal (whoever threw it, a campaign's direction) carries a
 * «Revelar» button; revealing asks twice, since it cannot be undone.
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
        /** For whoever moderates the whole forum: giving the author a title */
        titleUrl?: string | null;
        /** «Hablar como» for the reader's own posts; null when characters are off for them */
        characters?: ForumCharacterPicker | null;
    }>(),
    {
        hiddenReasonMax: 300,
        reasonMax: 500,
        suspendUrl: null,
        titleUrl: null,
        viewerId: null,
        characters: null,
    }
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

// «Revelar» on a hidden roll: confirmed twice, then the page reads the post again
const { asked: rollToReveal } = useRollReveals(body);

// «Citar» floating over a selection of the post's text that the server would take as its words
const selection = useSelectionQuote(body, {
    enabled: () =>
        props.canQuote &&
        props.post.status === 'visible' &&
        !editing.value &&
        props.post.html !== null,
    fits: (text: string) => quoteFits(props.post.quoteText ?? '', text),
});
const selectionVisible = selection.visible;
// The post drawn again (edited, moderated, reloaded): what was selected is no longer there
watch(
    () => props.post.html,
    () => selection.hide()
);

/** A post as a reference opens it; null when it may not be read (or the request failed) */
async function loadPreview(url: string): Promise<ForumPostPreview | null> {
    const answer = await sendJson<ForumPostPreview>(url, 'GET');

    return answer.ok ? answer.data : null;
}

// «#3» opens post 3 under its paragraph; the boxes go when the post is drawn again
const references = usePostReferences(body, {
    depth: 0,
    load: loadPreview,
    html: () => props.post.html,
});
watch(
    () => props.post.html,
    () => references.closeAll(),
    { flush: 'pre' }
);

// «N respuestas»: the posts that point at this one or quote it
const replyCount = computed(() =>
    props.post.status === 'visible' ? (props.post.replyCount ?? 0) : 0
);
const showsReplies = computed(() => replyCount.value > 0 && Boolean(props.post.repliesUrl));
const repliesOpen = ref(false);
const repliesId = computed(() => `post-replies-${props.post.id}`);

/**
 * Hands the teleported button to the composable. A function ref: the string form was never set
 * on the teleported button in the browser, so the button stayed unplaced at the top corner.
 */
function setSelectionButton(element: unknown): void {
    selection.floating.value = element instanceof HTMLElement ? element : null;
}
const revealOpen = computed({
    get: () => rollToReveal.value !== null,
    set: (open: boolean) => {
        if (!open) {
            rollToReveal.value = null;
            revealError.value = null;
        }
    },
});
const revealBusy = ref(false);
const revealError = ref<string | null>(null);

async function revealRoll(): Promise<void> {
    const roll = rollToReveal.value;
    if (roll === null || revealBusy.value) {
        return;
    }
    revealBusy.value = true;
    revealError.value = null;
    const answer = await sendJson(revealRollUrl(roll.id), 'POST');
    revealBusy.value = false;
    if (answer.ok) {
        rollToReveal.value = null;
        emit('changed');

        return;
    }
    revealError.value = answer.message ?? t('forum.rolls.revealFailed');
}

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
        return [...titleItems.value, ...characterItems.value];
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

    return [...items, ...titleItems.value, ...characterItems.value];
});

/** Giving the author a title, for whoever moderates the whole forum */
const titleItems = computed<ModerationMenuItem[]>(() => {
    // In character the title is left out, so the menu could not tell whether the person holds one
    if (!props.titleUrl || props.post.authorId === null || props.post.character) {
        return [];
    }

    return props.post.authorTitle
        ? [
              { key: 'title-change', label: t('forum.titles.change') },
              { key: 'title-remove', label: t('forum.titles.remove') },
          ]
        : [{ key: 'title-set', label: t('forum.titles.set') }];
});
const titling = ref(false);

async function removeTitle(): Promise<void> {
    if (!props.titleUrl || props.post.authorId === null) {
        return;
    }
    const answer = await sendJson(props.titleUrl, 'POST', {
        user: props.post.authorId,
        title: null,
    });
    if (answer.ok) {
        emit('changed');

        return;
    }
    error.value = answer.message ?? t('forum.titles.errors.save');
}

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
    if (key === 'title-set' || key === 'title-change') {
        titling.value = true;

        return;
    }
    if (key === 'title-remove') {
        void removeTitle();

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

/** Every action button: 44 px for a finger */
const actionClass =
    'inline-flex min-h-[44px] min-w-[44px] items-center justify-center gap-1 rounded-md text-sm text-base-secondary hover:bg-muted hover:text-base-primary';
/** Dimmed where a mouse can bring them back (hover or focus within the post); always full on touch */
const secondaryClass =
    '[@media(hover:hover)]:opacity-60 [@media(hover:hover)]:group-hover:opacity-100 [@media(hover:hover)]:group-focus-within:opacity-100';

const anchor = computed(() =>
    props.post.number === null ? `post-${props.post.id}` : `post-${props.post.number}`
);
const isPlaceholder = computed(() => props.post.html === null);
const showsLikes = computed(
    () =>
        props.post.status === 'visible' && (props.post.likes.canLike || props.post.likes.count > 0)
);
/** Whether the post shows its row of actions (the row also gives the post its bottom room) */
const hasFooter = computed(
    () =>
        !editing.value &&
        (((props.canQuote ||
            props.post.canEdit ||
            props.post.canDelete ||
            showsLikes.value ||
            showsReplies.value) &&
            props.post.html !== null) ||
            moderationItems.value.length > 0 ||
            (props.post.reportUrl !== null && !reported.value) ||
            Boolean(props.post.messageUrl))
);

/** Quotes what the floating button was offered for: the selection as it settled */
function quoteSelection(): void {
    const text = selection.text.value;
    selection.hide();
    if (text !== '') {
        emit('quote', { postId: props.post.id, text });
    }
}

function quote(): void {
    // The post's stored text, not what is drawn: rolls, asides and embeds add labels and buttons
    // («Tirada», each die, «Cargar contenido») the server would not find in the post
    const whole = (props.post.quoteText ?? '').trim();
    const selection = window.getSelection();
    const selected =
        selection &&
        !selection.isCollapsed &&
        selection.rangeCount > 0 &&
        body.value?.contains(selection.anchorNode)
            ? selectionText(selection.getRangeAt(0))
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
        class="group scroll-mt-24 pt-3 focus:outline-none sm:grid sm:grid-cols-[72px_minmax(0,1fr)] sm:gap-x-4"
        :class="[
            hasFooter ? 'pb-1' : 'pb-3',
            // The 3 px border is taken from the left padding, so the content never shifts.
            post.isStarter ? 'border-l-[3px] border-primary pl-[13px] pr-4' : 'px-4',
            focused ? 'ring-2 ring-inset ring-primary-500/40' : '',
        ]"
        :data-starter="post.isStarter ? 'true' : undefined"
        :data-post-number="post.number ?? undefined"
        :data-post-url="post.url ?? undefined"
        :aria-labelledby="`${anchor}-author`"
    >
        <!-- The side column: a character's portrait, or the account's profile avatar -->
        <div class="hidden sm:block">
            <CharacterAvatar
                v-if="post.character"
                size="xl"
                :initials="post.character.initials"
                :portrait-url="post.character.portraitUrl"
            />
            <PostAvatar
                v-else
                size="xl"
                :public-id="post.authorAvatarPublicId ?? null"
                :name="post.authorName"
            />
        </div>

        <div class="min-w-0">
            <header class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                <!-- Avatar and name always share a line: a long name is cut, never pushed below. It
                     starts from its own width (flex-auto), so when it does not fit beside the date
                     the date goes to the next line instead of the name shrinking to a letter -->
                <div class="flex min-w-0 flex-auto items-center gap-2" data-testid="post-author">
                    <!-- On phones the avatar moves to the author line, leaving the width to the text -->
                    <CharacterAvatar
                        v-if="post.character"
                        class="sm:hidden"
                        size="sm"
                        :initials="post.character.initials"
                        :portrait-url="post.character.portraitUrl"
                    />
                    <PostAvatar
                        v-else
                        class="sm:hidden"
                        size="sm"
                        :public-id="post.authorAvatarPublicId ?? null"
                        :name="post.authorName"
                    />
                    <p
                        v-if="post.character"
                        :id="`${anchor}-author`"
                        class="flex min-w-0 items-center gap-x-2 font-semibold text-base-primary"
                        data-testid="post-character"
                    >
                        <span class="min-w-[6ch] truncate" data-testid="post-character-name">{{
                            post.character.name
                        }}</span>
                        <span
                            v-if="post.character.isRetired"
                            class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-base-secondary"
                        >
                            {{ t('forum.characters.retiredBadge') }}
                        </span>
                        <span
                            v-if="post.isStarter"
                            class="sr-only"
                            data-testid="post-starter-label"
                            >{{ t('forum.posts.starter') }}</span
                        >
                    </p>
                    <p
                        v-else
                        :id="`${anchor}-author`"
                        class="flex min-w-0 items-center gap-x-2 font-semibold text-base-primary"
                    >
                        <span class="min-w-[6ch] truncate" data-testid="post-author-name">{{
                            post.authorName ?? t('forum.posts.deletedAccount')
                        }}</span>
                        <!-- Given by moderation; escaped like any text. Cut before the name, never to nothing -->
                        <span
                            v-if="post.authorTitle"
                            class="min-w-[5ch] shrink-[2] truncate text-xs font-normal text-base-secondary"
                            :title="post.authorTitle"
                            data-testid="post-author-title"
                            >{{ post.authorTitle }}</span
                        >
                        <span
                            v-if="post.isStarter"
                            class="sr-only"
                            data-testid="post-starter-label"
                            >{{ t('forum.posts.starter') }}</span
                        >
                    </p>
                </div>
                <!-- Date, «editado» and «pendiente» wrap on narrow screens instead of widening the page -->
                <p
                    class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-x-1.5 text-xs text-base-muted"
                    data-testid="post-meta"
                >
                    <time :datetime="post.publishedAt ?? undefined">{{ post.publishedLabel }}</time>
                    <span v-if="post.editedLabel">
                        · {{ t('forum.posts.edited', { at: post.editedLabel }) }}</span
                    >
                    <span v-if="post.status === 'pending'">
                        · {{ t('forum.posts.pendingBadge') }}</span
                    >
                    <a
                        v-if="post.url && post.number"
                        :href="post.url"
                        class="inline-flex items-center justify-center rounded-md px-1 font-medium text-base-muted hover:text-primary pointer-coarse:min-h-[44px] pointer-coarse:min-w-[44px]"
                        :aria-label="t('forum.posts.permalink', { number: post.number })"
                        >#{{ post.number }}</a
                    >
                </p>
            </header>
            <p
                v-if="post.character"
                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-base-secondary"
                data-testid="post-character-player"
            >
                <span
                    v-if="post.character.isShared"
                    class="shrink-0 rounded-full bg-primary-light px-2 py-0.5 text-xs font-medium text-primary"
                    data-testid="post-character-shared"
                >
                    {{
                        post.authorName === null
                            ? t('forum.characters.shared.hiddenAuthor')
                            : t('forum.characters.shared.badge')
                    }}
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
            </p>

            <div class="mt-1">
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
                    <p
                        v-if="post.placeholder && isPlaceholder"
                        class="text-sm italic text-base-muted"
                    >
                        {{ post.placeholder }}
                    </p>
                    <p
                        v-else-if="post.placeholder"
                        class="mb-2 text-xs font-medium text-base-muted"
                    >
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
                    <Teleport
                        v-for="item in references.open.value"
                        :key="item.key"
                        :to="item.anchor"
                    >
                        <PostPreview
                            :preview="item.preview"
                            :failed="item.failed"
                            :depth="1"
                            :load="loadPreview"
                        />
                    </Teleport>
                    <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
                </template>
            </div>

            <footer v-if="hasFooter" class="-ml-2 mt-1 flex flex-wrap items-center gap-0.5">
                <LikeButton
                    v-if="showsLikes"
                    :class="{ 'mr-auto': !showsReplies }"
                    :likes="post.likes"
                    :post-id="post.id"
                    :post-number="post.number"
                />
                <button
                    v-if="showsReplies && post.repliesUrl"
                    type="button"
                    :class="[actionClass, 'mr-auto px-3']"
                    :aria-expanded="repliesOpen ? 'true' : 'false'"
                    :aria-controls="repliesId"
                    data-testid="post-replies"
                    @click="repliesOpen = !repliesOpen"
                >
                    {{ t('forum.references.replies', replyCount) }}
                    <ChevronDown
                        :class="[
                            'h-4 w-4 transition-transform motion-reduce:transition-none',
                            { 'rotate-180': repliesOpen },
                        ]"
                        aria-hidden="true"
                    />
                </button>
                <button
                    v-if="canQuote && post.status === 'visible'"
                    type="button"
                    :class="[actionClass, 'px-3', { 'ml-auto': !showsLikes && !showsReplies }]"
                    :aria-label="t('forum.posts.quote')"
                    @click="quote"
                >
                    <Quote class="h-4 w-4" aria-hidden="true" />
                    {{ t('forum.posts.quote') }}
                </button>
                <!-- Secondary actions: dimmed until the post is hovered or holds the focus, where a mouse is -->
                <Link
                    v-if="post.messageUrl"
                    :href="post.messageUrl"
                    :class="[actionClass, secondaryClass]"
                    :aria-label="
                        t('forum.posts.writePrivatelyTo', {
                            name: post.authorName ?? t('forum.posts.deletedAccount'),
                        })
                    "
                    :title="
                        t('forum.posts.writePrivatelyTo', {
                            name: post.authorName ?? t('forum.posts.deletedAccount'),
                        })
                    "
                    data-secondary
                    data-testid="post-write-privately"
                >
                    <Mail class="h-4 w-4" aria-hidden="true" />
                </Link>
                <button
                    v-if="post.canEdit && post.document && post.updateUrl"
                    type="button"
                    :class="[actionClass, secondaryClass]"
                    :aria-label="t('forum.posts.edit')"
                    :title="t('forum.posts.edit')"
                    data-secondary
                    @click="editing = true"
                >
                    <Pencil class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                    v-if="post.canDelete && post.deleteUrl"
                    type="button"
                    :class="[actionClass, secondaryClass]"
                    :aria-label="t('forum.posts.delete')"
                    :title="t('forum.posts.delete')"
                    data-secondary
                    @click="confirmDelete = true"
                >
                    <Trash2 class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                    v-if="post.reportUrl && !reported"
                    type="button"
                    :class="[actionClass, secondaryClass]"
                    :aria-label="t('forum.reports.action')"
                    :title="t('forum.reports.action')"
                    data-secondary
                    data-testid="post-report"
                    @click="reporting = true"
                >
                    <Flag class="h-4 w-4" aria-hidden="true" />
                </button>
                <ModerationMenu
                    :class="secondaryClass"
                    :items="moderationItems"
                    :label="t('forum.moderation.post.menu')"
                    :busy="moderationBusy"
                    @select="selectModeration"
                />
            </footer>
            <PostReplies
                v-if="repliesOpen && post.repliesUrl"
                :id="repliesId"
                :url="post.repliesUrl"
                :label="t('forum.references.region', { number: post.number ?? '' })"
                :load="loadPreview"
            />

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

            <TitleDialog
                v-if="titleUrl && post.authorId"
                v-model="titling"
                :title-url="titleUrl"
                :user-id="post.authorId"
                :user-name="post.authorName"
                :current="post.authorTitle ?? null"
                @saved="emit('changed')"
            />

            <SuspendDialog
                v-if="canSuspendAuthor && suspendUrl && post.authorId"
                v-model="suspending"
                :suspend-url="suspendUrl"
                :user-id="post.authorId"
                :user-name="post.authorName"
                @suspended="suspended = true"
            />

            <ModerationDialog
                v-model="revealOpen"
                :title="t('forum.rolls.revealTitle')"
                :message="t('forum.rolls.revealMessage', { roll: rollToReveal?.label ?? '' })"
                :confirm-label="t('forum.rolls.reveal')"
                :confirmation="t('forum.rolls.revealConfirmation')"
                :busy="revealBusy"
                :error="revealError"
                @confirm="revealRoll"
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
            <!-- Outside the list, so no container clips it; placed by useSelectionQuote -->
            <Teleport to="body">
                <button
                    v-if="selectionVisible"
                    :ref="setSelectionButton"
                    type="button"
                    class="fixed left-0 top-0 z-50 inline-flex min-h-[44px] items-center gap-1 rounded-md border border-default bg-surface px-3 text-sm font-medium text-base-primary shadow-lg hover:bg-muted"
                    :aria-label="t('forum.posts.quoteSelection')"
                    data-testid="selection-quote"
                    @pointerdown.prevent
                    @mousedown.prevent
                    @click="quoteSelection"
                >
                    <Quote class="h-4 w-4" aria-hidden="true" />
                    {{ t('forum.posts.quote') }}
                </button>
            </Teleport>
        </div>
    </article>
</template>
