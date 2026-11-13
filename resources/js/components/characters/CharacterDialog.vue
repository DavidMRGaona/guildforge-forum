<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import CharacterAvatar from './CharacterAvatar.vue';
import { sendForm } from '../../forum/api';
import type { ForumCharacterForm, ForumPostCharacter } from '../../types/forum';

/** What the form starts from when it changes a character */
export interface CharacterDialogStart {
    name: string;
    description: string | null;
    categoryId: string | null;
    initials: string;
    portraitUrl: string | null;
    updateUrl: string;
}

/**
 * Creates or changes a character: name, short description, portrait (without one, its
 * initials show) and the category it is tied to, if any. Moderators change everything but
 * the category, which stays its owner's choice. The server checks it all again.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        form: ForumCharacterForm;
        /** The character to change; null creates one */
        start?: CharacterDialogStart | null;
        /** Whether the category may be chosen (its owner); moderators keep it */
        choosesCategory?: boolean;
        /** A PNJ shared by a campaign's directors: its own titles */
        shared?: boolean;
    }>(),
    { start: null, choosesCategory: true, shared: false }
);

const emit = defineEmits<{
    'update:modelValue': [open: boolean];
    /** With the category it was tied to (null for none) */
    saved: [character: ForumPostCharacter, categoryId: string | null];
}>();

const { t } = useI18n();
const uid = Math.random().toString(36).slice(2, 10);
const dialog = ref<HTMLDialogElement | null>(null);
const nameField = ref<HTMLInputElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const name = ref('');
const description = ref('');
const categoryId = ref('');

const categoryOptions = computed(() =>
    props.form.categories.map((category) => ({
        value: category.id,
        label: category.parentName ? `${category.parentName} › ${category.name}` : category.name,
    }))
);

function chooseCategory(value: string | number | null): void {
    categoryId.value = value === null ? '' : String(value);
}
const portrait = ref<File | null>(null);
const preview = ref<string | null>(null);
const removePortrait = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

const nameLength = computed(() => name.value.trim().replace(/\s+/g, ' ').length);
const valid = computed(
    () =>
        nameLength.value > 0 &&
        nameLength.value <= props.form.limits.nameMax &&
        description.value.length <= props.form.limits.descriptionMax
);
const shownPortrait = computed(
    () => preview.value ?? (removePortrait.value ? null : (props.start?.portraitUrl ?? null))
);
const initials = computed(() => {
    const words = name.value
        .trim()
        .split(/[\s-]+/u)
        .filter((word) => word !== '');

    return words
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
});

function clearPreview(): void {
    if (preview.value !== null) {
        URL.revokeObjectURL(preview.value);
    }
    preview.value = null;
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            name.value = props.start?.name ?? '';
            description.value = props.start?.description ?? '';
            categoryId.value = props.start?.categoryId ?? '';
            portrait.value = null;
            clearPreview();
            removePortrait.value = false;
            error.value = null;
            fieldErrors.value = {};
            dialog.value?.showModal();
            nameField.value?.focus();
        } else {
            dialog.value?.close();
        }
    }
);

onBeforeUnmount(clearPreview);

function choosePortrait(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    fieldErrors.value = { ...fieldErrors.value, portrait: '' };
    if (file === null) {
        return;
    }
    if (!props.form.limits.portraitTypes.includes(file.type)) {
        fieldErrors.value = {
            ...fieldErrors.value,
            portrait: t('forum.characters.form.portraitType'),
        };

        return;
    }
    if (file.size > props.form.limits.portraitKilobytes * 1024) {
        fieldErrors.value = {
            ...fieldErrors.value,
            portrait: t('forum.characters.form.portraitSize', {
                kb: props.form.limits.portraitKilobytes,
            }),
        };

        return;
    }
    portrait.value = file;
    removePortrait.value = false;
    clearPreview();
    preview.value = URL.createObjectURL(file);
}

function dropPortrait(): void {
    portrait.value = null;
    clearPreview();
    removePortrait.value = true;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function close(): void {
    emit('update:modelValue', false);
}

async function submit(): Promise<void> {
    const url = props.start?.updateUrl ?? props.form.createUrl;
    if (!valid.value || busy.value || url === null) {
        return;
    }

    const data = new FormData();
    data.append('name', name.value);
    data.append('description', description.value);
    if (props.choosesCategory && categoryId.value !== '') {
        data.append('category', categoryId.value);
    }
    if (portrait.value !== null) {
        data.append('portrait', portrait.value);
    } else if (removePortrait.value) {
        data.append('remove_portrait', '1');
    }

    busy.value = true;
    error.value = null;
    const answer = await sendForm<{ character: ForumPostCharacter }>(url, data);
    busy.value = false;

    if (answer.ok && answer.data) {
        emit(
            'saved',
            answer.data.character,
            props.choosesCategory && categoryId.value !== ''
                ? categoryId.value
                : (props.start?.categoryId ?? null)
        );
        close();

        return;
    }

    fieldErrors.value = Object.fromEntries(
        Object.entries(answer.errors).map(([field, messages]) => [field, messages[0] ?? ''])
    );
    error.value = answer.message ?? t('forum.characters.errors.save');
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="fixed inset-0 m-auto max-h-[calc(100%-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-lg border-0 bg-transparent p-0 backdrop:bg-neutral-900/60"
            :aria-labelledby="`character-dialog-${uid}`"
            data-testid="character-dialog"
            @cancel.prevent="close"
        >
            <form
                class="w-full rounded-lg bg-surface p-6 shadow-xl"
                novalidate
                @submit.prevent="submit"
            >
                <h3 :id="`character-dialog-${uid}`" class="text-lg font-semibold text-base-primary">
                    {{
                        start
                            ? t(
                                  shared
                                      ? 'forum.characters.shared.editTitle'
                                      : 'forum.characters.form.editTitle'
                              )
                            : t(
                                  shared
                                      ? 'forum.characters.shared.createTitle'
                                      : 'forum.characters.form.createTitle'
                              )
                    }}
                </h3>

                <div class="mt-4">
                    <label
                        :for="`character-name-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.characters.form.name') }}
                    </label>
                    <input
                        :id="`character-name-${uid}`"
                        ref="nameField"
                        v-model="name"
                        type="text"
                        :maxlength="form.limits.nameMax"
                        required
                        autocomplete="off"
                        class="mt-1 block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary"
                        :aria-invalid="fieldErrors.name ? 'true' : undefined"
                        :aria-describedby="
                            fieldErrors.name ? `character-name-error-${uid}` : undefined
                        "
                        data-testid="character-name"
                    />
                    <p
                        v-if="fieldErrors.name"
                        :id="`character-name-error-${uid}`"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ fieldErrors.name }}
                    </p>
                </div>

                <div class="mt-4">
                    <label
                        :for="`character-description-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.characters.form.description') }}
                    </label>
                    <textarea
                        :id="`character-description-${uid}`"
                        v-model="description"
                        rows="3"
                        :maxlength="form.limits.descriptionMax"
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        :aria-describedby="`character-description-help-${uid}`"
                        data-testid="character-description"
                    />
                    <p
                        :id="`character-description-help-${uid}`"
                        class="mt-1 text-xs text-base-muted"
                    >
                        {{
                            t('forum.characters.form.descriptionHelp', {
                                count: description.length,
                                max: form.limits.descriptionMax,
                            })
                        }}
                    </p>
                </div>

                <fieldset class="mt-4">
                    <legend class="block text-sm font-medium text-base-primary">
                        {{ t('forum.characters.form.portrait') }}
                    </legend>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <CharacterAvatar
                            :initials="initials || '?'"
                            :portrait-url="shownPortrait"
                            size="lg"
                        />
                        <label
                            :for="`character-portrait-${uid}`"
                            class="inline-flex min-h-[44px] cursor-pointer items-center rounded-md border border-default px-3 text-sm font-medium text-base-primary hover:bg-muted focus-within:ring-2 focus-within:ring-primary-500"
                        >
                            {{
                                shownPortrait
                                    ? t('forum.characters.form.changePortrait')
                                    : t('forum.characters.form.choosePortrait')
                            }}
                            <input
                                :id="`character-portrait-${uid}`"
                                ref="fileInput"
                                type="file"
                                class="sr-only"
                                :accept="form.limits.portraitTypes.join(',')"
                                :aria-describedby="`character-portrait-help-${uid}`"
                                data-testid="character-portrait"
                                @change="choosePortrait"
                            />
                        </label>
                        <button
                            v-if="shownPortrait"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                            @click="dropPortrait"
                        >
                            {{ t('forum.characters.form.removePortrait') }}
                        </button>
                    </div>
                    <p :id="`character-portrait-help-${uid}`" class="mt-1 text-xs text-base-muted">
                        {{
                            t('forum.characters.form.portraitHelp', {
                                kb: form.limits.portraitKilobytes,
                            })
                        }}
                    </p>
                    <p v-if="fieldErrors.portrait" role="alert" class="mt-1 text-xs text-red-600">
                        {{ fieldErrors.portrait }}
                    </p>
                </fieldset>

                <div v-if="choosesCategory" class="mt-4">
                    <label
                        :for="`character-category-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.characters.form.category') }}
                    </label>
                    <FormSelect
                        :id="`character-category-${uid}`"
                        class="mt-1"
                        :model-value="categoryId"
                        :options="categoryOptions"
                        :placeholder="t('forum.characters.form.anyCategory')"
                        :described-by="`character-category-help-${uid}`"
                        :error="fieldErrors.category ?? ''"
                        data-testid="character-category"
                        @update:model-value="chooseCategory"
                    />
                    <p :id="`character-category-help-${uid}`" class="mt-1 text-xs text-base-muted">
                        {{ t('forum.characters.form.categoryHelp') }}
                    </p>
                    <p v-if="fieldErrors.category" class="mt-1 text-xs text-red-600">
                        {{ fieldErrors.category }}
                    </p>
                </div>

                <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <BaseButton variant="secondary" size="sm" type="button" @click="close">
                        {{ t('common.cancel') }}
                    </BaseButton>
                    <BaseButton
                        variant="primary"
                        size="sm"
                        type="submit"
                        :disabled="!valid || busy"
                        data-testid="character-submit"
                    >
                        {{
                            start
                                ? t('forum.characters.form.save')
                                : t('forum.characters.form.create')
                        }}
                    </BaseButton>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>
