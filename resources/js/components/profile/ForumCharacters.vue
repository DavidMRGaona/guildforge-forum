<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, VenetianMask } from '@lucide/vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import { useVerificationGate } from '@/composables/useVerificationGate';
import CharacterAvatar from '../characters/CharacterAvatar.vue';
import CharacterDialog, { type CharacterDialogStart } from '../characters/CharacterDialog.vue';
import ModerationDialog from '../moderation/ModerationDialog.vue';
import { sendJson } from '../../forum/api';
import type { ForumCharacterProfile, ForumOwnedCharacter } from '../../types/forum';

/**
 * The «Personajes» tab of the profile: the person's characters, to create, change, retire,
 * bring back and delete. One with posts is retired instead of deleted, and its posts keep
 * showing it; one moderation retired stays retired.
 */
const { t } = useI18n();
const gate = useVerificationGate();

const CHARACTERS_URL = '/foro/personajes';

type LoadState = 'loading' | 'ready' | 'error';

const state = ref<LoadState>('loading');
const profile = ref<ForumCharacterProfile | null>(null);
const error = ref<string | null>(null);
const status = ref('');
const busy = ref<string | null>(null);
const dialogOpen = ref(false);
const editing = ref<ForumOwnedCharacter | null>(null);
const deleting = ref<ForumOwnedCharacter | null>(null);
const confirmOpen = ref(false);
const deleteError = ref<string | null>(null);

const canCreate = computed(
    () =>
        profile.value !== null &&
        profile.value.createUrl !== null &&
        profile.value.items.length < profile.value.limits.perAccount
);
const start = computed<CharacterDialogStart | null>(() =>
    editing.value === null
        ? null
        : {
              name: editing.value.name,
              description: editing.value.description,
              categoryId: editing.value.categoryId,
              initials: editing.value.initials,
              portraitUrl: editing.value.portraitUrl,
              updateUrl: editing.value.actions.updateUrl,
          }
);

async function load(): Promise<void> {
    state.value = 'loading';
    try {
        const response = await fetch(CHARACTERS_URL, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok || gate.isVerificationRequired(response)) {
            state.value = 'error';

            return;
        }
        profile.value = (await response.json()) as ForumCharacterProfile;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

async function reload(message: string): Promise<void> {
    await load();
    status.value = message;
}

function create(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function edit(character: ForumOwnedCharacter): void {
    editing.value = character;
    dialogOpen.value = true;
}

async function act(
    character: ForumOwnedCharacter,
    url: string | null,
    done: string
): Promise<void> {
    if (url === null) {
        return;
    }
    busy.value = character.id;
    error.value = null;
    const answer = await sendJson(url, 'POST');
    busy.value = null;
    if (answer.ok) {
        await reload(done);

        return;
    }
    error.value = answer.message ?? t('forum.characters.errors.save');
}

function askDelete(character: ForumOwnedCharacter): void {
    deleting.value = character;
    deleteError.value = null;
    confirmOpen.value = true;
}

async function remove(): Promise<void> {
    const character = deleting.value;
    if (character === null) {
        return;
    }
    busy.value = character.id;
    deleteError.value = null;
    const answer = await sendJson(character.actions.deleteUrl, 'DELETE');
    busy.value = null;
    if (answer.ok) {
        confirmOpen.value = false;
        deleting.value = null;
        await reload(t('forum.characters.profile.deleted', { name: character.name }));

        return;
    }
    deleteError.value = answer.message ?? t('forum.characters.errors.save');
}

onMounted(() => {
    void load();
});
</script>

<template>
    <section aria-labelledby="forum-characters-title" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <VenetianMask class="h-5 w-5 text-primary" aria-hidden="true" />
                <h2 id="forum-characters-title" class="text-lg font-semibold text-base-primary">
                    {{ t('forum.characters.profile.title') }}
                </h2>
            </div>
            <button
                v-if="canCreate"
                type="button"
                class="inline-flex min-h-[44px] items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-white hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                data-testid="character-create"
                @click="create"
            >
                <Plus class="h-4 w-4" aria-hidden="true" />
                {{ t('forum.characters.profile.create') }}
            </button>
        </div>

        <div v-if="state === 'loading'" class="flex items-center gap-2 text-sm text-base-muted">
            <LoadingSpinner size="sm" />
            <span>{{ t('forum.characters.profile.loading') }}</span>
        </div>

        <div v-else-if="state === 'error'" role="alert" class="space-y-2 text-sm">
            <p class="text-base-secondary">{{ t('forum.characters.profile.loadError') }}</p>
            <button
                type="button"
                class="inline-flex min-h-[44px] items-center rounded-md border border-default px-4 text-sm font-medium text-base-primary hover:bg-muted"
                @click="load"
            >
                {{ t('forum.characters.profile.retry') }}
            </button>
        </div>

        <template v-else-if="profile">
            <p class="text-sm text-base-secondary">
                {{
                    profile.createUrl
                        ? t('forum.characters.profile.intro')
                        : t('forum.characters.profile.off')
                }}
            </p>

            <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
            <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

            <p
                v-if="profile.items.length === 0"
                class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-base-muted"
            >
                {{ t('forum.characters.profile.empty') }}
            </p>

            <ul v-else class="grid gap-3 md:grid-cols-2" data-testid="character-list">
                <li
                    v-for="character in profile.items"
                    :key="character.id"
                    class="flex flex-col gap-3 rounded-lg border border-default bg-surface p-4"
                    :class="character.isRetired ? 'opacity-80' : ''"
                >
                    <div class="flex items-start gap-3">
                        <CharacterAvatar
                            :initials="character.initials"
                            :portrait-url="character.portraitUrl"
                            size="lg"
                        />
                        <div class="min-w-0 flex-1">
                            <h3 class="break-words font-semibold text-base-primary">
                                {{ character.name }}
                            </h3>
                            <p class="mt-0.5 flex flex-wrap gap-1 text-xs">
                                <span
                                    v-if="character.isRetired"
                                    class="rounded-full bg-muted px-2 py-0.5 font-medium text-base-secondary"
                                >
                                    {{
                                        character.isRetirementLocked
                                            ? t('forum.characters.profile.retiredByModeration')
                                            : t('forum.characters.retiredBadge')
                                    }}
                                </span>
                                <span
                                    class="rounded-full bg-primary-light px-2 py-0.5 font-medium text-primary"
                                >
                                    {{
                                        character.categoryName ??
                                        (character.categoryId
                                            ? t('forum.characters.profile.tiedElsewhere')
                                            : t('forum.characters.profile.anyCategory'))
                                    }}
                                </span>
                            </p>
                            <p
                                v-if="character.description"
                                class="mt-2 whitespace-pre-line break-words text-sm text-base-secondary"
                            >
                                {{ character.description }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-auto flex flex-wrap items-center gap-1 border-t border-default pt-2"
                    >
                        <button
                            v-if="profile.createUrl && !character.isRetirementLocked"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                            :aria-label="
                                t('forum.characters.profile.editLabel', { name: character.name })
                            "
                            :disabled="busy === character.id"
                            @click="edit(character)"
                        >
                            {{ t('forum.characters.profile.edit') }}
                        </button>
                        <button
                            v-if="character.actions.retireUrl"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                            :aria-label="
                                t('forum.characters.profile.retireLabel', { name: character.name })
                            "
                            :disabled="busy === character.id"
                            @click="
                                act(
                                    character,
                                    character.actions.retireUrl,
                                    t('forum.characters.profile.retired', { name: character.name })
                                )
                            "
                        >
                            {{ t('forum.characters.profile.retire') }}
                        </button>
                        <button
                            v-if="profile.createUrl && character.actions.restoreUrl"
                            type="button"
                            class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                            :aria-label="
                                t('forum.characters.profile.restoreLabel', { name: character.name })
                            "
                            :disabled="busy === character.id"
                            @click="
                                act(
                                    character,
                                    character.actions.restoreUrl,
                                    t('forum.characters.profile.restored', { name: character.name })
                                )
                            "
                        >
                            {{ t('forum.characters.profile.restore') }}
                        </button>
                        <button
                            v-if="!character.hasPosts"
                            type="button"
                            class="ml-auto inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                            :aria-label="
                                t('forum.characters.profile.deleteLabel', { name: character.name })
                            "
                            :disabled="busy === character.id"
                            @click="askDelete(character)"
                        >
                            {{ t('forum.characters.profile.delete') }}
                        </button>
                        <p v-else class="ml-auto text-xs text-base-muted">
                            {{ t('forum.characters.profile.hasPosts') }}
                        </p>
                    </div>
                </li>
            </ul>
        </template>

        <CharacterDialog
            v-if="profile"
            v-model="dialogOpen"
            :form="profile"
            :start="start"
            @saved="
                () =>
                    reload(
                        editing
                            ? t('forum.characters.profile.saved')
                            : t('forum.characters.profile.created')
                    )
            "
        />

        <ModerationDialog
            v-model="confirmOpen"
            :title="t('forum.characters.profile.deleteTitle', { name: deleting?.name ?? '' })"
            :message="t('forum.characters.profile.deleteMessage')"
            :confirm-label="t('forum.characters.profile.delete')"
            danger
            :confirmation="t('forum.characters.profile.deleteConfirmation')"
            :busy="busy !== null"
            :error="deleteError"
            @confirm="remove"
        />
    </section>
</template>
