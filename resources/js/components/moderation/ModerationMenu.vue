<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ShieldCheck } from '@lucide/vue';

export interface ModerationMenuItem {
    key: string;
    label: string;
    danger?: boolean;
}

/**
 * The moderation menu of a discussion or a post: one button that opens the list of actions
 * (a disclosure, so screen readers hear a button and a list). Escape or a click outside closes
 * it and gives the focus back to the button.
 */
const props = defineProps<{
    items: ModerationMenuItem[];
    label: string;
    busy?: boolean;
}>();

const emit = defineEmits<{ select: [key: string] }>();

const { t } = useI18n();
const open = ref(false);
const root = ref<HTMLElement | null>(null);
const toggle = ref<HTMLButtonElement | null>(null);
const listId = `forum-moderation-${Math.random().toString(36).slice(2, 10)}`;

const hasItems = computed(() => props.items.length > 0);

function onOutside(event: MouseEvent): void {
    if (root.value && event.target instanceof Node && !root.value.contains(event.target)) {
        close(false);
    }
}

function show(): void {
    open.value = true;
    document.addEventListener('click', onOutside);
    void nextTick(() =>
        root.value?.querySelector<HTMLButtonElement>('[data-moderation-item]')?.focus()
    );
}

function close(focusToggle = true): void {
    open.value = false;
    document.removeEventListener('click', onOutside);
    if (focusToggle) {
        toggle.value?.focus();
    }
}

function choose(key: string): void {
    close();
    emit('select', key);
}

onBeforeUnmount(() => document.removeEventListener('click', onOutside));
</script>

<template>
    <div v-if="hasItems" ref="root" class="relative" @keydown.esc.stop="close()">
        <button
            ref="toggle"
            type="button"
            class="inline-flex min-h-[44px] items-center gap-1 rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary disabled:opacity-50"
            :aria-expanded="open"
            :aria-controls="listId"
            :disabled="busy"
            data-testid="moderation-toggle"
            @click="open ? close() : show()"
        >
            <ShieldCheck class="h-4 w-4" aria-hidden="true" />
            {{ label }}
        </button>
        <ul
            v-show="open"
            :id="listId"
            class="absolute right-0 z-20 mt-1 min-w-[14rem] rounded-md border border-default bg-surface py-1 shadow-lg"
            :aria-label="t('forum.moderation.menu')"
        >
            <li v-for="item in items" :key="item.key">
                <button
                    type="button"
                    class="flex min-h-[44px] w-full items-center px-4 text-left text-sm hover:bg-muted"
                    :class="item.danger ? 'text-red-600 dark:text-red-400' : 'text-base-primary'"
                    data-moderation-item
                    :data-testid="`moderation-${item.key}`"
                    @click="choose(item.key)"
                >
                    {{ item.label }}
                </button>
            </li>
        </ul>
    </div>
</template>
