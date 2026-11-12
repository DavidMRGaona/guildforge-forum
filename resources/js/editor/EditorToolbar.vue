<script setup lang="ts">
import type { Editor } from '@tiptap/core';
import {
    Bold,
    Code,
    Dices,
    Heading2,
    Heading3,
    ImagePlus,
    Italic,
    Link,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo2,
    SquareCode,
    Strikethrough,
    Undo2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ToolbarItem } from './types';

const props = defineProps<{
    editor: Editor;
    /** Buttons other features add, after the core ones */
    items: ToolbarItem[];
    canUploadImages: boolean;
    disabled: boolean;
}>();

const emit = defineEmits<{
    link: [];
    image: [];
}>();

const { t } = useI18n();

interface Button {
    id: string;
    label: string;
    icon: Component;
    run: () => void;
    isActive: () => boolean;
    /** Where it shows its own keyboard shortcut in the tooltip */
    shortcut?: string;
}

const chain = () => props.editor.chain().focus();

const buttons = computed<Button[]>(() => {
    const core: Button[] = [
        {
            id: 'bold',
            label: t('forum.editor.toolbar.bold'),
            icon: Bold,
            shortcut: 'Ctrl+B',
            run: () => chain().toggleBold().run(),
            isActive: () => props.editor.isActive('bold'),
        },
        {
            id: 'italic',
            label: t('forum.editor.toolbar.italic'),
            icon: Italic,
            shortcut: 'Ctrl+I',
            run: () => chain().toggleItalic().run(),
            isActive: () => props.editor.isActive('italic'),
        },
        {
            id: 'strike',
            label: t('forum.editor.toolbar.strike'),
            icon: Strikethrough,
            run: () => chain().toggleStrike().run(),
            isActive: () => props.editor.isActive('strike'),
        },
        {
            id: 'heading2',
            label: t('forum.editor.toolbar.heading', { level: 2 }),
            icon: Heading2,
            run: () => chain().toggleHeading({ level: 2 }).run(),
            isActive: () => props.editor.isActive('heading', { level: 2 }),
        },
        {
            id: 'heading3',
            label: t('forum.editor.toolbar.heading', { level: 3 }),
            icon: Heading3,
            run: () => chain().toggleHeading({ level: 3 }).run(),
            isActive: () => props.editor.isActive('heading', { level: 3 }),
        },
        {
            id: 'bulletList',
            label: t('forum.editor.toolbar.bullet_list'),
            icon: List,
            run: () => chain().toggleBulletList().run(),
            isActive: () => props.editor.isActive('bulletList'),
        },
        {
            id: 'orderedList',
            label: t('forum.editor.toolbar.ordered_list'),
            icon: ListOrdered,
            run: () => chain().toggleOrderedList().run(),
            isActive: () => props.editor.isActive('orderedList'),
        },
        {
            id: 'blockquote',
            label: t('forum.editor.toolbar.blockquote'),
            icon: Quote,
            run: () => chain().toggleBlockquote().run(),
            isActive: () => props.editor.isActive('blockquote'),
        },
        {
            id: 'code',
            label: t('forum.editor.toolbar.code'),
            icon: Code,
            run: () => chain().toggleCode().run(),
            isActive: () => props.editor.isActive('code'),
        },
        {
            id: 'codeBlock',
            label: t('forum.editor.toolbar.code_block'),
            icon: SquareCode,
            run: () => chain().toggleCodeBlock().run(),
            isActive: () => props.editor.isActive('codeBlock'),
        },
        {
            id: 'link',
            label: t('forum.editor.toolbar.link'),
            icon: Link,
            shortcut: 'Ctrl+K',
            run: () => emit('link'),
            isActive: () => props.editor.isActive('link'),
        },
        {
            id: 'horizontalRule',
            label: t('forum.editor.toolbar.horizontal_rule'),
            icon: Minus,
            run: () => chain().setHorizontalRule().run(),
            isActive: () => false,
        },
    ];

    if (props.canUploadImages) {
        core.push({
            id: 'image',
            label: t('forum.editor.toolbar.image'),
            icon: ImagePlus,
            run: () => emit('image'),
            isActive: () => false,
        });
    }

    core.push({
        id: 'roll',
        label: t('forum.editor.toolbar.roll'),
        icon: Dices,
        run: () => chain().insertRoll().run(),
        isActive: () => false,
    });

    const extra = props.items.map((item) => ({
        id: item.id,
        label: item.label,
        icon: item.icon,
        run: () => item.run(props.editor),
        isActive: () => item.isActive?.(props.editor) ?? false,
    }));

    return [
        ...core,
        ...extra,
        {
            id: 'undo',
            label: t('forum.editor.toolbar.undo'),
            icon: Undo2,
            shortcut: 'Ctrl+Z',
            run: () => chain().undo().run(),
            isActive: () => false,
        },
        {
            id: 'redo',
            label: t('forum.editor.toolbar.redo'),
            icon: Redo2,
            shortcut: 'Ctrl+Shift+Z',
            run: () => chain().redo().run(),
            isActive: () => false,
        },
    ];
});

// One tab stop for the whole toolbar; the arrow keys move inside it (ARIA toolbar pattern)
const current = ref(0);
const buttonElements = ref<HTMLButtonElement[]>([]);

function move(event: KeyboardEvent): void {
    const last = buttons.value.length - 1;
    const next =
        event.key === 'ArrowRight'
            ? (current.value + 1) % (last + 1)
            : event.key === 'ArrowLeft'
              ? (current.value + last) % (last + 1)
              : event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? last
                  : null;

    if (next !== null) {
        event.preventDefault();
        current.value = next;
        buttonElements.value[next]?.focus();
    }
}
</script>

<template>
    <div
        role="toolbar"
        :aria-label="t('forum.editor.toolbar.label')"
        aria-orientation="horizontal"
        class="border-default flex flex-wrap items-center gap-0.5 border-b px-1.5 py-1"
        @keydown="move"
    >
        <button
            v-for="(button, index) in buttons"
            :key="button.id"
            ref="buttonElements"
            type="button"
            :tabindex="index === current ? 0 : -1"
            :aria-label="button.label"
            :aria-pressed="
                button.id === 'undo' ||
                button.id === 'redo' ||
                button.id === 'image' ||
                button.id === 'roll' ||
                button.id === 'horizontalRule'
                    ? undefined
                    : button.isActive()
            "
            :title="button.shortcut ? `${button.label} (${button.shortcut})` : button.label"
            :disabled="disabled"
            :data-toolbar-item="button.id"
            class="text-base-secondary hover:bg-muted hover:text-base-primary focus-visible:ring-primary-500 inline-flex h-9 w-9 items-center justify-center rounded-md transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 aria-pressed:bg-primary-light aria-pressed:text-primary"
            @click="
                current = index;
                button.run();
            "
        >
            <component :is="button.icon" class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>
</template>
