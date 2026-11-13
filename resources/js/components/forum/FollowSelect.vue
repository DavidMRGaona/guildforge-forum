<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bell, BellOff, BellRing } from '@lucide/vue';
import { FormSelect } from '@/components/form';
import { useMergedDiscussion } from '../../composables/useMergedDiscussion';
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
const reportMerged = useMergedDiscussion();

const LEVELS: ForumFollowLevel[] = ['following', 'normal', 'muted'];

const options = computed(() =>
    LEVELS.map((option) => ({ value: option, label: t(`forum.follow.${option}`) }))
);

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

function isLevel(value: string | number | null): value is ForumFollowLevel {
    return LEVELS.some((option) => option === value);
}

async function change(value: string | number | null): Promise<void> {
    if (!isLevel(value) || value === level.value) {
        return;
    }
    const next = value;
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
    // Merged into another discussion: the server's message says so, and the page shows the way
    reportMerged(answer.mergedInto);
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
        <FormSelect
            :id="`forum-follow-${discussionId}`"
            class="w-full sm:w-64"
            :model-value="level"
            :options="options"
            :disabled="saving"
            @update:model-value="change"
        />
        <span class="text-sm text-base-muted" aria-live="polite">{{ status }}</span>
        <p v-if="error" role="alert" class="w-full text-sm text-red-600">{{ error }}</p>
    </div>
</template>
