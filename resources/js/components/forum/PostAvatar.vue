<script setup lang="ts">
import { computed } from 'vue';
import { buildAvatarUrl } from '@/utils/cloudinary';

/**
 * An account's profile avatar next to its post, or its initial when it has none. Slightly
 * rounded and without a frame, so it never reads as a character's portrait. The name always
 * shows next to it, so the image says nothing more (empty alt) and the initial is hidden from
 * screen readers.
 */
const props = defineProps<{
    publicId: string | null;
    name: string | null;
    size: 'sm' | 'xl';
}>();

const pixels = computed(() => (props.size === 'xl' ? 72 : 28));
// Twice the drawn size, for high-density screens
const url = computed(() => buildAvatarUrl(props.publicId, pixels.value * 2));
const initial = computed(() => (props.name ?? '?').charAt(0).toUpperCase() || '?');
const box = computed(() =>
    props.size === 'xl' ? 'h-[72px] w-[72px] text-2xl' : 'h-7 w-7 text-xs'
);
</script>

<template>
    <span class="inline-flex shrink-0" data-testid="post-avatar">
        <img
            v-if="url"
            :src="url"
            alt=""
            class="rounded-md bg-muted object-cover"
            :class="box"
            loading="lazy"
            decoding="async"
        />
        <span
            v-else
            class="inline-flex items-center justify-center rounded-md bg-muted font-semibold text-primary"
            :class="box"
            aria-hidden="true"
            >{{ initial }}</span
        >
    </span>
</template>
