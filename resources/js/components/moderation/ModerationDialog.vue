<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import type { ForumMoveTarget } from '../../types/forum';

export interface ModerationDialogFields {
    reason: string;
    title: string;
    category: string;
}

/**
 * What a moderation action asks before it runs: a reason (optional, kept in the log), a new
 * title, a category to move to, and for what cannot be undone a box to tick. Nothing is sent
 * until the form is valid.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        title: string;
        message?: string;
        confirmLabel: string;
        danger?: boolean;
        askReason?: boolean;
        reasonMax?: number;
        askTitle?: boolean;
        initialTitle?: string;
        titleMin?: number;
        titleMax?: number;
        moveTargets?: ForumMoveTarget[];
        confirmation?: string | null;
        busy?: boolean;
        error?: string | null;
    }>(),
    {
        message: '',
        danger: false,
        askReason: false,
        reasonMax: 500,
        askTitle: false,
        initialTitle: '',
        titleMin: 3,
        titleMax: 120,
        moveTargets: () => [],
        confirmation: null,
        busy: false,
        error: null,
    }
);

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    confirm: [fields: ModerationDialogFields];
}>();

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);
const reason = ref('');
const newTitle = ref('');
const category = ref('');
const confirmed = ref(false);
const uid = Math.random().toString(36).slice(2, 10);

const asksMove = computed(() => props.moveTargets.length > 0);
const titleLength = computed(() => newTitle.value.trim().replace(/\s+/g, ' ').length);

const valid = computed(
    () =>
        (!props.askTitle ||
            (titleLength.value >= props.titleMin && titleLength.value <= props.titleMax)) &&
        (!asksMove.value || category.value !== '') &&
        (props.confirmation === null || confirmed.value) &&
        reason.value.length <= props.reasonMax
);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            reason.value = '';
            newTitle.value = props.initialTitle;
            category.value = '';
            confirmed.value = false;
            dialog.value?.showModal();
        } else {
            dialog.value?.close();
        }
    }
);

function cancel(): void {
    emit('update:modelValue', false);
}

function submit(): void {
    if (!valid.value || props.busy) {
        return;
    }
    emit('confirm', {
        reason: reason.value.trim(),
        title: newTitle.value,
        category: category.value,
    });
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border-0 bg-transparent p-0 backdrop:bg-neutral-900/60"
            :aria-labelledby="`moderation-dialog-${uid}`"
            @cancel.prevent="cancel"
        >
            <form class="w-full rounded-lg bg-surface p-6 shadow-xl" @submit.prevent="submit">
                <h3
                    :id="`moderation-dialog-${uid}`"
                    class="text-lg font-semibold text-base-primary"
                >
                    {{ title }}
                </h3>
                <p v-if="message" class="mt-2 text-sm text-base-secondary">{{ message }}</p>

                <div v-if="askTitle" class="mt-4">
                    <label
                        :for="`moderation-title-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.moderation.fields.title') }}
                    </label>
                    <input
                        :id="`moderation-title-${uid}`"
                        v-model="newTitle"
                        type="text"
                        :maxlength="titleMax"
                        required
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        :aria-describedby="`moderation-title-help-${uid}`"
                        data-testid="moderation-title"
                    />
                    <p :id="`moderation-title-help-${uid}`" class="mt-1 text-xs text-base-muted">
                        {{
                            t('forum.moderation.fields.titleHelp', { min: titleMin, max: titleMax })
                        }}
                    </p>
                </div>

                <div v-if="asksMove" class="mt-4">
                    <label
                        :for="`moderation-category-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.moderation.fields.category') }}
                    </label>
                    <select
                        :id="`moderation-category-${uid}`"
                        v-model="category"
                        required
                        class="mt-1 block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary"
                        data-testid="moderation-category"
                    >
                        <option value="" disabled>
                            {{ t('forum.moderation.fields.categoryPlaceholder') }}
                        </option>
                        <option v-for="target in moveTargets" :key="target.id" :value="target.id">
                            {{
                                target.parentName
                                    ? `${target.parentName} › ${target.name}`
                                    : target.name
                            }}
                        </option>
                    </select>
                </div>

                <div v-if="askReason" class="mt-4">
                    <label
                        :for="`moderation-reason-${uid}`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('forum.moderation.fields.reason') }}
                    </label>
                    <textarea
                        :id="`moderation-reason-${uid}`"
                        v-model="reason"
                        rows="2"
                        :maxlength="reasonMax"
                        class="mt-1 block w-full rounded-md border border-default bg-surface px-3 py-2 text-base-primary"
                        :aria-describedby="`moderation-reason-help-${uid}`"
                        data-testid="moderation-reason"
                    />
                    <p :id="`moderation-reason-help-${uid}`" class="mt-1 text-xs text-base-muted">
                        {{ t('forum.moderation.fields.reasonHelp') }}
                    </p>
                </div>

                <label
                    v-if="confirmation"
                    class="mt-4 flex items-start gap-2 text-sm text-base-primary"
                >
                    <input
                        v-model="confirmed"
                        type="checkbox"
                        class="mt-1 h-4 w-4"
                        required
                        data-testid="moderation-confirmation"
                    />
                    <span>{{ confirmation }}</span>
                </label>

                <p v-if="error" role="alert" class="mt-3 text-sm text-red-600">{{ error }}</p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <BaseButton variant="secondary" size="sm" type="button" @click="cancel">
                        {{ t('common.cancel') }}
                    </BaseButton>
                    <BaseButton
                        :variant="danger ? 'danger' : 'primary'"
                        size="sm"
                        type="submit"
                        :disabled="!valid || busy"
                        data-testid="moderation-submit"
                    >
                        {{ confirmLabel }}
                    </BaseButton>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>
