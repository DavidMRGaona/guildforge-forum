<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * What the header of a category says about it: that a private space (a campaign's) may be
 * entered by the forum's moderators, and that a read-only one takes no posts. Draws nothing for
 * a category that is neither.
 */
defineProps<{
    isPublicationSpace: boolean;
    readOnly: boolean;
}>();

const { t } = useI18n();
</script>

<template>
    <div v-if="isPublicationSpace || readOnly" class="flex flex-col gap-2">
        <p
            v-if="isPublicationSpace"
            class="bg-info-light text-info rounded-md px-3 py-2 text-sm"
            data-testid="space-moderation-notice"
        >
            {{ t('forum.spaces.moderationNotice') }}
        </p>
        <p
            v-if="readOnly"
            class="bg-surface border-default text-base-secondary rounded-md border px-3 py-2 text-sm"
            data-testid="space-read-only-notice"
        >
            {{ t('forum.spaces.readOnlyNotice') }}
        </p>
    </div>
</template>
