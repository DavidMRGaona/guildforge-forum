<script setup lang="ts">
import { computed, type Component } from 'vue';
import {
    BookOpen,
    Box,
    CalendarDays,
    CircleHelp,
    Flag,
    Lock,
    Map as MapIcon,
    Megaphone,
    MessagesSquare,
    Puzzle,
    ShieldCheck,
    Sparkles,
    Trophy,
    Users,
} from '@lucide/vue';

/**
 * A category's icon. The server sends one of a closed list of names (CategoryIcon), never
 * markup; an unknown one draws the default speech bubbles.
 */
const props = defineProps<{
    name: string | null;
    color?: string | null;
}>();

const icons: Record<string, Component> = {
    'chat-bubble-left-right': MessagesSquare,
    megaphone: Megaphone,
    cube: Box,
    'puzzle-piece': Puzzle,
    'user-group': Users,
    'calendar-days': CalendarDays,
    map: MapIcon,
    'book-open': BookOpen,
    sparkles: Sparkles,
    'shield-check': ShieldCheck,
    trophy: Trophy,
    flag: Flag,
    'lock-closed': Lock,
    'question-mark-circle': CircleHelp,
};

const icon = computed<Component>(
    () => (props.name ? icons[props.name] : undefined) ?? MessagesSquare
);

/** Only a #rrggbb colour (the server checks it too) tints the badge */
const style = computed(() =>
    props.color && /^#[0-9a-fA-F]{6}$/.test(props.color)
        ? { backgroundColor: `${props.color}26`, color: props.color }
        : undefined
);
</script>

<template>
    <span
        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
        :class="style ? '' : 'bg-primary-light text-primary'"
        :style="style"
        aria-hidden="true"
    >
        <component :is="icon" class="h-5 w-5" :stroke-width="2" />
    </span>
</template>
