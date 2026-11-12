<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/composables/useAuth';
import { useMenuBadges } from '@/composables/useMenuBadges';
import { useForumPulse } from '../../composables/useForumPulse';
import { PULSE_UNREAD_CAP, pollPulse } from '../../live/transports';

/**
 * Lives in the site's `after-header` slot and draws nothing visible. Every minute (while the tab
 * is shown, and only for people with a session) it asks whether the discussions the person
 * follows have posts they did not read, and marks the «Foro» link of the menu through the core's
 * menu badges. The menu draws the dot and reads the text inside the link; this component only
 * announces a change, politely and without moving the focus.
 */
const MENU_KEY = 'forum:forum.index';

const { t } = useI18n();
const { isAuthenticated } = useAuth();
const { setBadge, clearBadge } = useMenuBadges();

const { unreadFollowed } = useForumPulse({ transport: pollPulse(), enabled: isAuthenticated });

/** What the announcement says: a sentence of its own */
const message = computed(() => {
    const count = unreadFollowed.value;
    if (count <= 0) {
        return '';
    }

    return count >= PULSE_UNREAD_CAP
        ? t('forum.live.unreadFollowedMany')
        : t('forum.live.unreadFollowed', { count }, count);
});

/** What the link reads after its name: «Foro, 3 temas sin leer» */
const badge = computed(() => {
    const count = unreadFollowed.value;
    if (count <= 0) {
        return '';
    }

    return count >= PULSE_UNREAD_CAP
        ? t('forum.live.unreadFollowedBadgeMany')
        : t('forum.live.unreadFollowedBadge', { count }, count);
});

watch(
    badge,
    (text) => {
        if (text === '') {
            clearBadge(MENU_KEY);
        } else {
            setBadge(MENU_KEY, text);
        }
    },
    { immediate: true }
);

onBeforeUnmount(() => {
    clearBadge(MENU_KEY);
});
</script>

<template>
    <p role="status" aria-live="polite" aria-atomic="true" class="sr-only">
        {{ message }}
    </p>
</template>
