<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { BellPlus, BellRing } from '@lucide/vue';
import { sendJson } from '../../forum/api';
import type { ForumCategoryFollow } from '../../types/forum';

/**
 * Following a category: a notice for every new discussion started in it.
 */
const props = defineProps<{
    follow: ForumCategoryFollow;
}>();

const { t } = useI18n();

const following = ref(props.follow.following);
const busy = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.follow.following,
    (value) => {
        following.value = value;
    }
);

async function toggle(): Promise<void> {
    busy.value = true;
    error.value = null;
    const answer = await sendJson<{ following: boolean }>(props.follow.url, 'PUT', {
        follow: !following.value,
    });
    busy.value = false;

    if (answer.ok && answer.data) {
        following.value = answer.data.following;

        return;
    }

    error.value = answer.message ?? t('forum.follow.error');
}
</script>

<template>
    <div>
        <button
            type="button"
            class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md border px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
            :class="
                following
                    ? 'border-primary bg-primary-light text-primary'
                    : 'border-default bg-surface text-base-secondary hover:bg-muted hover:text-base-primary'
            "
            :aria-pressed="following"
            :title="t('forum.follow.categoryHint')"
            :disabled="busy"
            @click="toggle"
        >
            <BellRing v-if="following" class="h-4 w-4" aria-hidden="true" />
            <BellPlus v-else class="h-4 w-4" aria-hidden="true" />
            {{ following ? t('forum.follow.categoryFollowing') : t('forum.follow.category') }}
        </button>
        <p v-if="error" role="alert" class="mt-1 text-sm text-red-600">{{ error }}</p>
    </div>
</template>
