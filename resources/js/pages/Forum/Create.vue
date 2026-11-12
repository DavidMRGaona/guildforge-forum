<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { JSONContent } from '@tiptap/core';
import EmptyState from '@/components/ui/EmptyState.vue';
import SpeakAsSelect from '../../components/characters/SpeakAsSelect.vue';
import PostEditor from '../../components/editor/PostEditor.vue';
import ForumBreadcrumbs from '../../components/forum/ForumBreadcrumbs.vue';
import { sendJson } from '../../forum/api';
import type {
    ForumCategoryOption,
    ForumEditorSettings,
    ForumSuspensionNotice,
    ForumTopic,
    ForumCharacterPicker,
} from '../../types/forum';

const props = defineProps<{
    categories: ForumCategoryOption[];
    topics: ForumTopic[];
    selectedCategoryId: string | null;
    storeUrl: string;
    cancelUrl: string;
    editor: ForumEditorSettings;
    /** «Hablar como»: the reader's characters with where each may be used; null when off for them */
    characters?: ForumCharacterPicker | null;
    suspension: ForumSuspensionNotice | null;
    limits: { titleMin: number; titleMax: number; topicsMax: number };
}>();

const { t } = useI18n();

const title = ref('');
const speaker = ref<string | null>(null);
const categoryId = ref<string>(
    props.selectedCategoryId ?? (props.categories.length === 1 ? props.categories[0]!.id : '')
);
const chosenTopics = ref<string[]>([]);
const pinned = ref(false);
const closed = ref(false);
const sending = ref(false);
const errors = ref<Record<string, string>>({});
const postError = ref<string | null>(null);
const postEditor = ref<InstanceType<typeof PostEditor> | null>(null);

const breadcrumbs = computed(() => [{ name: t('forum.title'), url: props.cancelUrl }]);
const selected = computed(
    () => props.categories.find((category) => category.id === categoryId.value) ?? null
);
const canModerate = computed(() => selected.value?.canModerate === true);
const topicsFull = computed(() => chosenTopics.value.length >= props.limits.topicsMax);

function categoryLabel(category: ForumCategoryOption): string {
    return category.parentName ? `${category.parentName} › ${category.name}` : category.name;
}

function validate(): boolean {
    const next: Record<string, string> = {};
    const length = title.value.trim().length;
    if (length < props.limits.titleMin || length > props.limits.titleMax) {
        next.title = t('forum.create.errors.title', {
            min: props.limits.titleMin,
            max: props.limits.titleMax,
        });
    }
    if (categoryId.value === '') {
        next.category = t('forum.create.errors.category');
    }
    errors.value = next;

    return Object.keys(next).length === 0;
}

async function submit(document: JSONContent): Promise<void> {
    postError.value = null;
    if (!validate()) {
        window.document
            .getElementById(errors.value.title ? 'forum-title' : 'forum-category')
            ?.focus();

        return;
    }

    sending.value = true;
    const answer = await sendJson<{ url: string }>(props.storeUrl, 'POST', {
        title: title.value,
        category: categoryId.value,
        topics: chosenTopics.value,
        pinned: canModerate.value && pinned.value,
        closed: canModerate.value && closed.value,
        document,
        character: speaker.value,
    });
    sending.value = false;

    if (answer.ok && answer.data) {
        postEditor.value?.clearDraft();
        router.visit(answer.data.url);

        return;
    }

    const fieldErrors: Record<string, string> = {};
    for (const [field, messages] of Object.entries(answer.errors)) {
        if (messages[0] && field !== 'document') {
            fieldErrors[field] = messages[0];
        }
    }
    errors.value = fieldErrors;
    postError.value =
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
                {{ t('forum.create.title') }}
            </h1>
        </div>
    </div>

    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
        <EmptyState
            v-if="suspension"
            icon="document"
            :title="t('forum.suspensions.title_self')"
            :description="
                suspension.untilLabel
                    ? t('forum.suspensions.noticeUntil', {
                          until: suspension.untilLabel,
                          reason: suspension.reason,
                      })
                    : t('forum.suspensions.noticeForever', { reason: suspension.reason })
            "
            data-testid="create-suspended"
        />
        <EmptyState
            v-else-if="categories.length === 0"
            icon="document"
            :title="t('forum.create.noCategories')"
            :description="t('forum.create.noCategoriesDescription')"
        />

        <!-- Not a <form>: the editor holds a form of its own (its link field) -->
        <div v-else class="space-y-6">
            <div>
                <label for="forum-title" class="block text-sm font-medium text-base-primary">{{
                    t('forum.create.titleLabel')
                }}</label>
                <input
                    id="forum-title"
                    v-model="title"
                    type="text"
                    required
                    :maxlength="limits.titleMax"
                    autocomplete="off"
                    class="mt-1 block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                    :aria-invalid="errors.title ? 'true' : 'false'"
                    :aria-describedby="errors.title ? 'forum-title-error' : 'forum-title-help'"
                    @keydown.enter.prevent="postEditor?.focus()"
                />
                <p
                    v-if="errors.title"
                    id="forum-title-error"
                    role="alert"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ errors.title }}
                </p>
                <p v-else id="forum-title-help" class="mt-1 text-sm text-base-muted">
                    {{
                        t('forum.create.titleHelp', { min: limits.titleMin, max: limits.titleMax })
                    }}
                </p>
            </div>

            <div>
                <label for="forum-category" class="block text-sm font-medium text-base-primary">{{
                    t('forum.create.categoryLabel')
                }}</label>
                <select
                    id="forum-category"
                    v-model="categoryId"
                    required
                    class="mt-1 block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                    :aria-invalid="errors.category ? 'true' : 'false'"
                    :aria-describedby="errors.category ? 'forum-category-error' : undefined"
                >
                    <option value="" disabled>{{ t('forum.create.categoryPlaceholder') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">
                        {{ categoryLabel(category) }}
                    </option>
                </select>
                <p
                    v-if="errors.category"
                    id="forum-category-error"
                    role="alert"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ errors.category }}
                </p>
            </div>

            <fieldset v-if="topics.length > 0">
                <legend class="text-sm font-medium text-base-primary">
                    {{ t('forum.create.topicsLabel') }}
                </legend>
                <p class="text-sm text-base-muted">
                    {{ t('forum.create.topicsHelp', { max: limits.topicsMax }) }}
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <label
                        v-for="topic in topics"
                        :key="topic.key"
                        class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-full border border-default px-3 text-sm text-base-secondary has-[:checked]:border-primary has-[:checked]:bg-primary-light has-[:checked]:text-primary"
                    >
                        <input
                            v-model="chosenTopics"
                            type="checkbox"
                            class="h-4 w-4"
                            :value="topic.key"
                            :disabled="topicsFull && !chosenTopics.includes(topic.key)"
                        />
                        {{ topic.label }}
                    </label>
                </div>
                <p v-if="errors.topics" role="alert" class="mt-1 text-sm text-red-600">
                    {{ errors.topics }}
                </p>
            </fieldset>

            <fieldset v-if="canModerate" class="space-y-2">
                <legend class="text-sm font-medium text-base-primary">
                    {{ t('forum.create.moderationLabel') }}
                </legend>
                <label class="flex min-h-[44px] items-center gap-2 text-sm text-base-secondary">
                    <input v-model="pinned" type="checkbox" class="h-4 w-4" />
                    {{ t('forum.create.pinned') }}
                </label>
                <label class="flex min-h-[44px] items-center gap-2 text-sm text-base-secondary">
                    <input v-model="closed" type="checkbox" class="h-4 w-4" />
                    {{ t('forum.create.closed') }}
                </label>
                <p v-if="errors.moderation" role="alert" class="text-sm text-red-600">
                    {{ errors.moderation }}
                </p>
            </fieldset>

            <div>
                <p id="forum-first-post" class="mb-1 text-sm font-medium text-base-primary">
                    {{ t('forum.create.firstPostLabel') }}
                </p>
                <SpeakAsSelect
                    v-if="characters"
                    v-model="speaker"
                    class="mb-2"
                    :picker="characters"
                    :category-id="categoryId === '' ? null : categoryId"
                    :disabled="sending"
                />
                <PostEditor
                    ref="postEditor"
                    :label="t('forum.create.firstPostLabel')"
                    draft-key="forum-new-discussion"
                    :upload-url="editor.uploadUrl"
                    :max-images="editor.maxImages"
                    :image-types="editor.imageTypes"
                    :external-image-url="editor.externalImageUrl"
                    :external-image-hosts="editor.externalImageHosts"
                    :embed-url="editor.embedUrl"
                    :embed-services="editor.embedServices"
                    :submit-label="t('forum.create.submit')"
                    :submitting="sending"
                    :error="postError"
                    @submit="submit"
                />
            </div>

            <Link
                :href="cancelUrl"
                class="inline-flex min-h-[44px] items-center text-sm font-medium text-base-secondary hover:underline"
            >
                {{ t('forum.create.cancel') }}
            </Link>
        </div>
    </div>
</template>
