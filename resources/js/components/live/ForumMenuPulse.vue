<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/composables/useAuth';
import { useMenuBadges } from '@/composables/useMenuBadges';
import { useForumPulse } from '../../composables/useForumPulse';
import { useForumVisibility } from '../../composables/useForumVisibility';
import { ACCOUNT_EVENTS, accountChannel, useChannelFeed } from '../../live/channels';
import { PULSE_UNREAD_CAP, livePulse } from '../../live/transports';

/**
 * Lives in the site's `after-header` slot and draws nothing visible. For people with a session it
 * asks whether the discussions the person follows have posts they did not read: when their own
 * real-time channel says something changed, or every minute (while the tab is shown) without it, and marks the «Foro» link of the menu through the core's
 * menu badges. The menu draws the dot and reads the text inside the link; this component only
 * announces a change, politely and without moving the focus.
 */
const MENU_KEY = 'forum:forum.index';

const { t } = useI18n();
const { isAuthenticated, user } = useAuth();
const { setBadge, clearBadge } = useMenuBadges();
// A forum in preview the person may not see has no menu link to mark: nothing is asked
const forumVisible = useForumVisibility();
const listening = computed(() => isAuthenticated.value && forumVisible.value);

// The person's own channel says when to ask; without real time it asks every minute
const own = useChannelFeed(() => {
    const id = user.value?.id;

    return forumVisible.value && typeof id === 'string' && id !== '' ? accountChannel(id) : null;
}, ACCOUNT_EVENTS);
const { unreadFollowed, unreadConversations } = useForumPulse({
    transport: livePulse(own),
    enabled: listening,
});

/** «2 temas», «muchos temas» */
function topicsPart(count: number): string {
    return count >= PULSE_UNREAD_CAP
        ? t('forum.live.topicsMany')
        : t('forum.live.topics', { count }, count);
}

/** «una conversación», «3 conversaciones» */
function conversationsPart(count: number): string {
    return t('forum.live.conversations', { count }, count);
}

/** What the announcement says: one sentence naming whatever is unread */
const message = computed(() => {
    const count = unreadFollowed.value;
    const conversations = unreadConversations.value;
    if (count > 0 && conversations > 0) {
        return t('forum.live.unreadBoth', {
            topics: topicsPart(count),
            conversations: conversationsPart(conversations),
        });
    }
    if (count > 0) {
        return count >= PULSE_UNREAD_CAP
            ? t('forum.live.unreadFollowedMany')
            : t('forum.live.unreadFollowed', { count }, count);
    }

    return conversations > 0
        ? t('forum.live.unreadConversations', { count: conversations }, conversations)
        : '';
});

/** What the link reads after its name: «Foro, 2 temas y una conversación sin leer» */
const badge = computed(() => {
    const count = unreadFollowed.value;
    const conversations = unreadConversations.value;
    if (count > 0 && conversations > 0) {
        return t('forum.live.unreadBothBadge', {
            topics: topicsPart(count),
            conversations: conversationsPart(conversations),
        });
    }
    if (count > 0) {
        return count >= PULSE_UNREAD_CAP
            ? t('forum.live.unreadFollowedBadgeMany')
            : t('forum.live.unreadFollowedBadge', { count }, count);
    }

    return conversations > 0
        ? t('forum.live.unreadConversationsBadge', { count: conversations }, conversations)
        : '';
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
