<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import CharacterAvatar from './CharacterAvatar.vue';
import CharacterDialog from './CharacterDialog.vue';
import type {
    ForumCharacterBasics,
    ForumCharacterOption,
    ForumCharacterPicker,
    ForumPostCharacter,
} from '../../types/forum';

/**
 * «Hablar como» above the editor: one of the person's characters, or «Fuera de personaje». In a
 * campaign's space, whoever directs the campaign also finds its shared PNJ, in a group of their own.
 * «Nuevo personaje…» creates one without leaving the page and picks it. The server checks the
 * choice again when the post is sent.
 */
const props = withDefaults(
    defineProps<{
        /** The chosen character id; null out of character */
        modelValue: string | null;
        picker: ForumCharacterPicker;
        /** Only these may be chosen (the page that starts a discussion, by category); all when null */
        categoryId?: string | null;
        /** The character a post being edited speaks as, offered even if it is no longer usable */
        current?: ForumCharacterBasics | null;
        disabled?: boolean;
    }>(),
    { categoryId: null, current: null, disabled: false }
);

const emit = defineEmits<{ 'update:modelValue': [characterId: string | null] }>();

const NEW = '__new__';
const { t } = useI18n();
const uid = Math.random().toString(36).slice(2, 10);
const creating = ref(false);
const created = ref<ForumCharacterOption[]>([]);

/** The category the post is written in: chosen on the page, or the discussion's */
const here = computed(() => props.categoryId ?? props.picker.categoryId);
const notice = ref<string | null>(null);

const options = computed<ForumCharacterBasics[]>(() => {
    const usable = [...props.picker.items, ...created.value].filter(
        (item) =>
            item.categoryIds === undefined ||
            (here.value !== null && item.categoryIds.includes(here.value))
    );
    if (props.current && !usable.some((item) => item.id === props.current?.id)) {
        return [props.current, ...usable];
    }

    return usable;
});

/**
 * The person's characters first, then the shared PNJ, each marked as one (the list has no
 * groups), and «Nuevo personaje…» last. «Fuera de personaje» is the empty choice.
 */
const choices = computed(() => [
    ...options.value
        .filter((option) => option.isShared !== true)
        .map((option) => ({ value: option.id, label: option.name })),
    ...options.value
        .filter((option) => option.isShared === true)
        .map((option) => ({
            value: option.id,
            label: t('forum.characters.shared.option', { name: option.name }),
        })),
    ...(props.picker.createUrl ? [{ value: NEW, label: t('forum.characters.newCharacter') }] : []),
]);

const chosen = computed(
    () => options.value.find((option) => option.id === props.modelValue) ?? null
);

// A character that cannot be used in the category chosen on the page is dropped
watch(
    options,
    () => {
        if (props.modelValue !== null && chosen.value === null) {
            emit('update:modelValue', null);
        }
    },
    { immediate: true }
);

function change(value: string | number | null): void {
    if (value === NEW) {
        // What was chosen stays until the new one is saved
        creating.value = true;

        return;
    }
    emit('update:modelValue', value === null || value === '' ? null : String(value));
}

function added(character: ForumPostCharacter, boundTo: string | null): void {
    // Tied to a category, it is used there and in its subcategories
    const categoryIds =
        boundTo === null
            ? undefined
            : props.picker.categories
                  .filter((category) => category.id === boundTo || category.parentId === boundTo)
                  .map((category) => category.id);
    created.value = [
        ...created.value,
        {
            id: character.id,
            name: character.name,
            initials: character.initials,
            portraitUrl: character.portraitUrl,
            categoryIds,
        },
    ];
    if (categoryIds === undefined || (here.value !== null && categoryIds.includes(here.value))) {
        notice.value = null;
        emit('update:modelValue', character.id);
    } else {
        notice.value = t('forum.characters.createdElsewhere', { name: character.name });
    }
    document.getElementById(`speak-as-${uid}`)?.focus();
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-testid="speak-as">
        <label :for="`speak-as-${uid}`" class="text-sm font-medium text-base-secondary">
            {{ t('forum.characters.speakAs') }}
        </label>
        <div class="flex min-w-0 flex-1 items-center gap-2 sm:flex-none">
            <CharacterAvatar
                v-if="chosen"
                :initials="chosen.initials"
                :portrait-url="chosen.portraitUrl"
                size="sm"
            />
            <FormSelect
                :id="`speak-as-${uid}`"
                class="w-full min-w-0 sm:w-64"
                :model-value="modelValue"
                :options="choices"
                :placeholder="t('forum.characters.outOfCharacter')"
                :disabled="disabled"
                data-testid="speak-as-select"
                @update:model-value="change"
            />
        </div>

        <p v-if="notice" role="status" class="basis-full text-xs text-base-muted">{{ notice }}</p>

        <CharacterDialog v-if="picker.createUrl" v-model="creating" :form="picker" @saved="added" />
    </div>
</template>
