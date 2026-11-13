<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { MessagesSquare } from '@lucide/vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import { useVerificationGate } from '@/composables/useVerificationGate';
import { sendJson } from '../../forum/api';
import ForumBlocks from './ForumBlocks.vue';
import type { ForumPreferences } from '../../types/forum';

/**
 * The «Foro» tab of the profile: whether starting or replying to a discussion follows it, and
 * the categories and discussions the person follows, each with «Dejar de seguir», and the
 * people the person blocked from private messages.
 */
const { t } = useI18n();
const gate = useVerificationGate();

const PREFERENCES_URL = '/foro/preferencias';

type LoadState = 'loading' | 'ready' | 'error';

const state = ref<LoadState>('loading');
const preferences = ref<ForumPreferences | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    state.value = 'loading';
    try {
        const response = await fetch(PREFERENCES_URL, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok || gate.isVerificationRequired(response)) {
            state.value = 'error';

            return;
        }
        preferences.value = (await response.json()) as ForumPreferences;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

async function setAutoFollow(event: Event): Promise<void> {
    if (preferences.value === null) {
        return;
    }
    const wanted = (event.target as HTMLInputElement).checked;
    saving.value = true;
    error.value = null;
    const answer = await sendJson<{ autoFollow: boolean }>(preferences.value.updateUrl, 'PUT', {
        autoFollow: wanted,
    });
    saving.value = false;
    if (answer.ok && answer.data) {
        preferences.value.autoFollow = answer.data.autoFollow;

        return;
    }
    (event.target as HTMLInputElement).checked = !wanted;
    error.value = answer.message ?? t('forum.preferences.saveError');
}

async function unfollowCategory(id: string, url: string): Promise<void> {
    error.value = null;
    const answer = await sendJson(url, 'PUT', { follow: false });
    if (answer.ok && preferences.value) {
        preferences.value.categories = preferences.value.categories.filter((c) => c.id !== id);

        return;
    }
    error.value = answer.message ?? t('forum.preferences.saveError');
}

async function unfollowDiscussion(id: string, url: string): Promise<void> {
    error.value = null;
    const answer = await sendJson(url, 'PUT', { level: 'normal' });
    if (answer.ok && preferences.value) {
        preferences.value.discussions = preferences.value.discussions.filter((d) => d.id !== id);

        return;
    }
    error.value = answer.message ?? t('forum.preferences.saveError');
}

onMounted(() => {
    void load();
});
</script>

<template>
    <section aria-labelledby="forum-preferences-title" class="space-y-6">
        <div class="flex items-center gap-2">
            <MessagesSquare class="h-5 w-5 text-primary" aria-hidden="true" />
            <h2 id="forum-preferences-title" class="text-lg font-semibold text-base-primary">
                {{ t('forum.preferences.title') }}
            </h2>
        </div>

        <div v-if="state === 'loading'" class="flex items-center gap-2 text-sm text-base-muted">
            <LoadingSpinner size="sm" />
            <span>{{ t('forum.preferences.loading') }}</span>
        </div>

        <div v-else-if="state === 'error'" role="alert" class="space-y-2 text-sm">
            <p class="text-base-secondary">{{ t('forum.preferences.loadError') }}</p>
            <button
                type="button"
                class="inline-flex min-h-[44px] items-center rounded-md border border-default px-4 text-sm font-medium text-base-primary hover:bg-muted"
                @click="load"
            >
                {{ t('forum.preferences.retry') }}
            </button>
        </div>

        <template v-else-if="preferences">
            <p class="text-sm text-base-secondary">{{ t('forum.preferences.intro') }}</p>

            <div class="rounded-lg border border-default bg-surface p-4">
                <label class="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        class="mt-1 h-5 w-5 shrink-0 rounded border-default text-primary focus:ring-primary-500"
                        :checked="preferences.autoFollow"
                        :disabled="saving"
                        aria-describedby="forum-auto-follow-hint"
                        @change="setAutoFollow"
                    />
                    <span>
                        <span class="block text-sm font-medium text-base-primary">
                            {{ t('forum.preferences.autoFollow') }}
                        </span>
                        <span id="forum-auto-follow-hint" class="block text-sm text-base-muted">
                            {{ t('forum.preferences.autoFollowHint') }}
                        </span>
                    </span>
                </label>
            </div>

            <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

            <div class="space-y-2">
                <h3 class="text-base font-semibold text-base-primary">
                    {{ t('forum.preferences.categories') }}
                </h3>
                <p v-if="preferences.categories.length === 0" class="text-sm text-base-muted">
                    {{ t('forum.preferences.noCategories') }}
                </p>
                <ul
                    v-else
                    class="divide-y divide-[var(--color-border)] rounded-lg border border-default"
                >
                    <li
                        v-for="category in preferences.categories"
                        :key="category.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-2"
                    >
                        <a
                            :href="category.url"
                            class="min-w-0 break-words text-sm font-medium text-primary hover:underline"
                        >
                            <span v-if="category.parentName" class="text-base-muted"
                                >{{ category.parentName }} ›
                            </span>
                            {{ category.name }}
                        </a>
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                            :aria-label="
                                t('forum.preferences.unfollowLabel', { name: category.name })
                            "
                            @click="unfollowCategory(category.id, category.followUrl)"
                        >
                            {{ t('forum.preferences.unfollow') }}
                        </button>
                    </li>
                </ul>
            </div>

            <div class="space-y-2">
                <h3 class="text-base font-semibold text-base-primary">
                    {{ t('forum.preferences.discussions') }}
                </h3>
                <p v-if="preferences.discussions.length === 0" class="text-sm text-base-muted">
                    {{ t('forum.preferences.noDiscussions') }}
                </p>
                <ul
                    v-else
                    class="divide-y divide-[var(--color-border)] rounded-lg border border-default"
                >
                    <li
                        v-for="discussion in preferences.discussions"
                        :key="discussion.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-2"
                    >
                        <a
                            :href="discussion.url"
                            class="min-w-0 break-words text-sm font-medium text-primary hover:underline"
                        >
                            {{ discussion.title }}
                        </a>
                        <button
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                            :aria-label="
                                t('forum.preferences.unfollowLabel', { name: discussion.title })
                            "
                            @click="unfollowDiscussion(discussion.id, discussion.followUrl)"
                        >
                            {{ t('forum.preferences.unfollow') }}
                        </button>
                    </li>
                </ul>
            </div>

            <ForumBlocks />

            <a
                :href="preferences.forumUrl"
                class="inline-block text-sm text-primary hover:underline"
            >
                {{ t('forum.preferences.goToForum') }}
            </a>
        </template>
    </section>
</template>
