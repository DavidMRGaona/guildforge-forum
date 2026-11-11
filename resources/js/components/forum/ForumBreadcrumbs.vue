<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronRight } from '@lucide/vue';

/** Where the page sits in the forum: Foro › category › subcategory. */
defineProps<{
    items: { name: string; url: string }[];
}>();

const { t } = useI18n();
</script>

<template>
    <nav :aria-label="t('forum.breadcrumbs')" class="text-sm">
        <ol class="flex flex-wrap items-center gap-1 text-base-secondary">
            <li v-for="(item, index) in items" :key="item.url" class="flex items-center gap-1">
                <ChevronRight
                    v-if="index > 0"
                    class="h-4 w-4 shrink-0 text-base-muted"
                    aria-hidden="true"
                />
                <Link
                    :href="item.url"
                    class="inline-flex min-h-[44px] items-center rounded-sm hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >
                    {{ item.name }}
                </Link>
            </li>
        </ol>
    </nav>
</template>
