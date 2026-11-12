<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useMergedDiscussion } from '../../composables/useMergedDiscussion';
import { sendJson } from '../../forum/api';
import { describeNumbers } from '../../composables/useSplitSelection';
import type { ForumDiscussionModeration } from '../../types/forum';
import ModerationDialog, { type ModerationDialogFields } from './ModerationDialog.vue';

/**
 * While a moderator chooses posts to split off: a bar fixed at the bottom of the screen with how
 * many are chosen, and the dialog that asks the new discussion's title and category, a reason
 * and a box to tick before anything moves. Once split, the new discussion opens.
 */
const props = defineProps<{
    moderation: ForumDiscussionModeration;
    numbers: number[];
}>();

const emit = defineEmits<{
    cancel: [];
    done: [];
}>();

const { t } = useI18n();
const reportMerged = useMergedDiscussion();
const dialogOpen = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

const tooMany = computed(() => props.numbers.length > props.moderation.limits.splitMax);
const ownCategory = computed(() => props.moderation.splitCategories[0]?.id ?? '');

async function split(fields: ModerationDialogFields): Promise<void> {
    if (props.moderation.splitUrl === null) {
        return;
    }
    busy.value = true;
    error.value = null;
    const answer = await sendJson<{ url: string }>(props.moderation.splitUrl, 'POST', {
        numbers: props.numbers,
        title: fields.title,
        category: fields.category,
        reason: fields.reason === '' ? undefined : fields.reason,
        confirmed: true,
    });
    busy.value = false;

    if (!answer.ok || answer.data === null) {
        reportMerged(answer.mergedInto);
        error.value = answer.message ?? t('forum.moderation.errors.generic');

        return;
    }

    dialogOpen.value = false;
    emit('done');
    router.visit(answer.data.url);
}
</script>

<template>
    <div
        role="region"
        :aria-label="t('forum.moderation.discussion.split')"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-default bg-surface shadow-lg"
        data-testid="split-bar"
    >
        <div
            class="mx-auto flex max-w-4xl flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <p class="text-sm text-base-primary" aria-live="polite">
                {{ t('forum.moderation.discussion.splitChosen', numbers.length) }}
            </p>
            <div class="flex flex-wrap gap-2">
                <BaseButton variant="secondary" size="sm" type="button" @click="emit('cancel')">
                    {{ t('forum.moderation.discussion.splitCancel') }}
                </BaseButton>
                <BaseButton
                    size="sm"
                    type="button"
                    :disabled="numbers.length === 0 || tooMany || busy"
                    data-testid="split-continue"
                    @click="dialogOpen = true"
                >
                    {{ t('forum.moderation.discussion.splitContinue') }}
                </BaseButton>
            </div>
        </div>
        <ModerationDialog
            v-model="dialogOpen"
            :title="t('forum.moderation.discussion.splitTitle')"
            :message="
                t('forum.moderation.discussion.splitMessage', { numbers: describeNumbers(numbers) })
            "
            :confirm-label="t('forum.moderation.discussion.splitConfirm')"
            ask-reason
            :reason-max="moderation.limits.reasonMax"
            ask-title
            :title-label="t('forum.moderation.fields.splitTitle')"
            :title-min="moderation.limits.titleMin"
            :title-max="moderation.limits.titleMax"
            :move-targets="moderation.splitCategories"
            :category-label="t('forum.moderation.fields.splitCategory')"
            :initial-category="ownCategory"
            :confirmation="t('forum.moderation.discussion.splitConfirmation')"
            :busy="busy"
            :error="error"
            @confirm="split"
        />
    </div>
</template>
