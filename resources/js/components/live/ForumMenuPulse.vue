<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/composables/useAuth';
import { useForumPulse } from '../../composables/useForumPulse';
import { pollPulse } from '../../live/transports';
import { PULSE_CAP } from '../../forum/live';

/**
 * Lives in the site's `after-header` slot and draws nothing: it asks every minute (while the tab
 * is shown, and only for people with a session) whether the discussions the person follows have
 * posts they did not read, and marks the «Foro» link of the menu with a dot when they do.
 * The dot is a rule of the module's sheet driven by an attribute on <html>, so it survives the
 * menu being drawn again; screen readers get the same through a description of the link and
 * a polite announcement when the count changes.
 */
const { t } = useI18n();
const { isAuthenticated } = useAuth();
const statusId = useId();

const { unreadFollowed } = useForumPulse({ transport: pollPulse(), enabled: isAuthenticated });

const message = computed(() => {
    const count = unreadFollowed.value;
    if (count <= 0) {
        return '';
    }

    return count >= PULSE_CAP
        ? t('forum.live.unreadFollowedMany')
        : t('forum.live.unreadFollowed', { count }, count);
});

function forumLinks(): HTMLAnchorElement[] {
    return Array.from(document.querySelectorAll<HTMLAnchorElement>('header a[href]')).filter(
        (link) => link.getAttribute('href')?.replace(/\/$/, '') === '/foro'
    );
}

function describeLinks(): void {
    for (const link of forumLinks()) {
        if (unreadFollowed.value > 0) {
            link.setAttribute('aria-describedby', statusId);
        } else if (link.getAttribute('aria-describedby') === statusId) {
            link.removeAttribute('aria-describedby');
        }
    }
}

function mark(): void {
    if (unreadFollowed.value > 0) {
        document.documentElement.dataset.forumUnread = '';
    } else {
        delete document.documentElement.dataset.forumUnread;
    }
    describeLinks();
}

let menu: MutationObserver | null = null;

onMounted(() => {
    // The mobile menu draws its links when it opens: they get the description too
    const header = document.querySelector('header');
    if (header !== null && typeof MutationObserver !== 'undefined') {
        menu = new MutationObserver(describeLinks);
        menu.observe(header, { childList: true, subtree: true });
    }
});

watch(unreadFollowed, mark);
onBeforeUnmount(() => {
    menu?.disconnect();
    // The pulse already forgot its count: the mark and the descriptions go with it
    mark();
});
</script>

<template>
    <p :id="statusId" role="status" aria-live="polite" aria-atomic="true" class="sr-only">
        {{ message }}
    </p>
</template>
