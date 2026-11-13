<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TypingPeople } from '../../composables/useTyping';

/**
 * «Ana está escribiendo…» under a discussion. Not a live region: it comes and goes every few
 * seconds and would only interrupt a screen reader; the posts themselves are announced.
 */
const props = defineProps<{ people: TypingPeople }>();

const { t } = useI18n();

const text = computed(() => {
    const { known, unknown } = props.people;
    const total = known.length + unknown;
    const [first, second] = known;

    if (total === 0) {
        return '';
    }
    if (total === 1) {
        return first === undefined
            ? t('forum.live.typingSomeone')
            : t('forum.live.typingOne', { name: first });
    }
    if (total === 2 && first !== undefined && second !== undefined) {
        return t('forum.live.typingTwo', { first, second });
    }
    if (total === 2 && first !== undefined) {
        return t('forum.live.typingWithSomeone', { name: first });
    }

    return t('forum.live.typingMany');
});
</script>

<template>
    <p class="min-h-5 text-sm text-base-secondary italic" data-testid="typing">{{ text }}</p>
</template>
