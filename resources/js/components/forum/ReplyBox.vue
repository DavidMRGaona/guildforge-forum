<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { useAuth } from '@/composables/useAuth';
import { useRoutes } from '@/composables/useRoutes';
import SpeakAsSelect from '../characters/SpeakAsSelect.vue';
import PostEditor from '../editor/PostEditor.vue';
import type { ForumReplySettings } from '../../types/forum';

/**
 * Where a reader replies to a discussion: the editor when they may post, otherwise why they
 * may not (no session, an unverified email, a suspension in the forum, a discussion awaiting
 * approval or closed, or a category they may not write in). Used by the discussion page and by the conversation of a record's page.
 */
const props = withDefaults(
    defineProps<{
        reply: ForumReplySettings;
        draftKey: string;
        isClosed: boolean;
        label: string;
        submitLabel: string;
        sending: boolean;
        error: string | null;
        /** Follows the sign-in link for guests ("…para responder.") */
        signInSuffix: string;
        /** Why the discussion is closed, when the page can say more than that it is */
        closedNotice?: string | null;
        /** Its first post awaits approval: it takes replies once approved */
        isAwaitingApproval?: boolean;
    }>(),
    { closedNotice: null, isAwaitingApproval: false }
);

/** The document, and the character it speaks as (null out of character) */
const emit = defineEmits<{ submit: [document: JSONContent, characterId: string | null] }>();

const { t } = useI18n();
const { isAuthenticated, mustVerifyEmail } = useAuth();
const routes = useRoutes();

const editor = ref<InstanceType<typeof PostEditor> | null>(null);
// The last character the reader used in this discussion, when they still may
const speaker = ref<string | null>(props.reply.characters?.suggestedId ?? null);

/** Why there is no editor, when there is none */
const notice = computed<
    'guest' | 'verify' | 'suspended' | 'awaiting' | 'closed' | 'forbidden' | null
>(() => {
    if (props.reply.canPost) {
        return null;
    }
    if (!isAuthenticated.value) {
        return 'guest';
    }
    if (mustVerifyEmail.value) {
        return 'verify';
    }
    if (props.reply.suspension) {
        return 'suspended';
    }
    if (props.isAwaitingApproval) {
        return 'awaiting';
    }

    return props.isClosed ? 'closed' : 'forbidden';
});

defineExpose({
    quote: (source: { postId: string; text: string }) => editor.value?.quote(source),
    focus: () => editor.value?.focus(),
    clear: () => editor.value?.clear(),
});
</script>

<template>
    <div v-if="reply.canPost" class="space-y-3">
        <slot name="intro" />
        <SpeakAsSelect
            v-if="reply.characters"
            v-model="speaker"
            :picker="reply.characters"
            :disabled="sending"
        />
        <PostEditor
            ref="editor"
            :label="label"
            :draft-key="draftKey"
            :mentions-url="reply.mentionsUrl"
            :upload-url="reply.uploadUrl"
            :max-images="reply.maxImages"
            :image-types="reply.imageTypes"
            :external-image-url="reply.externalImageUrl"
            :external-image-hosts="reply.externalImageHosts"
            :submit-label="submitLabel"
            :submitting="sending"
            :error="error"
            @submit="(document: JSONContent) => emit('submit', document, speaker)"
        />
    </div>

    <p v-else class="rounded-lg border border-default bg-surface p-4 text-sm text-base-secondary">
        <template v-if="notice === 'guest'">
            <Link :href="routes.auth.login" class="font-medium text-primary hover:underline">{{
                t('forum.posts.signIn')
            }}</Link>
            {{ signInSuffix }}
        </template>
        <template v-else-if="notice === 'verify'">{{ t('forum.posts.verifyToReply') }}</template>
        <span
            v-else-if="notice === 'suspended' && reply.suspension"
            data-testid="reply-suspended"
            >{{
                reply.suspension.untilLabel
                    ? t('forum.suspensions.noticeUntil', {
                          until: reply.suspension.untilLabel,
                          reason: reply.suspension.reason,
                      })
                    : t('forum.suspensions.noticeForever', { reason: reply.suspension.reason })
            }}</span
        >
        <template v-else-if="notice === 'awaiting'">{{
            t('forum.moderation.awaitingReplies')
        }}</template>
        <template v-else-if="notice === 'closed'">{{
            closedNotice ?? t('forum.posts.closedNotice')
        }}</template>
        <template v-else>{{ t('forum.posts.cannotReply') }}</template>
    </p>
</template>
