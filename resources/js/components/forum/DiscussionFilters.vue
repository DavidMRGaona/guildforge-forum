<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { ForumListFilters } from '../../types/forum';

/**
 * «Todos / Sin leer» and the game-system tag. Each change loads the list from its first page,
 * keeping the other filter.
 */
const props = defineProps<{
    filters: ForumListFilters;
}>();

const { t } = useI18n();

const topicId = computed(() => `forum-topic-${props.filters.baseUrl.replace(/[^a-z0-9]/gi, '-')}`);

function visit(next: { unread?: boolean; topic?: string | null }): void {
    const unread = next.unread ?? props.filters.unread;
    const topic = next.topic === undefined ? props.filters.topic : next.topic;
    const query: Record<string, string> = {};
    if (unread) {
        query['sin-leer'] = '1';
    }
    if (topic) {
        query.etiqueta = topic;
    }

    router.get(props.filters.baseUrl, query, {
        preserveScroll: true,
        only: ['discussions', 'filters'],
    });
}

function onTopicChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    visit({ topic: value === '' ? null : value });
}
</script>

<template>
    <div
        v-if="filters.canFilterUnread || filters.topics.length > 0"
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <div
            v-if="filters.canFilterUnread"
            role="group"
            :aria-label="t('forum.filters.show')"
            class="inline-flex w-full rounded-md border border-default bg-surface p-1 sm:w-auto"
        >
            <button
                type="button"
                class="min-h-[40px] flex-1 rounded px-4 text-sm font-medium sm:flex-none"
                :class="
                    !filters.unread ? 'bg-primary text-white' : 'text-base-secondary hover:bg-muted'
                "
                :aria-pressed="!filters.unread"
                @click="visit({ unread: false })"
            >
                {{ t('forum.filters.all') }}
            </button>
            <button
                type="button"
                class="min-h-[40px] flex-1 rounded px-4 text-sm font-medium sm:flex-none"
                :class="
                    filters.unread ? 'bg-primary text-white' : 'text-base-secondary hover:bg-muted'
                "
                :aria-pressed="filters.unread"
                @click="visit({ unread: true })"
            >
                {{ t('forum.filters.unread') }}
            </button>
        </div>

        <div v-if="filters.topics.length > 0" class="flex items-center gap-2">
            <label :for="topicId" class="text-sm font-medium text-base-secondary">
                {{ t('forum.filters.topic') }}
            </label>
            <select
                :id="topicId"
                class="min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-sm text-base-primary sm:w-56"
                :value="filters.topic ?? ''"
                @change="onTopicChange"
            >
                <option value="">{{ t('forum.filters.anyTopic') }}</option>
                <option v-for="topic in filters.topics" :key="topic.key" :value="topic.key">
                    {{ topic.label }}
                </option>
            </select>
        </div>
    </div>
</template>
