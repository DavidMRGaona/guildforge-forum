<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { sendJson } from '../../forum/api';

/**
 * Giving the author of a post a title, or changing it: one short line shown next to their name in
 * the whole forum. Only whoever moderates the whole forum gets the URL; the server checks it again.
 */
const props = defineProps<{
    modelValue: boolean;
    titleUrl: string;
    userId: string;
    userName: string | null;
    current: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    saved: [];
}>();

const TITLE_MAX = 30;

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);
const title = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const uid = Math.random().toString(36).slice(2, 10);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            title.value = props.current ?? '';
            error.value = null;
            dialog.value?.showModal();
        } else {
            dialog.value?.close();
        }
    }
);

function cancel(): void {
    emit('update:modelValue', false);
}

async function submit(): Promise<void> {
    if (sending.value) {
        return;
    }
    sending.value = true;
    const trimmed = title.value.trim();
    const answer = await sendJson(props.titleUrl, 'POST', {
        user: props.userId,
        title: trimmed === '' ? null : trimmed,
    });
    sending.value = false;

    if (answer.ok) {
        emit('saved');
        emit('update:modelValue', false);

        return;
    }

    error.value = answer.message ?? t('forum.titles.errors.save');
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border-0 bg-transparent p-0 backdrop:bg-neutral-900/60"
            :aria-labelledby="`title-dialog-${uid}`"
            @cancel.prevent="cancel"
        >
            <form
                class="w-full rounded-lg bg-surface p-6 shadow-xl"
                data-testid="title-form"
                @submit.prevent="submit"
            >
                <h3 :id="`title-dialog-${uid}`" class="text-lg font-semibold text-base-primary">
                    {{
                        t('forum.titles.dialogTitle', {
                            name: userName ?? t('forum.posts.deletedAccount'),
                        })
                    }}
                </h3>

                <div class="mt-4">
                    <label
                        :for="`title-input-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.titles.label') }}
                    </label>
                    <input
                        :id="`title-input-${uid}`"
                        v-model="title"
                        type="text"
                        :maxlength="TITLE_MAX"
                        autocomplete="off"
                        :aria-describedby="`title-hint-${uid}`"
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        data-testid="title-input"
                    />
                    <p :id="`title-hint-${uid}`" class="mt-1 text-xs text-base-muted">
                        {{ t('forum.titles.hint') }}
                    </p>
                </div>

                <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <BaseButton variant="secondary" size="sm" type="button" @click="cancel">
                        {{ t('common.cancel') }}
                    </BaseButton>
                    <BaseButton
                        variant="primary"
                        size="sm"
                        type="submit"
                        :disabled="sending"
                        data-testid="title-submit"
                    >
                        {{ t('forum.titles.save') }}
                    </BaseButton>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>
