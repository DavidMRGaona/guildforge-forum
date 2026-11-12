<script setup lang="ts">
import { computed } from 'vue';

/**
 * A character's portrait, or its initials when it has none. Square-ish with a frame, so it never
 * reads as an account's round avatar. The name always shows next to it, so the image says
 * nothing more (empty alt) and the initials are hidden from screen readers.
 */
const props = withDefaults(
    defineProps<{
        initials: string;
        portraitUrl: string | null;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { size: 'md' }
);

const box = computed(
    () =>
        ({
            sm: 'h-7 w-7 text-xs',
            md: 'h-10 w-10 text-sm',
            lg: 'h-16 w-16 text-lg',
        })[props.size]
);
</script>

<template>
    <img
        v-if="portraitUrl"
        :src="portraitUrl"
        alt=""
        class="forum-character-avatar shrink-0 rounded-lg object-cover"
        :class="box"
        loading="lazy"
        decoding="async"
    />
    <span
        v-else
        class="forum-character-avatar inline-flex shrink-0 items-center justify-center rounded-lg bg-primary-light font-semibold text-primary"
        :class="box"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
