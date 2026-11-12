<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * What the header of a category says about it: that only the forum's moderators see it because a
 * module it depends on is missing, that a private space (a campaign's) may be entered by the
 * forum's moderators, and that a read-only one takes no posts. Draws nothing for a category that is
 * none of these.
 */
withDefaults(
    defineProps<{
        isPublicationSpace: boolean;
        readOnly: boolean;
        unavailable?: boolean;
    }>(),
    { unavailable: false }
);

const { t } = useI18n();
</script>

<template>
    <div v-if="unavailable || isPublicationSpace || readOnly" class="flex flex-col gap-2">
        <p
            v-if="unavailable"
            class="border-warning bg-warning-light text-base-primary rounded-md border px-3 py-2 text-sm"
            data-testid="category-unavailable-notice"
        >
            {{ t('forum.spaces.unavailableNotice') }}
        </p>
        <p
            v-if="isPublicationSpace && !unavailable"
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
