<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { Ban, Flag, LogOut, Trash2, UserPlus } from '@lucide/vue';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PeoplePicker from '../../components/conversations/PeoplePicker.vue';
import PostEditor from '../../components/editor/PostEditor.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import ReportDialog from '../../components/moderation/ReportDialog.vue';
import { sendJson } from '../../forum/api';
import { CONVERSATION_EVENTS, conversationChannel, useChannelFeed } from '../../live/channels';
import type { LiveConnection } from '../../live/transport';
import { liveConversation } from '../../live/transports';
import type {
    ForumConversationEntry,
    ForumConversationParticipant,
    ForumConversationPagination,
    ForumConversationUpdates,
    ForumPerson,
} from '../../types/forum';

/**
 * One private conversation: who is in it, its messages and the notes of who joined or left,
 * and the box to write. Anyone in it adds someone (up to ten) or leaves; each person may block
 * any of the others, which nobody is told about.
 */
const props = defineProps<{
    conversation: { id: string; title: string; url: string };
    participants: ForumConversationParticipant[];
    entries: ForumConversationEntry[];
    pagination: ForumConversationPagination;
    write: { refusal: string | null; storeUrl: string; readUrl: string };
    /** Null when nobody else fits or the person may not add people */
    addUrl: string | null;
    peopleUrl: string;
    leaveUrl: string;
    blockUrl: string;
    /** The unblock address with a placeholder id, replaced for each person */
    unblockUrlTemplate: string;
    indexUrl: string;
    maxPeople: number;
}>();

const PLACEHOLDER_ID = '00000000-0000-4000-8000-000000000000';

const { t } = useI18n();

const list = ref<ForumConversationEntry[]>([...props.entries]);
const sending = ref(false);
const replyError = ref<string | null>(null);
const editor = ref<InstanceType<typeof PostEditor> | null>(null);
const adding = ref(false);
const addError = ref<string | null>(null);
const leaving = ref(false);
const leaveError = ref<string | null>(null);
const blockTarget = ref<ForumConversationParticipant | null>(null);
const blockOpen = ref(false);
const actionError = ref<string | null>(null);
const reportTarget = ref<ForumConversationEntry | null>(null);
const reportOpen = ref(false);
const reported = ref<Set<string>>(new Set());
const deleteTarget = ref<ForumConversationEntry | null>(null);
const deleteOpen = ref(false);
const status = ref('');

const breadcrumbs = computed(() => [{ name: t('forum.conversations.title'), url: props.indexUrl }]);
const onLastPage = computed(() => props.pagination.nextUrl === null);

// What the others write while the last page is open: through the conversation's real-time
// channel when there is one, by consultation otherwise (forum#89). Only its people may listen
const channel = useChannelFeed(
    () => conversationChannel(props.conversation.id),
    CONVERSATION_EVENTS
);
const live = liveConversation(props.conversation.id, channel);
let connection: LiveConnection | null = null;
const highest = computed(() => list.value.reduce((top, entry) => Math.max(top, entry.number), 0));

function receive(update: ForumConversationUpdates): void {
    const known = new Set(list.value.map((entry) => entry.id));
    const incoming = new Map(update.entries.map((entry) => [entry.id, entry]));
    const fresh = update.entries.filter((entry) => !known.has(entry.id));
    list.value = [...list.value.map((entry) => incoming.get(entry.id) ?? entry), ...fresh].sort(
        (a, b) => a.number - b.number
    );

    if (fresh.some((entry) => entry.kind !== 'message')) {
        // Someone joined or left: who is in it changed
        router.reload({ only: ['participants'] });
    }
    const fromOthers = fresh.filter((entry) => entry.kind === 'message' && !entry.isMine).length;
    if (fromOthers > 0) {
        status.value = t('forum.live.newPosts', { count: fromOthers }, fromOthers);
        // Showing it on the open page reads it, as opening the page does
        if (document.visibilityState === 'visible') {
            void sendJson(props.write.readUrl, 'POST', { number: highest.value });
        }
    }
    if (update.more) {
        connection?.refresh();
    }
}

onMounted(() => {
    if (onLastPage.value) {
        connection = live.connect(
            {
                cursor: () => highest.value,
                holds: (number) => list.value.some((entry) => entry.number === number),
            },
            receive
        );
    }
});
onBeforeUnmount(() => {
    connection?.stop();
    connection = null;
});

function noteOf(entry: ForumConversationEntry): string {
    return entry.kind === 'added'
        ? t('forum.conversations.entries.added', {
              author: entry.authorName,
              name: entry.subjectName ?? '',
          })
        : t('forum.conversations.entries.left', { author: entry.authorName });
}

async function reply(document: JSONContent): Promise<void> {
    replyError.value = null;
    sending.value = true;
    const answer = await sendJson<{ entry: ForumConversationEntry }>(props.write.storeUrl, 'POST', {
        document,
    });
    sending.value = false;

    if (answer.ok && answer.data) {
        editor.value?.clear();
        if (onLastPage.value) {
            list.value = [...list.value, answer.data.entry];
        } else {
            router.visit(props.conversation.url);
        }
        status.value = t('forum.conversations.sent');

        return;
    }

    replyError.value =
        answer.status === 419 || answer.status === 401
            ? t('forum.editor.errors.session')
            : (answer.message ?? t('forum.posts.errors.save'));
}

async function add(person: ForumPerson): Promise<void> {
    if (props.addUrl === null) {
        return;
    }
    addError.value = null;
    const answer = await sendJson(props.addUrl, 'POST', { account: person.id });
    if (answer.ok) {
        adding.value = false;
        router.reload();

        return;
    }
    addError.value = answer.message ?? t('forum.moderation.errors.generic');
}

async function leave(): Promise<void> {
    leaveError.value = null;
    const answer = await sendJson<{ url: string }>(props.leaveUrl, 'POST');
    if (answer.ok && answer.data) {
        router.visit(answer.data.url);

        return;
    }
    leaveError.value = answer.message ?? t('forum.moderation.errors.generic');
}

function askBlock(person: ForumConversationParticipant): void {
    blockTarget.value = person;
    blockOpen.value = true;
}

async function block(): Promise<void> {
    const person = blockTarget.value;
    if (person === null) {
        return;
    }
    actionError.value = null;
    const answer = await sendJson(props.blockUrl, 'POST', { account: person.id });
    if (answer.ok) {
        router.reload({ only: ['participants'] });
        status.value = t('forum.conversations.blocks.blocked', { name: person.name });

        return;
    }
    actionError.value = answer.message ?? t('forum.moderation.errors.generic');
}

async function unblock(person: ForumConversationParticipant): Promise<void> {
    actionError.value = null;
    const answer = await sendJson(
        props.unblockUrlTemplate.replace(PLACEHOLDER_ID, person.id),
        'DELETE'
    );
    if (answer.ok) {
        router.reload({ only: ['participants'] });
        status.value = t('forum.conversations.blocks.unblocked', { name: person.name });

        return;
    }
    actionError.value = answer.message ?? t('forum.moderation.errors.generic');
}

function askReport(entry: ForumConversationEntry): void {
    reportTarget.value = entry;
    reportOpen.value = true;
}

function onReported(): void {
    if (reportTarget.value) {
        reported.value = new Set([...reported.value, reportTarget.value.id]);
    }
    status.value = t('forum.reports.sent');
}

function askDelete(entry: ForumConversationEntry): void {
    deleteTarget.value = entry;
    deleteOpen.value = true;
}

async function remove(): Promise<void> {
    const entry = deleteTarget.value;
    if (entry === null || entry.deleteUrl === null) {
        return;
    }
    actionError.value = null;
    const answer = await sendJson<{ entry: ForumConversationEntry }>(entry.deleteUrl, 'DELETE');
    if (answer.ok && answer.data) {
        const updated = answer.data.entry;
        list.value = list.value.map((each) => (each.id === updated.id ? updated : each));
        status.value = t('forum.conversations.entries.deletedDone');

        return;
    }
    actionError.value = answer.message ?? t('forum.moderation.errors.generic');
}
</script>

<template>
    <div class="bg-surface shadow">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <ForumBreadcrumbs :items="breadcrumbs" />
            <h1
                class="mt-2 break-words text-2xl font-bold tracking-tight text-base-primary sm:text-3xl"
            >
                {{ conversation.title }}
                <HelpLink guide="forum.site.private-messages" class="ml-1 align-middle" />
            </h1>

            <section class="mt-4" aria-labelledby="conversation-people-title">
                <h2 id="conversation-people-title" class="text-sm font-medium text-base-secondary">
                    {{
                        t(
                            'forum.conversations.people.title',
                            { count: participants.length, max: maxPeople },
                            participants.length
                        )
                    }}
                </h2>
                <ul class="mt-2 flex flex-wrap gap-2" data-testid="conversation-participants">
                    <li
                        v-for="person in participants"
                        :key="person.id"
                        class="inline-flex max-w-full items-center gap-1 rounded-full border border-default py-1 pl-3 text-sm text-base-primary"
                        :class="person.isMe ? 'pr-3' : 'pr-1'"
                    >
                        <span class="truncate">
                            {{ person.isMe ? t('forum.conversations.people.me') : person.name }}
                        </span>
                        <span v-if="person.blocked" class="text-xs text-base-muted">
                            ({{ t('forum.conversations.blocks.badge') }})
                        </span>
                        <button
                            v-if="!person.isMe && !person.blocked"
                            type="button"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base-muted hover:bg-muted hover:text-base-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                            :aria-label="
                                t('forum.conversations.blocks.block', { name: person.name })
                            "
                            :title="t('forum.conversations.blocks.block', { name: person.name })"
                            :data-testid="`block-${person.id}`"
                            @click="askBlock(person)"
                        >
                            <Ban class="h-4 w-4" aria-hidden="true" />
                        </button>
                        <button
                            v-else-if="!person.isMe"
                            type="button"
                            class="inline-flex min-h-[36px] shrink-0 items-center rounded-full px-2 text-xs font-medium text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary-500"
                            :data-testid="`unblock-${person.id}`"
                            @click="unblock(person)"
                        >
                            {{ t('forum.conversations.blocks.unblock') }}
                            <span class="sr-only">{{ person.name }}</span>
                        </button>
                    </li>
                </ul>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-if="addUrl && !adding"
                        type="button"
                        class="inline-flex min-h-[44px] items-center gap-2 rounded-md px-3 text-sm font-medium text-base-secondary hover:bg-muted hover:text-base-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                        data-testid="conversation-add"
                        @click="adding = true"
                    >
                        <UserPlus class="h-4 w-4" aria-hidden="true" />
                        {{ t('forum.conversations.people.addSomeone') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center gap-2 rounded-md px-3 text-sm font-medium text-base-secondary hover:bg-muted hover:text-base-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                        data-testid="conversation-leave"
                        @click="leaving = true"
                    >
                        <LogOut class="h-4 w-4" aria-hidden="true" />
                        {{ t('forum.conversations.leave.button') }}
                    </button>
                </div>
                <div v-if="adding && addUrl" class="mt-3 max-w-md space-y-2">
                    <PeoplePicker
                        :label="t('forum.conversations.people.addSomeone')"
                        :people-url="peopleUrl"
                        :max="1"
                        single
                        focus-on-open
                        :error="addError"
                        @pick="add"
                    />
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center text-sm font-medium text-base-secondary hover:underline"
                        @click="adding = false"
                    >
                        {{ t('common.cancel') }}
                    </button>
                </div>
                <p v-if="leaveError" role="alert" class="mt-2 text-sm text-red-600">
                    {{ leaveError }}
                </p>
                <p v-if="actionError" role="alert" class="mt-2 text-sm text-red-600">
                    {{ actionError }}
                </p>
            </section>
        </div>
    </div>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <p role="status" aria-live="polite" class="sr-only">{{ status }}</p>

        <Link
            v-if="pagination.previousUrl"
            :href="pagination.previousUrl"
            class="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline"
        >
            {{ t('forum.conversations.entries.earlier') }}
        </Link>

        <ol class="space-y-4" data-testid="conversation-entries">
            <li v-for="entry in list" :key="entry.id" :data-testid="`entry-${entry.number}`">
                <p
                    v-if="entry.state === 'note'"
                    class="text-center text-xs text-base-muted"
                    data-testid="entry-note"
                >
                    {{ noteOf(entry) }} ·
                    <time :datetime="entry.createdIso ?? undefined">{{ entry.createdLabel }}</time>
                </p>
                <article
                    v-else
                    class="rounded-lg border border-default bg-surface p-4"
                    :class="entry.isMine ? 'sm:ml-12' : 'sm:mr-12'"
                    :aria-labelledby="`entry-author-${entry.id}`"
                >
                    <header class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p
                            :id="`entry-author-${entry.id}`"
                            class="min-w-0 break-words text-sm font-semibold text-base-primary"
                        >
                            {{
                                entry.isMine ? t('forum.conversations.people.me') : entry.authorName
                            }}
                        </p>
                        <time
                            :datetime="entry.createdIso ?? undefined"
                            class="text-xs text-base-muted"
                        >
                            {{ entry.createdLabel }}
                        </time>
                    </header>
                    <!-- Rendered by the server from the stored document, with every text escaped -->
                    <!-- eslint-disable vue/no-v-html -->
                    <div
                        v-if="entry.state === 'shown' && entry.html !== null"
                        class="forum-post prose mt-2 max-w-none break-words text-base-primary dark:prose-invert"
                        v-html="entry.html"
                    />
                    <!-- eslint-enable vue/no-v-html -->
                    <p
                        v-else-if="entry.state === 'deleted'"
                        class="mt-2 text-sm italic text-base-muted"
                    >
                        {{ t('forum.conversations.entries.deleted') }}
                    </p>
                    <p v-else class="mt-2 text-sm italic text-base-muted">
                        {{ t('forum.conversations.entries.hidden') }}
                        <span v-if="entry.hiddenReason" class="block not-italic">
                            {{
                                t('forum.conversations.entries.hiddenReason', {
                                    reason: entry.hiddenReason,
                                })
                            }}
                        </span>
                    </p>
                    <footer
                        v-if="entry.deleteUrl || entry.reportUrl"
                        class="mt-3 flex flex-wrap justify-end gap-2"
                    >
                        <button
                            v-if="entry.deleteUrl"
                            type="button"
                            class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-2 text-xs font-medium text-base-muted hover:bg-muted hover:text-base-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                            :data-testid="`delete-${entry.number}`"
                            @click="askDelete(entry)"
                        >
                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                            {{ t('forum.conversations.entries.delete') }}
                        </button>
                        <p
                            v-if="entry.reportUrl && reported.has(entry.id)"
                            class="text-xs text-base-muted"
                        >
                            {{ t('forum.reports.sent') }}
                        </p>
                        <button
                            v-else-if="entry.reportUrl"
                            type="button"
                            class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-2 text-xs font-medium text-base-muted hover:bg-muted hover:text-base-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                            :data-testid="`report-${entry.number}`"
                            @click="askReport(entry)"
                        >
                            <Flag class="h-4 w-4" aria-hidden="true" />
                            {{ t('forum.conversations.entries.report') }}
                        </button>
                    </footer>
                </article>
            </li>
        </ol>

        <Link
            v-if="pagination.nextUrl"
            :href="pagination.nextUrl"
            class="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline"
        >
            {{ t('forum.conversations.entries.later') }}
        </Link>

        <section aria-labelledby="conversation-reply-title" class="pt-2">
            <h2 id="conversation-reply-title" class="sr-only">
                {{ t('forum.conversations.replyLabel') }}
            </h2>
            <p
                v-if="write.refusal"
                class="rounded-md border border-default bg-muted p-3 text-sm text-base-secondary"
                role="note"
                data-testid="conversation-refusal"
            >
                {{ write.refusal }}
            </p>
            <PostEditor
                v-else
                ref="editor"
                :label="t('forum.conversations.replyLabel')"
                :draft-key="`forum-conversation-${conversation.id}`"
                text-only
                help-guide="forum.site.private-messages"
                :submit-label="t('forum.conversations.send')"
                :submitting="sending"
                :error="replyError"
                @submit="reply"
            />
        </section>
    </div>

    <ConfirmDialog
        v-model="blockOpen"
        :title="t('forum.conversations.blocks.confirmTitle', { name: blockTarget?.name ?? '' })"
        :message="t('forum.conversations.blocks.confirmMessage')"
        :confirm-label="t('forum.conversations.blocks.confirm')"
        :cancel-label="t('common.cancel')"
        confirm-variant="danger"
        @confirm="block"
    />
    <ConfirmDialog
        v-model="leaving"
        :title="t('forum.conversations.leave.title')"
        :message="t('forum.conversations.leave.message')"
        :confirm-label="t('forum.conversations.leave.confirm')"
        :cancel-label="t('common.cancel')"
        confirm-variant="danger"
        @confirm="leave"
    />
    <ConfirmDialog
        v-model="deleteOpen"
        :title="t('forum.conversations.entries.deleteTitle')"
        :message="t('forum.conversations.entries.deleteMessage')"
        :confirm-label="t('forum.conversations.entries.delete')"
        :cancel-label="t('common.cancel')"
        confirm-variant="danger"
        @confirm="remove"
    />
    <ReportDialog
        v-model="reportOpen"
        :report-url="reportTarget?.reportUrl ?? ''"
        :post-number="null"
        :description="t('forum.conversations.reportDescription')"
        @reported="onReported"
    />
</template>
