<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { UserPlus, X } from '@lucide/vue';
import { sendJson } from '../../forum/api';
import type { ForumPerson } from '../../types/forum';

/**
 * Finding people by name for a private conversation: a search box whose results are buttons,
 * and the people chosen as chips that can be taken out. The server decides who may be found
 * (never the person, whoever blocked them or whoever may not take part), so this only shows what
 * it answers. With `max` 1 and `single`, choosing one emits `pick` instead of keeping a list.
 */
const props = withDefaults(
    defineProps<{
        /** Accessible name of the search box */
        label: string;
        peopleUrl: string;
        modelValue?: ForumPerson[];
        /** How many may be chosen */
        max: number;
        /** Choose one and hand it over (adding someone to a conversation) */
        single?: boolean;
        disabled?: boolean;
        /** The field's error, from the page */
        error?: string | null;
        /** Takes the focus when it appears (opened by a button) */
        focusOnOpen?: boolean;
    }>(),
    { modelValue: () => [], single: false, disabled: false, error: null, focusOnOpen: false }
);

const emit = defineEmits<{
    'update:modelValue': [people: ForumPerson[]];
    pick: [person: ForumPerson];
}>();

const MIN_LENGTH = 2;
const DELAY_MS = 250;

const { t } = useI18n();
const uid = Math.random().toString(36).slice(2, 10);
const query = ref('');
const results = ref<ForumPerson[]>([]);
const input = ref<HTMLInputElement | null>(null);
const searching = ref(false);
const searched = ref(false);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let asked = 0;

const full = computed(() => !props.single && props.modelValue.length >= props.max);
const chosenIds = computed(() => new Set(props.modelValue.map((person) => person.id)));
const shown = computed(() => results.value.filter((person) => !chosenIds.value.has(person.id)));

watch(query, (value) => {
    if (timer !== null) {
        clearTimeout(timer);
    }
    failed.value = false;
    if (value.trim().length < MIN_LENGTH) {
        results.value = [];
        searched.value = false;

        return;
    }
    timer = setTimeout(() => void search(value.trim()), DELAY_MS);
});

onMounted(() => {
    if (props.focusOnOpen) {
        input.value?.focus();
    }
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});

async function search(text: string): Promise<void> {
    const ticket = ++asked;
    searching.value = true;
    const separator = props.peopleUrl.includes('?') ? '&' : '?';
    const answer = await sendJson<{ people: ForumPerson[] }>(
        `${props.peopleUrl}${separator}q=${encodeURIComponent(text)}`,
        'GET'
    );
    // An older search answering late never replaces a newer one
    if (ticket !== asked) {
        return;
    }
    searching.value = false;
    searched.value = true;
    failed.value = !answer.ok;
    results.value = answer.ok && answer.data ? answer.data.people : [];
}

function choose(person: ForumPerson): void {
    if (props.single) {
        emit('pick', person);
    } else if (!full.value) {
        emit('update:modelValue', [...props.modelValue, person]);
    }
    query.value = '';
    results.value = [];
    searched.value = false;
}

function remove(person: ForumPerson): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((chosen) => chosen.id !== person.id)
    );
}
</script>

<template>
    <div class="space-y-2" data-testid="people-picker">
        <ul
            v-if="!single && modelValue.length > 0"
            class="flex flex-wrap gap-2"
            :aria-label="t('forum.conversations.people.chosen')"
        >
            <li
                v-for="person in modelValue"
                :key="person.id"
                class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-light py-1 pl-3 pr-1 text-sm text-primary"
            >
                <span class="truncate">{{ person.name }}</span>
                <button
                    type="button"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full hover:bg-surface focus:outline-none focus:ring-2 focus:ring-primary-500"
                    :aria-label="t('forum.conversations.people.remove', { name: person.name })"
                    :disabled="disabled"
                    @click="remove(person)"
                >
                    <X class="h-4 w-4" aria-hidden="true" />
                </button>
            </li>
        </ul>

        <label :for="`people-search-${uid}`" class="sr-only">{{ label }}</label>
        <input
            :id="`people-search-${uid}`"
            ref="input"
            v-model="query"
            type="search"
            autocomplete="off"
            :placeholder="
                full
                    ? t('forum.conversations.people.full', { max })
                    : t('forum.conversations.people.placeholder')
            "
            :disabled="disabled || full"
            class="block min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-60"
            :aria-describedby="`people-status-${uid}`"
            :aria-invalid="error ? 'true' : 'false'"
            data-testid="people-search"
        />

        <p :id="`people-status-${uid}`" class="text-sm text-base-muted" aria-live="polite">
            <template v-if="searching">{{ t('forum.conversations.people.searching') }}</template>
            <template v-else-if="failed">{{ t('forum.conversations.people.failed') }}</template>
            <template v-else-if="searched && shown.length === 0">{{
                t('forum.conversations.people.none')
            }}</template>
            <template v-else-if="query.trim().length > 0 && query.trim().length < 2">{{
                t('forum.conversations.people.tooShort')
            }}</template>
        </p>

        <ul
            v-if="shown.length > 0"
            class="divide-y divide-default overflow-hidden rounded-md border border-default"
            :aria-label="t('forum.conversations.people.results')"
        >
            <li v-for="person in shown" :key="person.id">
                <button
                    type="button"
                    class="flex min-h-[44px] w-full items-center gap-2 px-3 py-2 text-left text-sm text-base-primary hover:bg-muted focus:bg-muted focus:outline-none"
                    :disabled="disabled"
                    :data-testid="`people-result-${person.id}`"
                    @click="choose(person)"
                >
                    <UserPlus class="h-4 w-4 shrink-0 text-base-muted" aria-hidden="true" />
                    <span class="min-w-0 truncate">{{ person.name }}</span>
                    <span class="sr-only">{{ t('forum.conversations.people.add') }}</span>
                </button>
            </li>
        </ul>

        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>
