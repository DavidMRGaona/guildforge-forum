<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { sendJson } from '../../forum/api';
import ChoiceMark from '../forms/ChoiceMark.vue';

/**
 * Reporting a post: a reason from a closed list and, if wanted, a note for moderation.
 */
const props = defineProps<{
    modelValue: boolean;
    reportUrl: string;
    postNumber: number | null;
    /** What moderation will read, when it is not the post alone (a private message) */
    description?: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    reported: [];
}>();

const REASONS = ['spam', 'offensive', 'off_topic', 'personal_data', 'other'] as const;
const NOTE_MAX = 500;

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);
const reason = ref('');
const note = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const uid = Math.random().toString(36).slice(2, 10);

const valid = computed(() => reason.value !== '' && note.value.length <= NOTE_MAX);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            reason.value = '';
            note.value = '';
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
    if (!valid.value || sending.value) {
        return;
    }
    sending.value = true;
    const answer = await sendJson(props.reportUrl, 'POST', {
        reason: reason.value,
        note: note.value.trim() === '' ? undefined : note.value.trim(),
    });
    sending.value = false;

    if (answer.ok) {
        emit('reported');
        emit('update:modelValue', false);

        return;
    }

    error.value = answer.message ?? t('forum.moderation.errors.generic');
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border-0 bg-transparent p-0 backdrop:bg-neutral-900/60"
            :aria-labelledby="`report-dialog-${uid}`"
            @cancel.prevent="cancel"
        >
            <form class="w-full rounded-lg bg-surface p-6 shadow-xl" @submit.prevent="submit">
                <h3 :id="`report-dialog-${uid}`" class="text-lg font-semibold text-base-primary">
                    {{ t('forum.reports.title', { number: postNumber ?? '' }) }}
                </h3>
                <p class="mt-2 text-sm text-base-secondary">
                    {{ description ?? t('forum.reports.message') }}
                </p>

                <fieldset class="mt-4">
                    <legend class="text-sm font-medium text-base-primary">
                        {{ t('forum.reports.reason') }}
                    </legend>
                    <label
                        v-for="option in REASONS"
                        :key="option"
                        class="relative mt-2 flex min-h-[44px] cursor-pointer items-center gap-3 text-sm text-base-primary"
                    >
                        <input
                            v-model="reason"
                            type="radio"
                            class="peer sr-only"
                            :name="`report-reason-${uid}`"
                            :value="option"
                            required
                            :data-testid="`report-reason-${option}`"
                        />
                        <ChoiceMark type="radio" :checked="reason === option" />
                        {{ t(`forum.reports.reasons.${option}`) }}
                    </label>
                </fieldset>

                <div class="mt-4">
                    <label
                        :for="`report-note-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.reports.note') }}
                    </label>
                    <textarea
                        :id="`report-note-${uid}`"
                        v-model="note"
                        rows="3"
                        :maxlength="NOTE_MAX"
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        data-testid="report-note"
                    />
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
                        :disabled="!valid || sending"
                        data-testid="report-submit"
                    >
                        {{ t('forum.reports.send') }}
                    </BaseButton>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>
