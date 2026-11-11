<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MentionCandidate } from './types';

const props = defineProps<{
    items: MentionCandidate[];
    query: string;
    command: (candidate: MentionCandidate) => void;
}>();

const { t } = useI18n();
const selected = ref(0);

watch(
    () => props.items,
    () => {
        selected.value = 0;
    }
);

function pick(index: number): void {
    const candidate = props.items[index];
    if (candidate !== undefined) {
        props.command(candidate);
    }
}

/** Arrow keys move through the list, Enter and Tab pick; called by the suggestion plugin */
function onKeyDown(event: KeyboardEvent): boolean {
    if (props.items.length === 0) {
        return false;
    }

    switch (event.key) {
        case 'ArrowDown':
            selected.value = (selected.value + 1) % props.items.length;
            return true;
        case 'ArrowUp':
            selected.value = (selected.value + props.items.length - 1) % props.items.length;
            return true;
        case 'Enter':
        case 'Tab':
            pick(selected.value);
            return true;
        default:
            return false;
    }
}

defineExpose({ onKeyDown });
</script>

<template>
    <div
        class="bg-elevated border-default text-base-primary w-64 max-w-[calc(100vw-1rem)] overflow-hidden rounded-lg border shadow-lg"
    >
        <ul
            v-if="items.length > 0"
            id="forum-mention-list"
            role="listbox"
            :aria-label="t('forum.editor.mentions.label')"
            class="max-h-64 overflow-y-auto py-1"
        >
            <li
                v-for="(candidate, index) in items"
                :id="`forum-mention-${candidate.id}`"
                :key="candidate.id"
                role="option"
                :aria-selected="index === selected"
                class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm"
                :class="index === selected ? 'bg-primary-light' : ''"
                @mousedown.prevent="pick(index)"
                @mouseenter="selected = index"
            >
                <img
                    v-if="candidate.avatarUrl"
                    :src="candidate.avatarUrl"
                    alt=""
                    class="h-6 w-6 shrink-0 rounded-full object-cover"
                />
                <span
                    v-else
                    aria-hidden="true"
                    class="bg-muted text-base-secondary flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                >
                    {{ candidate.name.charAt(0).toUpperCase() }}
                </span>
                <span class="truncate">{{ candidate.name }}</span>
            </li>
        </ul>
        <p v-else-if="query.trim() !== ''" class="text-base-muted px-3 py-2 text-sm" role="status">
            {{ t('forum.editor.mentions.none') }}
        </p>
    </div>
</template>
