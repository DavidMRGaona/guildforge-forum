<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronDown, Plus, Users } from '@lucide/vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import { useVerificationGate } from '@/composables/useVerificationGate';
import CharacterAvatar from './CharacterAvatar.vue';
import CharacterDialog, { type CharacterDialogStart } from './CharacterDialog.vue';
import ModerationDialog from '../moderation/ModerationDialog.vue';
import { sendJson } from '../../forum/api';
import type { ForumSharedCharacter, ForumSharedCharacters } from '../../types/forum';

/**
 * The PNJ of a campaign's space, on the space's page, for whoever directs the campaign (and
 * whoever moderates the space): they belong to the campaign, not to an account, so any director
 * creates, changes and retires them. One with messages is retired instead of deleted, and its
 * messages keep showing it. Opens on request and loads then.
 */
const props = defineProps<{
    /** Where the list is read */
    url: string;
}>();

const { t } = useI18n();
const gate = useVerificationGate();
const uid = Math.random().toString(36).slice(2, 10);

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

const open = ref(false);
const state = ref<LoadState>('idle');
const data = ref<ForumSharedCharacters | null>(null);
const status = ref('');
const error = ref<string | null>(null);
const busy = ref<string | null>(null);
const dialogOpen = ref(false);
const editing = ref<ForumSharedCharacter | null>(null);
const deleting = ref<ForumSharedCharacter | null>(null);
const confirmOpen = ref(false);
const deleteError = ref<string | null>(null);

const canCreate = computed(
    () =>
        data.value !== null &&
        data.value.createUrl !== null &&
        data.value.items.length < data.value.max
);
const isFull = computed(
    () =>
        data.value !== null &&
        data.value.createUrl !== null &&
        data.value.items.length >= data.value.max
);
const start = computed<CharacterDialogStart | null>(() =>
    editing.value === null
        ? null
        : {
              name: editing.value.name,
              description: editing.value.description,
              categoryId: null,
              initials: editing.value.initials,
              portraitUrl: editing.value.portraitUrl,
              updateUrl: editing.value.actions.updateUrl,
          }
);

async function load(): Promise<void> {
    state.value = 'loading';
    try {
        const response = await fetch(props.url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok || gate.isVerificationRequired(response)) {
            state.value = 'error';

            return;
        }
        data.value = (await response.json()) as ForumSharedCharacters;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

function toggle(): void {
    open.value = !open.value;
    if (open.value && state.value === 'idle') {
        void load();
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

function edit(character: ForumSharedCharacter): void {
    editing.value = character;
    dialogOpen.value = true;
}

async function act(
    character: ForumSharedCharacter,
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

function askDelete(character: ForumSharedCharacter): void {
    deleting.value = character;
    deleteError.value = null;
    confirmOpen.value = true;
}

async function remove(): Promise<void> {
    const character = deleting.value;
    const url = character?.actions.deleteUrl ?? null;
    if (character === null || url === null) {
        return;
    }
    busy.value = character.id;
    deleteError.value = null;
    const answer = await sendJson(url, 'DELETE');
    busy.value = null;
    if (answer.ok) {
        confirmOpen.value = false;
        deleting.value = null;
        await reload(t('forum.characters.shared.deleted', { name: character.name }));

        return;
    }
    deleteError.value = answer.message ?? t('forum.characters.errors.save');
}
</script>

<template>
    <section
        class="rounded-lg border border-default bg-surface"
        :aria-labelledby="`shared-characters-${uid}`"
        data-testid="shared-characters"
    >
        <h2 :id="`shared-characters-${uid}`" class="m-0">
            <button
                type="button"
                class="flex min-h-[44px] w-full items-center gap-2 rounded-lg px-4 py-3 text-left text-base font-semibold text-base-primary hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :aria-expanded="open"
                :aria-controls="`shared-characters-panel-${uid}`"
                data-testid="shared-characters-toggle"
                @click="toggle"
            >
                <Users class="h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
                <span class="min-w-0 flex-1">{{ t('forum.characters.shared.title') }}</span>
                <ChevronDown
                    class="h-4 w-4 shrink-0 text-base-muted transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </button>
        </h2>

        <div
            v-show="open"
            :id="`shared-characters-panel-${uid}`"
            class="space-y-4 border-t border-default px-4 py-4"
        >
            <p class="text-sm text-base-secondary">{{ t('forum.characters.shared.intro') }}</p>

            <div v-if="state === 'loading'" class="flex items-center gap-2 text-sm text-base-muted">
                <LoadingSpinner size="sm" />
                <span>{{ t('forum.characters.shared.loading') }}</span>
            </div>

            <div v-else-if="state === 'error'" role="alert" class="space-y-2 text-sm">
                <p class="text-base-secondary">{{ t('forum.characters.shared.loadError') }}</p>
                <button
                    type="button"
                    class="inline-flex min-h-[44px] items-center rounded-md border border-default px-4 text-sm font-medium text-base-primary hover:bg-muted"
                    @click="load"
                >
                    {{ t('forum.characters.shared.retry') }}
                </button>
            </div>

            <template v-else-if="data">
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        v-if="canCreate"
                        type="button"
                        class="inline-flex min-h-[44px] items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-white hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                        data-testid="shared-character-create"
                        @click="create"
                    >
                        <Plus class="h-4 w-4" aria-hidden="true" />
                        {{ t('forum.characters.shared.create') }}
                    </button>
                    <p v-else-if="isFull" class="text-sm text-base-muted">
                        {{ t('forum.characters.shared.full', { max: data.max }) }}
                    </p>
                    <p v-else-if="data.createUrl === null" class="text-sm text-base-muted">
                        {{ t('forum.characters.shared.off') }}
                    </p>
                </div>

                <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
                <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

                <p
                    v-if="data.items.length === 0"
                    class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-base-muted"
                >
                    {{ t('forum.characters.shared.empty') }}
                </p>

                <ul v-else class="grid gap-3 md:grid-cols-2" data-testid="shared-character-list">
                    <li
                        v-for="character in data.items"
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
                                <p v-if="character.isRetired" class="mt-0.5 text-xs">
                                    <span
                                        class="rounded-full bg-muted px-2 py-0.5 font-medium text-base-secondary"
                                    >
                                        {{
                                            character.isRetirementLocked
                                                ? t('forum.characters.shared.retiredByModeration')
                                                : t('forum.characters.retiredBadge')
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
                                type="button"
                                class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                                :aria-label="
                                    t('forum.characters.profile.editLabel', {
                                        name: character.name,
                                    })
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
                                    t('forum.characters.profile.retireLabel', {
                                        name: character.name,
                                    })
                                "
                                :disabled="busy === character.id"
                                @click="
                                    act(
                                        character,
                                        character.actions.retireUrl,
                                        t('forum.characters.shared.retired', {
                                            name: character.name,
                                        })
                                    )
                                "
                            >
                                {{ t('forum.characters.profile.retire') }}
                            </button>
                            <button
                                v-if="character.actions.restoreUrl"
                                type="button"
                                class="inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-base-secondary hover:bg-muted hover:text-base-primary"
                                :aria-label="
                                    t('forum.characters.profile.restoreLabel', {
                                        name: character.name,
                                    })
                                "
                                :disabled="busy === character.id"
                                @click="
                                    act(
                                        character,
                                        character.actions.restoreUrl,
                                        t('forum.characters.shared.restored', {
                                            name: character.name,
                                        })
                                    )
                                "
                            >
                                {{ t('forum.characters.profile.restore') }}
                            </button>
                            <button
                                v-if="character.actions.deleteUrl"
                                type="button"
                                class="ml-auto inline-flex min-h-[44px] items-center rounded-md px-3 text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                :aria-label="
                                    t('forum.characters.profile.deleteLabel', {
                                        name: character.name,
                                    })
                                "
                                :disabled="busy === character.id"
                                @click="askDelete(character)"
                            >
                                {{ t('forum.characters.profile.delete') }}
                            </button>
                            <p v-else class="ml-auto text-xs text-base-muted">
                                {{ t('forum.characters.shared.hasPosts') }}
                            </p>
                        </div>
                    </li>
                </ul>
            </template>
        </div>

        <CharacterDialog
            v-if="data"
            v-model="dialogOpen"
            :form="data"
            :start="start"
            :chooses-category="false"
            shared
            @saved="
                () =>
                    reload(
                        editing
                            ? t('forum.characters.shared.saved')
                            : t('forum.characters.shared.created')
                    )
            "
        />

        <ModerationDialog
            v-model="confirmOpen"
            :title="t('forum.characters.shared.deleteTitle', { name: deleting?.name ?? '' })"
            :message="t('forum.characters.shared.deleteMessage')"
            :confirm-label="t('forum.characters.profile.delete')"
            danger
            :confirmation="t('forum.characters.profile.deleteConfirmation')"
            :busy="busy !== null"
            :error="deleteError"
            @confirm="remove"
        />
    </section>
</template>
