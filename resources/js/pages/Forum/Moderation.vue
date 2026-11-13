<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import ModerationDialog, {
    type ModerationDialogFields,
} from '../../components/moderation/ModerationDialog.vue';
import SuspendDialog from '../../components/moderation/SuspendDialog.vue';
import { sendJson } from '../../forum/api';
import type {
    ForumConversationReport,
    ForumPendingItem,
    ForumReportedItem,
} from '../../types/forum';

/**
 * «Moderación» in the forum: reported posts and posts awaiting approval in what the person
 * moderates. Hiding and rejecting ask first (a reason; rejecting, a box to tick); the rest
 * runs at once. Every answer reloads the lists.
 */
withDefaults(
    defineProps<{
        reported: ForumReportedItem[];
        pending: ForumPendingItem[];
        /** Reported private messages (their copy), for whoever moderates the whole forum */
        privateReports?: ForumConversationReport[] | null;
        suspendUrl?: string | null;
    }>(),
    { privateReports: null, suspendUrl: null }
);

type Pending =
    | { kind: 'hide'; item: ForumReportedItem }
    | { kind: 'reject'; item: ForumPendingItem }
    | { kind: 'hidePrivate'; item: ForumConversationReport };

const { t } = useI18n();
const busy = ref<string | null>(null);
const error = ref<string | null>(null);
const asking = ref<Pending | null>(null);
const dialogOpen = ref(false);
const dialogError = ref<string | null>(null);

const breadcrumbs = computed(() => [{ name: t('forum.title'), url: '/foro' }]);

async function send(id: string, url: string, body: Record<string, unknown>): Promise<boolean> {
    busy.value = id;
    error.value = null;
    const answer = await sendJson(url, 'POST', body);
    busy.value = null;
    if (answer.ok) {
        router.reload({ only: ['reported', 'pending', 'privateReports'] });

        return true;
    }
    const message = answer.message ?? t('forum.moderation.errors.generic');
    if (dialogOpen.value) {
        dialogError.value = message;
    } else {
        error.value = message;
    }

    return false;
}

function settle(item: ForumReportedItem, resolution: 'no_action' | 'edited'): void {
    void send(item.postId, item.resolveUrl, { resolution });
}

function dismissPrivate(item: ForumConversationReport): void {
    void send(item.id, item.resolveUrl, { resolution: 'no_action' });
}

const suspending = ref<ForumConversationReport | null>(null);
const suspendOpen = ref(false);

function askSuspend(item: ForumConversationReport): void {
    suspending.value = item;
    suspendOpen.value = true;
}

function approve(item: ForumPendingItem): void {
    void send(item.postId, item.moderationUrl, { action: 'approve' });
}

function ask(next: Pending): void {
    asking.value = next;
    dialogError.value = null;
    dialogOpen.value = true;
}

async function confirm(fields: ModerationDialogFields): Promise<void> {
    const current = asking.value;
    if (current === null) {
        return;
    }
    const reason = fields.reason === '' ? undefined : fields.reason;
    const done =
        current.kind === 'hidePrivate'
            ? await send(current.item.id, current.item.resolveUrl, { resolution: 'hidden', reason })
            : current.kind === 'hide'
              ? await send(current.item.postId, current.item.resolveUrl, {
                    resolution: 'hidden',
                    reason,
                })
              : await send(current.item.postId, current.item.moderationUrl, {
                    action: 'reject',
                    reason,
                });
    if (done) {
        dialogOpen.value = false;
        asking.value = null;
    }
}
</script>

<template>
    <div class="bg-surface shadow">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <ForumBreadcrumbs :items="breadcrumbs" />
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-base-primary sm:text-3xl">
                {{ t('forum.moderation.queueTitle') }}
            </h1>
        </div>
    </div>

    <div class="mx-auto max-w-4xl space-y-10 px-4 py-6 sm:px-6 lg:px-8">
        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

        <section aria-labelledby="forum-reported" class="space-y-4">
            <h2 id="forum-reported" class="text-xl font-semibold text-base-primary">
                {{ t('forum.moderation.reportedTitle') }}
            </h2>
            <EmptyState
                v-if="reported.length === 0"
                icon="document"
                :title="t('forum.moderation.reportedEmpty')"
            />
            <ul v-else class="space-y-4">
                <li
                    v-for="item in reported"
                    :key="item.postId"
                    class="rounded-lg border border-default bg-surface p-4"
                    data-testid="reported-item"
                >
                    <Link
                        :href="item.url"
                        class="font-semibold break-words text-primary hover:underline"
                    >
                        {{ item.discussionTitle }}
                    </Link>
                    <p class="mt-1 text-xs text-base-muted">
                        {{ item.authorName ?? t('forum.posts.deletedAccount') }} ·
                        {{ t('forum.moderation.reportCount', item.reports) }} ·
                        {{ item.firstReportedLabel }}
                    </p>
                    <p class="mt-2 text-sm break-words text-base-primary">{{ item.excerpt }}</p>
                    <p class="mt-2 flex flex-wrap gap-1">
                        <span
                            v-for="reason in item.reasons"
                            :key="reason"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs text-base-secondary"
                            >{{ reason }}</span
                        >
                    </p>
                    <ul
                        v-if="item.notes.length > 0"
                        class="mt-2 space-y-1 text-sm text-base-secondary"
                    >
                        <li v-for="(note, index) in item.notes" :key="index" class="break-words">
                            «{{ note }}»
                        </li>
                    </ul>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            v-if="item.status === 'visible'"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-base-primary hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.postId"
                            @click="ask({ kind: 'hide', item })"
                        >
                            {{ t('forum.moderation.post.hide') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-base-primary hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.postId"
                            @click="settle(item, 'edited')"
                        >
                            {{ t('forum.moderation.settleEdited') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-base-primary hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.postId"
                            @click="settle(item, 'no_action')"
                        >
                            {{ t('forum.moderation.settleNoAction') }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <section
            v-if="privateReports !== null"
            aria-labelledby="forum-private-reported"
            class="space-y-4"
        >
            <h2 id="forum-private-reported" class="text-xl font-semibold text-base-primary">
                {{ t('forum.conversations.moderation.title') }}
            </h2>
            <p class="text-sm text-base-secondary">
                {{ t('forum.conversations.moderation.help') }}
            </p>
            <EmptyState
                v-if="privateReports.length === 0"
                icon="document"
                :title="t('forum.conversations.moderation.empty')"
            />
            <ul v-else class="space-y-4">
                <li
                    v-for="item in privateReports"
                    :key="item.id"
                    class="rounded-lg border border-default bg-surface p-4"
                    data-testid="private-reported-item"
                >
                    <p class="text-sm font-semibold break-words text-base-primary">
                        {{ item.authorName }}
                    </p>
                    <p class="mt-1 text-xs text-base-muted">
                        {{
                            t('forum.conversations.moderation.reportedBy', {
                                date: item.reportedLabel,
                                reason: item.reason,
                            })
                        }}
                    </p>
                    <p v-if="item.note" class="mt-1 text-sm break-words text-base-secondary">
                        {{ t('forum.conversations.moderation.note', { note: item.note }) }}
                    </p>
                    <p v-if="item.messageGone" class="mt-1 text-xs text-base-muted">
                        {{ t('forum.conversations.moderation.gone') }}
                    </p>
                    <ol class="mt-3 space-y-2 border-l-2 border-default pl-3">
                        <li
                            v-for="entry in item.context"
                            :key="entry.number"
                            class="text-sm break-words"
                            :class="
                                entry.reported
                                    ? 'rounded-md bg-primary-light p-2 text-base-primary'
                                    : 'text-base-secondary'
                            "
                        >
                            <template v-if="entry.kind === 'added'">
                                <span class="text-xs text-base-muted">{{
                                    t('forum.conversations.entries.added', {
                                        author: entry.authorName,
                                        name: entry.subjectName ?? '',
                                    })
                                }}</span>
                            </template>
                            <template v-else-if="entry.kind === 'left'">
                                <span class="text-xs text-base-muted">{{
                                    t('forum.conversations.entries.left', {
                                        author: entry.authorName,
                                    })
                                }}</span>
                            </template>
                            <template v-else>
                                <span class="block text-xs font-semibold text-base-muted"
                                    >{{ entry.authorName }} · {{ entry.atLabel }}</span
                                >
                                <span
                                    v-if="entry.kind === 'deleted'"
                                    class="italic text-base-muted"
                                    >{{ t('forum.conversations.moderation.deleted') }}</span
                                >
                                <span v-else class="whitespace-pre-line">{{ entry.text }}</span>
                            </template>
                        </li>
                    </ol>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            v-if="!item.messageGone"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-base-primary hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.id"
                            @click="ask({ kind: 'hidePrivate', item })"
                        >
                            {{ t('forum.conversations.moderation.hide') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-base-primary hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.id"
                            @click="dismissPrivate(item)"
                        >
                            {{ t('forum.conversations.moderation.dismiss') }}
                        </button>
                        <button
                            v-if="suspendUrl && item.authorId"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-red-600 dark:text-red-400 hover:bg-muted"
                            @click="askSuspend(item)"
                        >
                            {{ t('forum.conversations.moderation.suspend') }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <section aria-labelledby="forum-pending" class="space-y-4">
            <h2 id="forum-pending" class="text-xl font-semibold text-base-primary">
                {{ t('forum.moderation.pendingTitle') }}
            </h2>
            <EmptyState
                v-if="pending.length === 0"
                icon="document"
                :title="t('forum.moderation.pendingEmpty')"
            />
            <ul v-else class="space-y-4">
                <li
                    v-for="item in pending"
                    :key="item.postId"
                    class="rounded-lg border border-default bg-surface p-4"
                    data-testid="pending-item"
                >
                    <Link
                        :href="item.url"
                        class="font-semibold break-words text-primary hover:underline"
                    >
                        {{ item.discussionTitle }}
                    </Link>
                    <p class="mt-1 text-xs text-base-muted">
                        {{ item.authorName ?? t('forum.posts.deletedAccount') }} ·
                        {{ item.writtenLabel }}
                        <span v-if="item.opensDiscussion">
                            · {{ t('forum.moderation.opensDiscussion') }}</span
                        >
                    </p>
                    <p class="mt-2 text-sm break-words text-base-primary">{{ item.excerpt }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-base-primary hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.postId"
                            @click="approve(item)"
                        >
                            {{ t('forum.moderation.post.approve') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-default px-3 text-sm text-red-600 dark:text-red-400 hover:bg-muted disabled:opacity-50"
                            :disabled="busy === item.postId"
                            @click="ask({ kind: 'reject', item })"
                        >
                            {{ t('forum.moderation.post.reject') }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <ModerationDialog
        v-model="dialogOpen"
        :title="
            asking?.kind === 'reject'
                ? t('forum.moderation.post.rejectTitle')
                : asking?.kind === 'hidePrivate'
                  ? t('forum.conversations.moderation.hide')
                  : t('forum.moderation.post.hideTitle')
        "
        :message="
            asking?.kind === 'reject'
                ? t('forum.moderation.post.rejectMessage')
                : t('forum.moderation.post.hideMessage')
        "
        :confirm-label="
            asking?.kind === 'reject'
                ? t('forum.moderation.post.reject')
                : t('forum.moderation.post.hide')
        "
        :danger="asking?.kind === 'reject'"
        ask-reason
        :reason-max="asking?.kind === 'reject' ? 500 : 300"
        :confirmation="
            asking?.kind === 'reject' ? t('forum.moderation.post.rejectConfirmation') : null
        "
        :busy="busy !== null"
        :error="dialogError"
        @confirm="confirm"
    />
    <SuspendDialog
        v-if="suspendUrl"
        v-model="suspendOpen"
        :suspend-url="suspendUrl"
        :user-id="suspending?.authorId ?? ''"
        :user-name="suspending?.authorName ?? null"
        @suspended="suspendOpen = false"
    />
</template>
