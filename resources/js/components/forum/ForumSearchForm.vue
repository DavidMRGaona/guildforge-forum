<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Search } from '@lucide/vue';
import { FormSelect, type SelectOption } from '@/components/form';
import type { ForumSearchCategoryOption } from '../../types/forum';

/**
 * The forum's search box: the text and, optionally, a category to look in. It works as a plain GET
 * form (so it needs no script to submit) and as an Inertia visit when scripts are there.
 */
const props = defineProps<{
    action: string;
    query?: string;
    /** Slug of the category chosen, if any */
    category?: string | null;
    /** The categories the reader may look in; none hides the filter */
    categories?: ForumSearchCategoryOption[];
    /** Distinguishes the ids when two forms share a page */
    idPrefix?: string;
}>();

const { t } = useI18n();

const text = ref(props.query ?? '');
const chosen = ref(props.category ?? '');

watch(
    () => props.query,
    (value) => {
        text.value = value ?? '';
    }
);
watch(
    () => props.category,
    (value) => {
        chosen.value = value ?? '';
    }
);

const prefix = props.idPrefix ?? 'forum-search';

// Subcategories under their category, as a tree (forum#108)
const categoryOptions = computed<SelectOption[]>(() =>
    (props.categories ?? []).map((option) => ({
        value: option.slug,
        label: option.name,
        level: option.depth > 0 ? 1 : 0,
    }))
);

function choose(value: string | number | null): void {
    chosen.value = value === null ? '' : String(value);
}

function submit(): void {
    const query: Record<string, string> = {};
    const value = text.value.trim();
    if (value !== '') {
        query.q = value;
    }
    if (chosen.value !== '') {
        query.categoria = chosen.value;
    }

    router.get(props.action, query);
}
</script>

<template>
    <form
        role="search"
        method="get"
        :action="action"
        :aria-label="t('forum.search.label')"
        class="flex flex-col gap-2 sm:flex-row sm:items-end"
        @submit.prevent="submit"
    >
        <div class="min-w-0 flex-1">
            <label :for="`${prefix}-q`" class="mb-1 block text-sm font-medium text-base-secondary">
                {{ t('forum.search.label') }}
            </label>
            <input
                :id="`${prefix}-q`"
                v-model="text"
                type="search"
                name="q"
                maxlength="200"
                autocomplete="off"
                enterkeyhint="search"
                :placeholder="t('forum.search.placeholder')"
                class="min-h-[44px] w-full rounded-md border border-default bg-surface px-3 text-base text-base-primary placeholder:text-base-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            />
        </div>

        <div v-if="categories && categories.length > 0" class="sm:w-56">
            <label
                :for="`${prefix}-category`"
                class="mb-1 block text-sm font-medium text-base-secondary"
            >
                {{ t('forum.search.category') }}
            </label>
            <FormSelect
                :id="`${prefix}-category`"
                :model-value="chosen"
                :options="categoryOptions"
                :placeholder="t('forum.search.anyCategory')"
                @update:model-value="choose"
            />
            <!-- What the plain GET form sends; nothing when no category is chosen -->
            <input type="hidden" name="categoria" :value="chosen" :disabled="chosen === ''" />
        </div>

        <button
            type="submit"
            class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
        >
            <Search class="h-4 w-4" aria-hidden="true" />
            {{ t('forum.search.submit') }}
        </button>
    </form>
</template>
