<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { sendJson } from '../../forum/api';
import type { ForumConversation, ForumDiscussionModeration } from '../../types/forum';
import MergeDialog from './MergeDialog.vue';
import ModerationDialog, { type ModerationDialogFields } from './ModerationDialog.vue';
import ModerationMenu, { type ModerationMenuItem } from './ModerationMenu.vue';

type Action =
    | 'pin'
    | 'unpin'
    | 'close'
    | 'reopen'
    | 'hide'
    | 'show'
    | 'rename'
    | 'move'
    | 'split'
    | 'merge'
    | 'delete';

/**
 * The discussion's moderation menu. Quick actions (pin, reopen, show) run at once; the others
 * ask first: a reason for the log, a title, a category, and a box to tick before deleting.
 * Every answer reloads the page, so what is shown is what the server keeps.
 */
const props = defineProps<{
    discussion: ForumConversation;
    moderation: ForumDiscussionModeration;
}>();

// Splitting is chosen on the page itself, post by post: the page takes it from here
const emit = defineEmits<{
    split: [];
}>();

const { t } = useI18n();
const pending = ref<Action | null>(null);
const dialogOpen = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const mergeOpen = ref(false);
const mergeError = ref<string | null>(null);

const items = computed<ModerationMenuItem[]>(() => {
    if (props.discussion.isAwaitingApproval) {
        return [];
    }
    const list: ModerationMenuItem[] = [
        {
            key: props.discussion.isPinned ? 'unpin' : 'pin',
            label: t(
                props.discussion.isPinned
                    ? 'forum.moderation.discussion.unpin'
                    : 'forum.moderation.discussion.pin'
            ),
        },
        {
            key: props.discussion.isClosed ? 'reopen' : 'close',
            label: t(
                props.discussion.isClosed
                    ? 'forum.moderation.discussion.reopen'
                    : 'forum.moderation.discussion.close'
            ),
        },
        { key: 'rename', label: t('forum.moderation.discussion.rename') },
    ];
    if (props.moderation.moveTargets.length > 0) {
        list.push({ key: 'move', label: t('forum.moderation.discussion.move') });
    }
    if (props.moderation.splitUrl !== null) {
        list.push({ key: 'split', label: t('forum.moderation.discussion.split') });
    }
    if (props.moderation.mergeUrl !== null) {
        list.push({ key: 'merge', label: t('forum.moderation.discussion.merge') });
    }
    list.push({
        key: props.discussion.isHidden ? 'show' : 'hide',
        label: t(
            props.discussion.isHidden
                ? 'forum.moderation.discussion.show'
                : 'forum.moderation.discussion.hide'
        ),
    });
    if (props.moderation.deleteUrl !== null) {
        list.push({ key: 'delete', label: t('forum.moderation.discussion.delete'), danger: true });
    }

    return list;
});

const asksFirst: Action[] = ['close', 'hide', 'rename', 'move', 'delete'];

const dialog = computed(() => {
    const action = pending.value;

    return {
        title: action === null ? '' : t(`forum.moderation.discussion.${action}Title`),
        message:
            action === 'delete'
                ? t('forum.moderation.discussion.deleteMessage')
                : action === 'hide'
                  ? t('forum.moderation.discussion.hideMessage')
                  : '',
        confirmLabel: action === null ? '' : t(`forum.moderation.discussion.${action}`),
        danger: action === 'delete',
        askReason: action !== 'rename',
        askTitle: action === 'rename',
        moveTargets: action === 'move' ? props.moderation.moveTargets : [],
        confirmation:
            action === 'delete' ? t('forum.moderation.discussion.deleteConfirmation') : null,
    };
});

function select(key: string): void {
    const action = key as Action;
    error.value = null;
    if (action === 'split') {
        emit('split');

        return;
    }
    if (action === 'merge') {
        mergeError.value = null;
        mergeOpen.value = true;

        return;
    }
    if (asksFirst.includes(action)) {
        pending.value = action;
        dialogOpen.value = true;

        return;
    }
    void run(action, { reason: '', title: '', category: '' });
}

async function merge(fields: { target: string; reason: string }): Promise<void> {
    if (props.moderation.mergeUrl === null) {
        return;
    }
    busy.value = true;
    mergeError.value = null;
    const answer = await sendJson<{ url: string }>(props.moderation.mergeUrl, 'POST', {
        target: fields.target,
        reason: fields.reason === '' ? undefined : fields.reason,
        confirmed: true,
    });
    busy.value = false;

    if (!answer.ok || answer.data === null) {
        mergeError.value = answer.message ?? t('forum.moderation.errors.generic');

        return;
    }

    mergeOpen.value = false;
    router.visit(answer.data.url);
}

async function run(action: Action, fields: ModerationDialogFields): Promise<void> {
    busy.value = true;
    error.value = null;
    const reason = fields.reason === '' ? undefined : fields.reason;
    const answer =
        action === 'delete'
            ? await sendJson<{ url: string }>(props.moderation.deleteUrl ?? '', 'DELETE', {
                  confirmed: true,
                  reason,
              })
            : await sendJson<{ discussion: { url: string } }>(props.moderation.actionsUrl, 'POST', {
                  action,
                  reason,
                  title: action === 'rename' ? fields.title : undefined,
                  category: action === 'move' ? fields.category : undefined,
              });
    busy.value = false;

    if (!answer.ok) {
        const message = answer.message ?? t('forum.moderation.errors.generic');
        if (dialogOpen.value) {
            error.value = message;
        } else {
            notice.value = message;
        }

        return;
    }

    dialogOpen.value = false;
    pending.value = null;
    const data = answer.data as { url?: string; discussion?: { url: string } } | null;
    const target = data?.url ?? data?.discussion?.url ?? props.discussion.url;
    // A new title changes the address; anything else reloads the same page
    if (target !== props.discussion.url) {
        router.visit(target);
    } else {
        router.reload();
    }
}
</script>

<template>
    <div class="flex flex-col items-end gap-1">
        <ModerationMenu
            :items="items"
            :label="t('forum.moderation.discussion.menu')"
            :busy="busy"
            @select="select"
        />
        <p v-if="notice" role="alert" class="text-sm text-red-600">{{ notice }}</p>
        <ModerationDialog
            v-model="dialogOpen"
            :title="dialog.title"
            :message="dialog.message"
            :confirm-label="dialog.confirmLabel"
            :danger="dialog.danger"
            :ask-reason="dialog.askReason"
            :reason-max="moderation.limits.reasonMax"
            :ask-title="dialog.askTitle"
            :initial-title="discussion.title"
            :title-min="moderation.limits.titleMin"
            :title-max="moderation.limits.titleMax"
            :move-targets="dialog.moveTargets"
            :confirmation="dialog.confirmation"
            :busy="busy"
            :error="error"
            @confirm="(fields) => pending && run(pending, fields)"
        />
        <MergeDialog
            v-if="moderation.mergeTargetUrl"
            v-model="mergeOpen"
            :lookup-url="moderation.mergeTargetUrl"
            :reason-max="moderation.limits.reasonMax"
            :busy="busy"
            :error="mergeError"
            @confirm="merge"
        />
    </div>
</template>
