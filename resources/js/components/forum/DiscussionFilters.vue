<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import type { ForumListFilters } from '../../types/forum';

/**
 * «Todos / Sin leer / Siguiendo» and the game-system tag. Each change loads the list from its
 * first page, keeping the tag.
 */
const props = defineProps<{
    filters: ForumListFilters;
}>();

const { t } = useI18n();

const topicId = computed(() => `forum-topic-${props.filters.baseUrl.replace(/[^a-z0-9]/gi, '-')}`);

type Show = 'all' | 'unread' | 'following';

const show = computed<Show>(() =>
    props.filters.following ? 'following' : props.filters.unread ? 'unread' : 'all'
);

const SHOW_OPTIONS: Show[] = ['all', 'unread', 'following'];

function visit(next: { show?: Show; topic?: string | null }): void {
    const shown = next.show ?? show.value;
    const topic = next.topic === undefined ? props.filters.topic : next.topic;
    const query: Record<string, string> = {};
    if (shown === 'unread') {
        query['sin-leer'] = '1';
    }
    if (shown === 'following') {
        query.siguiendo = '1';
    }
    if (topic) {
        query.etiqueta = topic;
    }

    router.get(props.filters.baseUrl, query, {
        preserveScroll: true,
        only: ['discussions', 'filters'],
    });
}

const topicOptions = computed(() =>
    props.filters.topics.map((topic) => ({ value: topic.key, label: topic.label }))
);

function onTopicChange(value: string | number | null): void {
    const topic = value === null || value === '' ? null : String(value);
    if (topic !== props.filters.topic) {
        visit({ topic });
    }
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
                v-for="option in SHOW_OPTIONS"
                :key="option"
                type="button"
                class="min-h-[40px] flex-1 rounded px-3 text-sm font-medium whitespace-nowrap sm:flex-none sm:px-4"
                :class="
                    show === option ? 'bg-primary text-white' : 'text-base-secondary hover:bg-muted'
                "
                :aria-pressed="show === option"
                @click="visit({ show: option })"
            >
                {{ t(`forum.filters.${option}`) }}
            </button>
        </div>

        <div v-if="filters.topics.length > 0" class="flex min-w-0 items-center gap-2">
            <label :for="topicId" class="text-sm font-medium text-base-secondary">
                {{ t('forum.filters.topic') }}
            </label>
            <FormSelect
                :id="topicId"
                class="w-full sm:w-56"
                :model-value="filters.topic ?? ''"
                :options="topicOptions"
                :placeholder="t('forum.filters.anyTopic')"
                @update:model-value="onTopicChange"
            />
        </div>
    </div>
</template>
