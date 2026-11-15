<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useAuth } from '@/composables/useAuth';
import { useRoutes } from '@/composables/useRoutes';

/**
 * What the forum shows in place of an editor while the person has no public name: a short
 * prompt with a field to set it right there, saved with the core's profile update. Once it is
 * saved the page's shared props carry it and the slot (the editor) takes the prompt's place.
 * Only a help: the server refuses to write without a public name whatever this shows.
 */
/** The name was saved and the slot is about to take the prompt's place: the page may move focus */
const emit = defineEmits<{ named: [] }>();

const { t } = useI18n();
const { user } = useAuth();
const routes = useRoutes();

const hasName = computed(() => (user.value?.displayName ?? '').trim() !== '');
const name = ref('');
const saving = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

// The shared props bring the saved name: only then does the slot show, and only then is it «named»
watch(hasName, (now) => {
    if (now && saved.value) {
        saved.value = false;
        emit('named');
    }
});

function save(): void {
    const account = user.value;
    const publicName = name.value.trim();
    if (account === null) {
        return;
    }
    if (publicName === '') {
        error.value = t('forum.publicName.required');

        return;
    }

    error.value = null;
    // The profile update takes the whole identity, and sending the account's email again would
    // cancel a pending change of address: the address in force for verification (the pending one
    // when there is one) goes, which the core keeps as it is
    router.post(
        routes.profile,
        {
            name: account.name,
            email: account.verificationEmail ?? account.email,
            display_name: publicName,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (saved.value = true),
            // The core's validation of the field, shown at the field
            onError: (errors) => {
                error.value = errors.display_name ?? errors.name ?? t('forum.publicName.saveError');
            },
            // Too many attempts, or any other answer that is not a validation error
            onHttpException: (response) => {
                error.value =
                    response.status === 429
                        ? t('forum.publicName.tooMany')
                        : t('forum.publicName.saveError');
            },
        }
    );
}
</script>

<template>
    <slot v-if="hasName" />

    <form
        v-else
        class="space-y-3 rounded-lg border border-default bg-surface p-4"
        data-testid="public-name-prompt"
        novalidate
        @submit.prevent="save"
    >
        <p id="forum-public-name-help" class="text-sm text-base-secondary">
            {{ t('forum.publicName.prompt') }}
        </p>
        <div>
            <label for="forum-public-name" class="block text-sm font-medium text-base-primary">{{
                t('forum.publicName.label')
            }}</label>
            <input
                id="forum-public-name"
                v-model="name"
                type="text"
                maxlength="255"
                autocomplete="nickname"
                class="mt-1 block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500"
                :aria-invalid="error ? 'true' : 'false'"
                :aria-describedby="
                    error
                        ? 'forum-public-name-help forum-public-name-error'
                        : 'forum-public-name-help'
                "
            />
            <p
                v-if="error"
                id="forum-public-name-error"
                role="alert"
                class="mt-1 text-sm text-red-600"
            >
                {{ error }}
            </p>
        </div>
        <BaseButton type="submit" variant="primary" :loading="saving">
            {{ t('forum.publicName.save') }}
        </BaseButton>
    </form>
</template>
