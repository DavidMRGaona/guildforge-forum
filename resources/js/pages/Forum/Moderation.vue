<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import ModerationDialog, {
    type ModerationDialogFields,
} from '../../components/moderation/ModerationDialog.vue';
import { sendJson } from '../../forum/api';
import type { ForumPendingItem, ForumReportedItem } from '../../types/forum';

/**
 * «Moderación» in the forum: reported posts and posts awaiting approval in what the person
 * moderates. Hiding and rejecting ask first (a reason; rejecting, a box to tick); the rest
 * runs at once. Every answer reloads the lists.
 */
defineProps<{
    reported: ForumReportedItem[];
    pending: ForumPendingItem[];
}>();

type Pending =
    { kind: 'hide'; item: ForumReportedItem } | { kind: 'reject'; item: ForumPendingItem };

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
        router.reload({ only: ['reported', 'pending'] });

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
        current.kind === 'hide'
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
</template>
