<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link } from '@inertiajs/vue3';
import { Ban, Mail, MessagesSquare, ShieldCheck, UserRound, X } from '@lucide/vue';
import ProfileBadges from '@/components/ui/ProfileBadges.vue';
import { buildAvatarUrl } from '@/utils/cloudinary';
import { sendJson } from '../../forum/api';
import { useAuthorCards } from '../../composables/useAuthorCards';
import type { ForumAuthorCard, ForumPost } from '../../types/forum';

export type AuthorCardModeration = 'title' | 'suspend';

/**
 * The writer's name as a button that opens their card (forum#134): the core's profile summary
 * (banner, avatar, public name, since when) when the reader may see the profile, the forum's title
 * and how many of their messages the reader may read, and quick actions, each only when it
 * applies. Without a visible profile it keeps what the post already shows (name, avatar, title).
 *
 * A disclosure like the moderation menu: the button says whether it is open, Escape or a click
 * outside closes it and the focus goes back to the name. On phones it is a sheet from the bottom.
 * The card is asked for when it opens and kept while the page is open (useAuthorCards).
 */
const props = withDefaults(
    defineProps<{
        post: ForumPost;
        /** The name the post shows, which the button reads */
        name: string;
        /** Whoever moderates the whole forum may give the person a title */
        canTitle?: boolean;
        /** Whoever moderates the whole forum may suspend the person from here */
        canSuspend?: boolean;
    }>(),
    { canTitle: false, canSuspend: false }
);

const emit = defineEmits<{ moderate: [action: AuthorCardModeration, title: string | null] }>();

const { t, locale } = useI18n();
const cache = useAuthorCards();

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const card = ref<ForumAuthorCard | null>(null);
const blockBusy = ref(false);
const blockStatus = ref<string | null>(null);
const root = ref<HTMLElement | null>(null);
const toggle = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const panelId = `forum-author-card-${Math.random().toString(36).slice(2, 10)}`;

/** One card per person on the page, whichever of their posts opened it */
const cacheKey = computed(() => props.post.authorId ?? props.post.id);

const profile = computed(() => card.value?.profile ?? null);
/** The profile's featured badges (forum#152), only with a profile the reader may see */
const badges = computed(() => profile.value?.badges ?? []);
const shownName = computed(() => profile.value?.publicName ?? props.name);
const title = computed(() => card.value?.title ?? props.post.authorTitle ?? null);
/** The profile's avatar, else the one the post shows (none in character), else the initial */
const avatarUrl = computed(
    () => profile.value?.avatarUrl ?? buildAvatarUrl(props.post.authorAvatarPublicId ?? null, 128)
);
const initial = computed(() => shownName.value.charAt(0).toUpperCase() || '?');

/** «Miembro desde» with the month and year as the core wrote them, never converted again */
const memberSince = computed(() => {
    const since = profile.value?.memberSince ?? null;
    const match = since === null ? null : /^(\d{4})-(\d{2})/.exec(since);
    if (match === null) {
        return null;
    }
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 15));

    return date.toLocaleDateString(locale.value, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
});

const topicUrl = computed(() => {
    const topic = card.value?.topic ?? null;

    return topic !== null && topic.count > 0 ? topic.url : null;
});

const moderates = computed(() => props.canTitle || props.canSuspend);

function onOutside(event: MouseEvent): void {
    if (root.value && event.target instanceof Node && !root.value.contains(event.target)) {
        close(false);
    }
}

async function show(): Promise<void> {
    const url = props.post.authorCardUrl ?? null;
    if (url === null) {
        return;
    }
    open.value = true;
    failed.value = false;
    blockStatus.value = null;
    document.addEventListener('click', onOutside);
    // From the page's cache each time: another post of the same person may have changed it
    loading.value = card.value === null;
    card.value = await cache.load(cacheKey.value, url);
    loading.value = false;
    failed.value = card.value === null;
    await nextTick();
    panel.value?.querySelector<HTMLElement>('[data-author-card-action]')?.focus();
}

function close(focusToggle = true): void {
    open.value = false;
    document.removeEventListener('click', onOutside);
    if (focusToggle) {
        toggle.value?.focus();
    }
}

async function toggleBlock(): Promise<void> {
    const current = card.value;
    const block = current?.block ?? null;
    if (current === null || block === null || blockBusy.value || props.post.authorId === null) {
        return;
    }
    blockBusy.value = true;
    blockStatus.value = null;
    const answer = block.blocked
        ? await sendJson(block.unblockUrl, 'DELETE')
        : await sendJson(block.blockUrl, 'POST', { account: props.post.authorId });
    blockBusy.value = false;
    if (!answer.ok) {
        blockStatus.value = answer.message ?? t('forum.authorCard.ignoreFailed');

        return;
    }
    const changed: ForumAuthorCard = { ...current, block: { ...block, blocked: !block.blocked } };
    card.value = changed;
    cache.update(cacheKey.value, changed);
    blockStatus.value = t(
        block.blocked ? 'forum.authorCard.unignored' : 'forum.authorCard.ignored',
        { name: shownName.value }
    );
}

function moderate(action: AuthorCardModeration): void {
    const current = title.value;
    close();
    // The title or the suspension changes what the card shows: it is asked again next time
    cache.forget(cacheKey.value);
    card.value = null;
    emit('moderate', action, current);
}

onBeforeUnmount(() => document.removeEventListener('click', onOutside));

const actionClass =
    'flex min-h-[44px] w-full items-center gap-2 rounded-md px-3 text-left text-sm text-base-primary hover:bg-muted disabled:opacity-50';
</script>

<template>
    <span ref="root" class="relative inline-flex min-w-0" @keydown.esc.stop="close()">
        <button
            ref="toggle"
            type="button"
            class="min-w-0 truncate rounded-sm text-left font-semibold text-base-primary underline-offset-2 hover:underline focus-visible:underline"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="panelId"
            :title="t('forum.authorCard.open', { name })"
            data-testid="author-card-toggle"
            @click="open ? close() : show()"
        >
            <slot>{{ name }}</slot>
        </button>
        <!-- On phones a veil behind the sheet takes the click that closes it -->
        <span
            v-if="open"
            class="fixed inset-0 z-40 bg-black/40 sm:hidden"
            aria-hidden="true"
            data-testid="author-card-veil"
            @click.stop="close(false)"
        />
        <span
            v-show="open"
            :id="panelId"
            ref="panel"
            role="region"
            :aria-label="t('forum.authorCard.region', { name: shownName })"
            class="fixed inset-x-0 bottom-0 z-50 block max-h-[85vh] overflow-y-auto rounded-t-2xl border border-default bg-surface pb-[env(safe-area-inset-bottom)] text-left font-normal shadow-xl sm:absolute sm:inset-x-auto sm:bottom-auto sm:left-0 sm:top-full sm:mt-1 sm:max-h-none sm:w-80 sm:overflow-visible sm:rounded-lg sm:pb-0"
            data-testid="author-card"
        >
            <span
                v-if="profile?.bannerUrl"
                class="block h-20 w-full rounded-t-2xl bg-muted bg-cover bg-center sm:rounded-t-lg"
                :style="{ backgroundImage: `url(${JSON.stringify(profile.bannerUrl)})` }"
                aria-hidden="true"
                data-testid="author-card-banner"
            />
            <span class="flex items-start gap-3 p-4" :class="{ '-mt-8': profile?.bannerUrl }">
                <img
                    v-if="avatarUrl"
                    :src="avatarUrl"
                    alt=""
                    class="h-16 w-16 shrink-0 rounded-full bg-muted object-cover ring-2 ring-primary"
                    data-testid="author-card-avatar"
                />
                <span
                    v-else
                    class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-muted text-2xl font-semibold text-primary ring-2 ring-primary"
                    aria-hidden="true"
                    >{{ initial }}</span
                >
                <span class="min-w-0 flex-1 pt-1" :class="{ 'pt-9': profile?.bannerUrl }">
                    <!-- The badges go whole below the name when they do not fit beside it -->
                    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <span
                            class="min-w-0 truncate text-base font-semibold text-base-primary"
                            data-testid="author-card-name"
                            >{{ shownName }}</span
                        >
                        <ProfileBadges
                            v-if="badges.length > 0"
                            class="shrink-0"
                            size="sm"
                            :badges="badges"
                        />
                    </span>
                    <span
                        v-if="title"
                        class="block truncate text-xs text-base-secondary"
                        data-testid="author-card-title"
                        >{{ title }}</span
                    >
                    <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                        <span
                            v-if="profile?.showsMembership"
                            class="rounded-full bg-primary-light px-2 py-0.5 font-medium text-primary"
                            data-testid="author-card-member"
                            >{{ t('forum.authorCard.member') }}</span
                        >
                        <span
                            v-if="memberSince"
                            class="text-base-muted"
                            data-testid="author-card-since"
                            >{{ t('forum.authorCard.memberSince', { date: memberSince }) }}</span
                        >
                    </span>
                    <span
                        v-if="profile && card?.postCount"
                        class="mt-1 block text-xs text-base-secondary"
                        data-testid="author-card-posts"
                        >{{ t('forum.authorCard.posts', card.postCount) }}</span
                    >
                </span>
                <button
                    type="button"
                    class="-mr-2 -mt-2 inline-flex min-h-[44px] min-w-[44px] shrink-0 items-center justify-center rounded-md text-base-secondary hover:bg-muted sm:hidden"
                    :aria-label="t('forum.authorCard.close')"
                    @click="close()"
                >
                    <X class="h-5 w-5" aria-hidden="true" />
                </button>
            </span>

            <span v-if="loading" class="block px-4 pb-4 text-sm text-base-muted" role="status">{{
                t('forum.authorCard.loading')
            }}</span>
            <span
                v-else-if="failed"
                class="block px-4 pb-4 text-sm text-red-600"
                role="alert"
                data-testid="author-card-failed"
                >{{ t('forum.authorCard.failed') }}</span
            >

            <span
                v-if="!loading"
                class="block border-t border-default p-2"
                data-testid="author-card-actions"
            >
                <Link
                    v-if="profile"
                    :href="profile.url"
                    :class="actionClass"
                    data-author-card-action
                    data-testid="author-card-profile"
                >
                    <UserRound class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ t('forum.authorCard.profile') }}
                </Link>
                <Link
                    v-if="post.messageUrl"
                    :href="post.messageUrl"
                    :class="actionClass"
                    data-author-card-action
                    data-testid="author-card-message"
                >
                    <Mail class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ t('forum.authorCard.message') }}
                </Link>
                <Link
                    v-if="topicUrl && card"
                    :href="topicUrl"
                    :class="actionClass"
                    data-author-card-action
                    data-testid="author-card-topic"
                >
                    <MessagesSquare class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ t('forum.authorCard.topicPosts', card.topic.count) }}
                </Link>
                <button
                    v-if="card?.block"
                    type="button"
                    :class="actionClass"
                    :disabled="blockBusy"
                    :aria-describedby="card.block.blocked ? undefined : `${panelId}-ignore`"
                    data-author-card-action
                    data-testid="author-card-ignore"
                    @click="toggleBlock"
                >
                    <Ban class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{
                        card.block.blocked
                            ? t('forum.authorCard.unignore')
                            : t('forum.authorCard.ignore')
                    }}
                </button>
                <span
                    v-if="card?.block && !card.block.blocked"
                    :id="`${panelId}-ignore`"
                    class="block px-3 pb-1 text-xs text-base-muted"
                    >{{ t('forum.authorCard.ignoreHint') }}</span
                >
                <span
                    v-if="blockStatus"
                    class="block px-3 pb-1 text-xs text-base-secondary"
                    role="status"
                    data-testid="author-card-status"
                    >{{ blockStatus }}</span
                >
                <template v-if="moderates">
                    <span
                        class="mt-1 flex items-center gap-1 border-t border-default px-3 pb-1 pt-2 text-xs font-medium text-base-muted"
                    >
                        <ShieldCheck class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ t('forum.authorCard.moderation') }}
                    </span>
                    <button
                        v-if="canTitle"
                        type="button"
                        :class="actionClass"
                        data-author-card-action
                        data-testid="author-card-title-action"
                        @click="moderate('title')"
                    >
                        {{ title ? t('forum.titles.change') : t('forum.titles.set') }}
                    </button>
                    <button
                        v-if="canSuspend"
                        type="button"
                        :class="[actionClass, 'text-red-600 dark:text-red-400']"
                        data-author-card-action
                        data-testid="author-card-suspend"
                        @click="moderate('suspend')"
                    >
                        {{ t('forum.authorCard.suspend') }}
                    </button>
                </template>
            </span>
        </span>
    </span>
</template>
