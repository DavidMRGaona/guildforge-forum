<script setup lang="ts">
import { Dices, Trash2 } from '@lucide/vue';
import { nodeViewProps, NodeViewWrapper } from '@tiptap/vue-3';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import ChoiceMark from '../components/forms/ChoiceMark.vue';
import { MAX_EXPRESSION_LENGTH, MAX_REASON_LENGTH } from './rollText';

/**
 * How a roll looks while writing: its expression and its reason, to fill in. The result is not
 * here and cannot be: the server throws the dice when the post is published. A roll the post
 * already has (it carries its id) keeps its expression, because that is the throw the server
 * made; another expression is another roll, so the person removes this one and adds a new one.
 *
 * «Oculta» asks for a hidden roll: its result is shown only to whoever throws it, moderation and,
 * in a campaign's space, its direction. It is chosen before throwing; a thrown roll stays as it is.
 */
const props = defineProps(nodeViewProps);

const { t } = useI18n();
const id = useId();

const locked = computed(() => props.node.attrs['rollId'] !== null);
const expression = computed(() => String(props.node.attrs['expression'] ?? ''));
const reason = computed(() => String(props.node.attrs['reason'] ?? ''));
const hidden = computed(() => props.node.attrs['hidden'] === true);

const hint = computed(() => {
    if (locked.value) {
        return hidden.value
            ? t('forum.editor.roll.thrown_hidden_hint')
            : t('forum.editor.roll.thrown_hint');
    }

    return hidden.value ? t('forum.editor.roll.hidden_hint') : t('forum.editor.roll.hint');
});

function setExpression(event: Event): void {
    props.updateAttributes({ expression: (event.target as HTMLInputElement).value });
}

function setHidden(event: Event): void {
    props.updateAttributes({ hidden: (event.target as HTMLInputElement).checked });
}

function setReason(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    props.updateAttributes({ reason: value === '' ? null : value });
}
</script>

<template>
    <NodeViewWrapper
        as="div"
        class="forum-roll forum-roll--editing"
        data-forum-roll
        :class="{ 'ProseMirror-selectednode': selected }"
        contenteditable="false"
    >
        <div class="forum-roll__fields">
            <Dices class="forum-roll__icon" aria-hidden="true" />
            <label :for="`${id}-expression`" class="forum-roll__label">
                {{ t('forum.editor.roll.label') }}
            </label>
            <input
                :id="`${id}-expression`"
                type="text"
                class="forum-roll__input forum-roll__input--expression"
                :value="expression"
                :readonly="locked"
                :maxlength="MAX_EXPRESSION_LENGTH"
                :placeholder="t('forum.editor.roll.expression_placeholder')"
                :aria-describedby="`${id}-hint`"
                autocomplete="off"
                spellcheck="false"
                data-roll-expression
                @input="setExpression"
            />
            <label :for="`${id}-reason`" class="sr-only">
                {{ t('forum.editor.roll.reason') }}
            </label>
            <input
                :id="`${id}-reason`"
                type="text"
                class="forum-roll__input forum-roll__input--reason"
                :value="reason"
                :maxlength="MAX_REASON_LENGTH"
                :placeholder="t('forum.editor.roll.reason_placeholder')"
                autocomplete="off"
                data-roll-reason
                @input="setReason"
            />
            <label class="forum-roll__hidden-toggle">
                <input
                    type="checkbox"
                    class="peer sr-only"
                    :checked="hidden"
                    :disabled="locked"
                    :aria-describedby="`${id}-hint`"
                    data-roll-hidden
                    @change="setHidden"
                />
                <ChoiceMark :checked="hidden" />
                {{ t('forum.editor.roll.hidden') }}
            </label>
            <button
                type="button"
                class="forum-roll__remove"
                :aria-label="t('forum.editor.roll.remove')"
                :title="t('forum.editor.roll.remove')"
                data-roll-remove
                @click="deleteNode"
            >
                <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
        <p :id="`${id}-hint`" class="forum-roll__hint">
            {{ hint }}
        </p>
    </NodeViewWrapper>
</template>
