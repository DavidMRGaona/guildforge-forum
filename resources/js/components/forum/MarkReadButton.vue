<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CheckCheck } from '@lucide/vue';
import BaseButton from '@/components/ui/BaseButton.vue';

/** «Marcar todo como leído» for a category or the whole forum. */
const props = defineProps<{
    url: string;
    label: string;
}>();

const { t } = useI18n();
const sending = ref(false);

function markRead(): void {
    router.post(
        props.url,
        {},
        {
            preserveScroll: true,
            onStart: () => (sending.value = true),
            onFinish: () => (sending.value = false),
        }
    );
}
</script>

<template>
    <BaseButton
        variant="secondary"
        size="sm"
        :loading="sending"
        :aria-label="label"
        @click="markRead"
    >
        <CheckCheck class="h-4 w-4" aria-hidden="true" />
        <span>{{ t('forum.reads.markRead') }}</span>
    </BaseButton>
</template>
