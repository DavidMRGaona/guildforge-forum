<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban } from '@lucide/vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import { sendJson } from '../../forum/api';
import type { ForumBlock } from '../../types/forum';

/**
 * «Personas bloqueadas», in the «Foro» tab of the profile: who may not write private messages
 * to the person nor add them to conversations, each with «Desbloquear». Only the person's own
 * list; nobody learns who blocked them.
 */
const { t } = useI18n();

const BLOCKS_URL = '/foro/bloqueos';

const state = ref<'loading' | 'ready' | 'error'>('loading');
const blocks = ref<ForumBlock[]>([]);
const error = ref<string | null>(null);
const status = ref('');

async function load(): Promise<void> {
    state.value = 'loading';
    const answer = await sendJson<{ blocks: ForumBlock[] }>(BLOCKS_URL, 'GET');
    if (answer.ok && answer.data) {
        blocks.value = answer.data.blocks;
        state.value = 'ready';

        return;
    }
    state.value = 'error';
}

async function unblock(block: ForumBlock): Promise<void> {
    error.value = null;
    const answer = await sendJson(block.unblockUrl, 'DELETE');
    if (answer.ok) {
        blocks.value = blocks.value.filter((each) => each.id !== block.id);
        status.value = t('forum.conversations.blocks.unblocked', { name: block.name });

        return;
    }
    error.value = answer.message ?? t('forum.preferences.saveError');
}

onMounted(() => {
    void load();
});
</script>

<template>
    <div class="space-y-2" data-testid="forum-blocks">
        <h3 class="flex items-center gap-2 text-base font-semibold text-base-primary">
            <Ban class="h-4 w-4 text-base-muted" aria-hidden="true" />
            {{ t('forum.conversations.blocks.profileTitle') }}
        </h3>
        <p class="text-sm text-base-muted">{{ t('forum.conversations.blocks.profileHelp') }}</p>
        <p role="status" aria-live="polite" class="sr-only">{{ status }}</p>

        <div v-if="state === 'loading'" class="flex items-center gap-2 text-sm text-base-muted">
            <LoadingSpinner size="sm" />
        </div>
        <div v-else-if="state === 'error'" role="alert" class="space-y-2 text-sm">
            <p class="text-base-secondary">{{ t('forum.conversations.blocks.loadError') }}</p>
            <button
                type="button"
                class="inline-flex min-h-[44px] items-center rounded-md border border-default px-4 text-sm font-medium text-base-primary hover:bg-muted"
                @click="load"
            >
                {{ t('forum.preferences.retry') }}
            </button>
        </div>
        <p v-else-if="blocks.length === 0" class="text-sm text-base-muted">
            {{ t('forum.conversations.blocks.profileEmpty') }}
        </p>
        <ul v-else class="divide-y divide-default rounded-lg border border-default">
            <li
                v-for="block in blocks"
                :key="block.id"
                class="flex flex-wrap items-center justify-between gap-2 px-4 py-2"
            >
                <span class="min-w-0 break-words text-sm text-base-primary">
                    {{ block.name }}
                    <span class="block text-xs text-base-muted">{{
                        t('forum.conversations.blocks.since', { date: block.sinceLabel })
                    }}</span>
                </span>
                <button
                    type="button"
                    class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                    :data-testid="`unblock-${block.id}`"
                    @click="unblock(block)"
                >
                    {{ t('forum.conversations.blocks.unblock') }}
                    <span class="sr-only">{{ block.name }}</span>
                </button>
            </li>
        </ul>
        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>
