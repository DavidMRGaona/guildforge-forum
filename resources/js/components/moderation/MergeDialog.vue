<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormCheckbox } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useMergedDiscussion } from '../../composables/useMergedDiscussion';
import { sendJson } from '../../forum/api';
import type { ForumMergeTarget } from '../../types/forum';

/**
 * Merging the discussion into another: the moderator types the other's number or address, the
 * server says which discussion that is (and whether they may merge into it), and only then a
 * reason and a box to tick allow merging. Nothing is sent before.
 */
const props = defineProps<{
    modelValue: boolean;
    lookupUrl: string;
    reasonMax: number;
    busy: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    confirm: [fields: { target: string; reason: string }];
}>();

const { t } = useI18n();
const reportMerged = useMergedDiscussion();
const dialog = ref<HTMLDialogElement | null>(null);
const typed = ref('');
const reason = ref('');
const confirmed = ref(false);
const target = ref<ForumMergeTarget | null>(null);
const looking = ref(false);
const lookupError = ref<string | null>(null);
const uid = Math.random().toString(36).slice(2, 10);

const valid = computed(
    () => target.value !== null && confirmed.value && reason.value.length <= props.reasonMax
);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            typed.value = '';
            reason.value = '';
            confirmed.value = false;
            target.value = null;
            lookupError.value = null;
            dialog.value?.showModal();
        } else {
            dialog.value?.close();
        }
    }
);

// What was found is for what was typed: typing again asks again
watch(typed, () => {
    target.value = null;
    confirmed.value = false;
});

async function lookup(): Promise<void> {
    if (typed.value.trim() === '' || looking.value) {
        return;
    }
    looking.value = true;
    lookupError.value = null;
    const separator = props.lookupUrl.includes('?') ? '&' : '?';
    const answer = await sendJson<{ discussion: ForumMergeTarget }>(
        `${props.lookupUrl}${separator}tema=${encodeURIComponent(typed.value.trim())}`,
        'GET'
    );
    looking.value = false;
    reportMerged(answer.mergedInto);
    target.value = answer.ok ? (answer.data?.discussion ?? null) : null;
    lookupError.value = answer.ok ? null : (answer.message ?? t('forum.moderation.errors.generic'));
}

function submit(): void {
    if (valid.value && target.value !== null && !props.busy) {
        emit('confirm', { target: target.value.id, reason: reason.value.trim() });
    }
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-lg border-0 bg-transparent p-0 backdrop:bg-neutral-900/60"
            :aria-labelledby="`merge-dialog-${uid}`"
            @cancel.prevent="emit('update:modelValue', false)"
        >
            <form class="w-full rounded-lg bg-surface p-6 shadow-xl" @submit.prevent="submit">
                <h3 :id="`merge-dialog-${uid}`" class="text-lg font-semibold text-base-primary">
                    {{ t('forum.moderation.discussion.mergeTitle') }}
                </h3>
                <p class="mt-2 text-sm text-base-secondary">
                    {{ t('forum.moderation.discussion.mergeMessage') }}
                </p>

                <label
                    :for="`merge-target-${uid}`"
                    class="mt-4 block text-sm font-medium text-base-primary"
                >
                    {{ t('forum.moderation.fields.mergeTarget') }}
                </label>
                <div class="mt-1 flex gap-2">
                    <input
                        :id="`merge-target-${uid}`"
                        v-model="typed"
                        type="text"
                        inputmode="url"
                        autocomplete="off"
                        class="block min-h-[44px] w-full min-w-0 rounded-md border border-default bg-surface px-3 text-base-primary"
                        :aria-describedby="`merge-target-help-${uid}`"
                        :aria-invalid="lookupError !== null"
                        data-testid="merge-target"
                        @keydown.enter.prevent="lookup"
                    />
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        type="button"
                        :disabled="looking"
                        data-testid="merge-lookup"
                        @click="lookup"
                    >
                        {{ t('forum.moderation.discussion.mergeLookup') }}
                    </BaseButton>
                </div>
                <p :id="`merge-target-help-${uid}`" class="mt-1 text-xs text-base-muted">
                    {{ t('forum.moderation.fields.mergeTargetHelp') }}
                </p>
                <p v-if="lookupError" role="alert" class="mt-2 text-sm text-red-600">
                    {{ lookupError }}
                </p>
                <p
                    v-if="target"
                    class="mt-3 rounded-md border border-default bg-muted px-3 py-2 text-sm break-words text-base-primary"
                    aria-live="polite"
                    data-testid="merge-found"
                >
                    {{ t('forum.moderation.discussion.mergeFound', { title: target.title }) }}
                    <span v-if="target.categoryName" class="text-base-secondary">
                        {{
                            t('forum.moderation.discussion.mergeFoundIn', {
                                category: target.categoryName,
                            })
                        }}
                    </span>
                </p>

                <template v-if="target">
                    <label
                        :for="`merge-reason-${uid}`"
                        class="mt-4 block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.moderation.fields.reason') }}
                    </label>
                    <textarea
                        :id="`merge-reason-${uid}`"
                        v-model="reason"
                        rows="2"
                        :maxlength="reasonMax"
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        data-testid="merge-reason"
                    />
                    <FormCheckbox
                        v-model="confirmed"
                        class="mt-2 w-full py-3"
                        data-testid="merge-confirmation"
                    >
                        <span class="text-base-primary">{{
                            t('forum.moderation.discussion.mergeConfirmation')
                        }}</span>
                    </FormCheckbox>
                </template>

                <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        type="button"
                        @click="emit('update:modelValue', false)"
                    >
                        {{ t('common.cancel') }}
                    </BaseButton>
                    <BaseButton
                        variant="danger"
                        size="sm"
                        type="submit"
                        :disabled="!valid || busy"
                        data-testid="merge-submit"
                    >
                        {{ t('forum.moderation.discussion.mergeConfirm') }}
                    </BaseButton>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>
