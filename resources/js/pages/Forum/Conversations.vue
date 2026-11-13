<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Mail, Plus } from '@lucide/vue';
import HelpLink from '@/components/knowledge/HelpLink.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import type { ForumConversationRow, ForumConversationPagination } from '../../types/forum';

/**
 * «Mensajes privados»: the conversations the person is in, the latest message first, with how
 * many messages they have not read in each.
 */
const props = defineProps<{
    conversations: ForumConversationRow[];
    pagination: ForumConversationPagination;
    /** Null when the person may not start conversations (suspended in the forum) */
    newUrl: string | null;
    startsLeft: number;
    barred: boolean;
    forumUrl: string;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [{ name: t('forum.title'), url: props.forumUrl }]);

function peopleOf(row: ForumConversationRow): string {
    if (row.people.length === 0) {
        return t('forum.conversations.list.alone');
    }

    const names = row.people.join(', ');

    return row.morePeople > 0
        ? t('forum.conversations.list.more', { names, count: row.morePeople }, row.morePeople)
        : names;
}
</script>

<template>
    <div class="bg-surface shadow">
        <div
            class="mx-auto flex max-w-4xl flex-col gap-4 px-4 py-6 sm:flex-row sm:items-end sm:justify-between sm:px-6 lg:px-8"
        >
            <div class="min-w-0">
                <ForumBreadcrumbs :items="breadcrumbs" />
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-base-primary sm:text-3xl">
                    {{ t('forum.conversations.title') }}
                    <HelpLink guide="forum.site.private-messages" class="ml-1 align-middle" />
                </h1>
                <p class="mt-1 text-sm text-base-secondary">
                    {{ t('forum.conversations.description') }}
                </p>
            </div>
            <div v-if="newUrl" class="flex flex-col items-start gap-1 sm:items-end">
                <Link
                    v-if="startsLeft > 0"
                    :href="newUrl"
                    class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                    data-testid="conversations-new"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    {{ t('forum.conversations.new') }}
                </Link>
                <p class="text-xs text-base-muted" data-testid="conversations-starts-left">
                    {{
                        startsLeft > 0
                            ? t('forum.conversations.startsLeft', { count: startsLeft }, startsLeft)
                            : t('forum.conversations.noStartsLeft')
                    }}
                </p>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
        <p
            v-if="barred"
            class="mb-4 rounded-md border border-default bg-muted p-3 text-sm text-base-secondary"
            role="note"
            data-testid="conversations-barred"
        >
            {{ t('forum.conversations.barred') }}
        </p>

        <ul
            v-if="conversations.length > 0"
            class="overflow-hidden rounded-lg border border-default bg-surface"
            data-testid="conversation-list"
        >
            <li
                v-for="row in conversations"
                :key="row.id"
                class="flex flex-col gap-1 border-t border-default p-4 first:border-t-0 sm:flex-row sm:items-center sm:gap-4"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-start gap-2">
                        <span
                            class="mt-2 inline-block h-2.5 w-2.5 shrink-0 rounded-full"
                            :class="row.unread > 0 ? 'bg-primary' : 'bg-transparent'"
                            aria-hidden="true"
                        />
                        <p class="min-w-0">
                            <Link
                                :href="row.url"
                                class="break-words rounded-sm text-base hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                :class="
                                    row.unread > 0
                                        ? 'font-semibold text-base-primary'
                                        : 'font-medium text-base-secondary'
                                "
                            >
                                {{ row.title }}
                                <span v-if="row.unread > 0" class="sr-only">
                                    ({{
                                        t(
                                            'forum.conversations.list.unread',
                                            { count: row.unread },
                                            row.unread
                                        )
                                    }})
                                </span>
                            </Link>
                            <span
                                v-if="row.unread > 0"
                                class="ml-2 inline-flex items-center rounded-full bg-primary-light px-2 py-0.5 align-middle text-xs font-medium text-primary"
                                aria-hidden="true"
                                data-testid="conversation-unread"
                            >
                                {{ row.unread }}
                            </span>
                        </p>
                    </div>
                    <p class="mt-1 break-words pl-[1.125rem] text-sm text-base-muted">
                        {{ peopleOf(row) }}
                    </p>
                </div>
                <time
                    :datetime="row.lastActivityIso ?? undefined"
                    class="shrink-0 pl-[1.125rem] text-xs text-base-muted sm:pl-0"
                >
                    {{ row.lastActivityLabel }}
                </time>
            </li>
        </ul>
        <EmptyState
            v-else
            icon="document"
            :title="t('forum.conversations.empty')"
            :description="t('forum.conversations.emptyDescription')"
        />

        <nav
            v-if="pagination.lastPage > 1"
            class="mt-6 flex items-center justify-between gap-3"
            :aria-label="t('forum.conversations.list.pages')"
        >
            <Link
                v-if="pagination.previousUrl"
                :href="pagination.previousUrl"
                class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium text-primary hover:underline"
            >
                {{ t('forum.conversations.list.newer') }}
            </Link>
            <span v-else />
            <span class="text-sm text-base-muted">
                {{
                    t('forum.conversations.list.page', {
                        page: pagination.page,
                        last: pagination.lastPage,
                    })
                }}
            </span>
            <Link
                v-if="pagination.nextUrl"
                :href="pagination.nextUrl"
                class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm font-medium text-primary hover:underline"
            >
                {{ t('forum.conversations.list.older') }}
            </Link>
            <span v-else />
        </nav>

        <p class="mt-6 flex items-center gap-2 text-xs text-base-muted">
            <Mail class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('forum.conversations.privacy') }}
        </p>
    </div>
</template>
