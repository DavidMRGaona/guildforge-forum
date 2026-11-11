<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bell, BellOff, BellRing } from '@lucide/vue';
import { sendJson } from '../../forum/api';
import type { ForumDiscussionFollow, ForumFollowLevel } from '../../types/forum';

/**
 * «Seguir», «Normal» or «Silenciar» for the discussion, saved as soon as it changes.
 */
const props = defineProps<{
    follow: ForumDiscussionFollow;
    discussionId: string;
}>();

const { t } = useI18n();

const LEVELS: ForumFollowLevel[] = ['following', 'normal', 'muted'];

const level = ref<ForumFollowLevel>(props.follow.level);
const saving = ref(false);
const status = ref<string>('');
const error = ref<string | null>(null);

watch(
    () => props.follow.level,
    (value) => {
        level.value = value;
    }
);

async function change(event: Event): Promise<void> {
    const next = (event.target as HTMLSelectElement).value as ForumFollowLevel;
    const previous = level.value;
    level.value = next;
    saving.value = true;
    status.value = '';
    error.value = null;

    const answer = await sendJson<{ level: ForumFollowLevel }>(props.follow.url, 'PUT', {
        level: next,
    });
    saving.value = false;

    if (answer.ok && answer.data) {
        level.value = answer.data.level;
        status.value = t('forum.follow.saved');

        return;
    }

    level.value = previous;
    error.value = answer.message ?? t('forum.follow.error');
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <label
            :for="`forum-follow-${discussionId}`"
            class="inline-flex items-center gap-1 text-sm font-medium text-base-secondary"
        >
            <BellRing v-if="level === 'following'" class="h-4 w-4" aria-hidden="true" />
            <BellOff v-else-if="level === 'muted'" class="h-4 w-4" aria-hidden="true" />
            <Bell v-else class="h-4 w-4" aria-hidden="true" />
            {{ t('forum.follow.label') }}
        </label>
        <select
            :id="`forum-follow-${discussionId}`"
            class="min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-sm text-base-primary sm:w-64"
            :value="level"
            :disabled="saving"
            @change="change"
        >
            <option v-for="option in LEVELS" :key="option" :value="option">
                {{ t(`forum.follow.${option}`) }}
            </option>
        </select>
        <span class="text-sm text-base-muted" aria-live="polite">{{ status }}</span>
        <p v-if="error" role="alert" class="w-full text-sm text-red-600">{{ error }}</p>
    </div>
</template>
