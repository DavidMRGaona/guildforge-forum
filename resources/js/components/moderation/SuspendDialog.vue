<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import { sendJson } from '../../forum/api';

/**
 * Suspending the author of a post in the forum: a reason they will read and how long (or with
 * no end). Only whoever moderates the whole forum gets the URL.
 */
const props = defineProps<{
    modelValue: boolean;
    suspendUrl: string;
    userId: string;
    userName: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    suspended: [];
}>();

const DURATIONS = ['1', '3', '7', '30', '90', 'forever'] as const;
const REASON_MAX = 500;

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);
const reason = ref('');
const duration = ref<string>('7');
const sending = ref(false);
const error = ref<string | null>(null);
const uid = Math.random().toString(36).slice(2, 10);

const valid = computed(() => reason.value.trim() !== '' && reason.value.length <= REASON_MAX);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            reason.value = '';
            duration.value = '7';
            error.value = null;
            dialog.value?.showModal();
        } else {
            dialog.value?.close();
        }
    }
);

function label(option: string): string {
    return option === 'forever'
        ? t('forum.suspensions.forever')
        : t('forum.suspensions.days', Number(option));
}

const durationOptions = computed(() =>
    DURATIONS.map((option) => ({ value: option, label: label(option) }))
);

function chooseDuration(value: string | number | null): void {
    if (DURATIONS.some((option) => option === value)) {
        duration.value = String(value);
    }
}

function cancel(): void {
    emit('update:modelValue', false);
}

async function submit(): Promise<void> {
    if (!valid.value || sending.value) {
        return;
    }
    sending.value = true;
    const answer = await sendJson(props.suspendUrl, 'POST', {
        user: props.userId,
        reason: reason.value.trim(),
        days: duration.value === 'forever' ? null : Number(duration.value),
    });
    sending.value = false;

    if (answer.ok) {
        emit('suspended');
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
            :aria-labelledby="`suspend-dialog-${uid}`"
            @cancel.prevent="cancel"
        >
            <form class="w-full rounded-lg bg-surface p-6 shadow-xl" @submit.prevent="submit">
                <h3 :id="`suspend-dialog-${uid}`" class="text-lg font-semibold text-base-primary">
                    {{
                        t('forum.suspensions.title', {
                            name: userName ?? t('forum.posts.deletedAccount'),
                        })
                    }}
                </h3>
                <p class="mt-2 text-sm text-base-secondary">{{ t('forum.suspensions.message') }}</p>

                <div class="mt-4">
                    <label
                        :for="`suspend-reason-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.suspensions.reason') }}
                    </label>
                    <textarea
                        :id="`suspend-reason-${uid}`"
                        v-model="reason"
                        rows="2"
                        required
                        :maxlength="REASON_MAX"
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        data-testid="suspend-reason"
                    />
                </div>

                <div class="mt-4">
                    <label
                        :for="`suspend-duration-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.suspensions.duration') }}
                    </label>
                    <FormSelect
                        :id="`suspend-duration-${uid}`"
                        class="mt-1"
                        :model-value="duration"
                        :options="durationOptions"
                        data-testid="suspend-duration"
                        @update:model-value="chooseDuration"
                    />
                </div>

                <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <BaseButton variant="secondary" size="sm" type="button" @click="cancel">
                        {{ t('common.cancel') }}
                    </BaseButton>
                    <BaseButton
                        variant="danger"
                        size="sm"
                        type="submit"
                        :disabled="!valid || sending"
                        data-testid="suspend-submit"
                    >
                        {{ t('forum.suspensions.submit') }}
                    </BaseButton>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>
