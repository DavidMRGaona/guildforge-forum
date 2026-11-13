<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import EmptyState from '@/components/ui/EmptyState.vue';
import PeoplePicker from '../../components/conversations/PeoplePicker.vue';
import PostEditor from '../../components/editor/PostEditor.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import { sendJson } from '../../forum/api';
import type { ForumPerson } from '../../types/forum';

/**
 * Starting a private conversation: who with (up to nine other people), a title and the first
 * message. Text and formatting only.
 */
const props = defineProps<{
    storeUrl: string;
    peopleUrl: string;
    indexUrl: string;
    startsLeft: number;
    barred: boolean;
    /** How many other people may be chosen */
    maxPeople: number;
    titleMaxLength: number;
    /** Someone chosen already («Escribir a» from elsewhere) */
    preselected: ForumPerson | null;
}>();

const { t } = useI18n();

const people = ref<ForumPerson[]>(props.preselected ? [props.preselected] : []);
const title = ref('');
const sending = ref(false);
const errors = ref<Record<string, string>>({});
const messageError = ref<string | null>(null);
const editor = ref<InstanceType<typeof PostEditor> | null>(null);

const breadcrumbs = computed(() => [{ name: t('forum.conversations.title'), url: props.indexUrl }]);

function validate(): boolean {
    const next: Record<string, string> = {};
    if (people.value.length === 0) {
        next.recipients = t('forum.conversations.create.errors.people');
    }
    const length = title.value.trim().length;
    if (length === 0 || length > props.titleMaxLength) {
        next.title = t('forum.conversations.create.errors.title', { max: props.titleMaxLength });
    }
    errors.value = next;

    return Object.keys(next).length === 0;
}

async function submit(document: JSONContent): Promise<void> {
    messageError.value = null;
    if (!validate()) {
        return;
    }

    sending.value = true;
    const answer = await sendJson<{ url: string }>(props.storeUrl, 'POST', {
        title: title.value,
        recipients: people.value.map((person) => person.id),
        document,
    });
    sending.value = false;

    if (answer.ok && answer.data) {
        editor.value?.clearDraft();
        router.visit(answer.data.url);

        return;
    }

    const fieldErrors: Record<string, string> = {};
    for (const [field, messages] of Object.entries(answer.errors)) {
        if (messages[0] && (field === 'title' || field.startsWith('recipients'))) {
            fieldErrors[field.startsWith('recipients') ? 'recipients' : field] = messages[0];
        }
    }
    errors.value = fieldErrors;
    messageError.value =
        answer.status === 419 || answer.status === 401
            ? t('forum.editor.errors.session')
            : Object.keys(fieldErrors).length > 0
              ? null
              : (answer.message ?? t('forum.posts.errors.save'));
}
</script>

<template>
    <div class="bg-surface shadow">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <ForumBreadcrumbs :items="breadcrumbs" />
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-base-primary sm:text-3xl">
                {{ t('forum.conversations.create.title') }}
            </h1>
        </div>
    </div>

    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
        <EmptyState
            v-if="barred"
            icon="document"
            :title="t('forum.conversations.create.barredTitle')"
            :description="t('forum.conversations.barred')"
            data-testid="new-conversation-barred"
        />
        <EmptyState
            v-else-if="startsLeft === 0"
            icon="document"
            :title="t('forum.conversations.create.limitTitle')"
            :description="t('forum.conversations.noStartsLeft')"
            data-testid="new-conversation-limit"
        />

        <!-- Not a <form>: the editor holds a form of its own (its link field) -->
        <div v-else class="space-y-6">
            <div>
                <p id="conversation-people" class="text-sm font-medium text-base-primary">
                    {{ t('forum.conversations.create.peopleLabel') }}
                </p>
                <p class="mb-2 text-sm text-base-muted">
                    {{ t('forum.conversations.create.peopleHelp', { max: maxPeople }) }}
                </p>
                <PeoplePicker
                    v-model="people"
                    :label="t('forum.conversations.create.peopleLabel')"
                    :people-url="peopleUrl"
                    :max="maxPeople"
                    :disabled="sending"
                    :error="errors.recipients ?? null"
                />
            </div>

            <div>
                <label
                    for="conversation-title"
                    class="block text-sm font-medium text-base-primary"
                    >{{ t('forum.conversations.create.titleLabel') }}</label
                >
                <input
                    id="conversation-title"
                    v-model="title"
                    type="text"
                    required
                    :maxlength="titleMaxLength"
                    autocomplete="off"
                    class="mt-1 block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                    :aria-invalid="errors.title ? 'true' : 'false'"
                    :aria-describedby="errors.title ? 'conversation-title-error' : undefined"
                    data-testid="conversation-title"
                    @keydown.enter.prevent="editor?.focus()"
                />
                <p
                    v-if="errors.title"
                    id="conversation-title-error"
                    role="alert"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ errors.title }}
                </p>
            </div>

            <div>
                <p class="mb-1 text-sm font-medium text-base-primary">
                    {{ t('forum.conversations.create.messageLabel') }}
                </p>
                <PostEditor
                    ref="editor"
                    :label="t('forum.conversations.create.messageLabel')"
                    draft-key="forum-new-conversation"
                    text-only
                    help-guide="forum.site.private-messages"
                    :submit-label="t('forum.conversations.create.submit')"
                    :submitting="sending"
                    :error="messageError"
                    @submit="submit"
                />
                <p class="mt-2 text-xs text-base-muted">
                    {{ t('forum.conversations.startsLeft', { count: startsLeft }, startsLeft) }}
                </p>
            </div>

            <Link
                :href="indexUrl"
                class="inline-flex min-h-[44px] items-center text-sm font-medium text-base-secondary hover:underline"
            >
                {{ t('forum.conversations.create.cancel') }}
            </Link>
        </div>
    </div>
</template>
