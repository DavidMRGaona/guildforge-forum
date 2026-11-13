<script setup lang="ts">
import { Check } from '@lucide/vue';

/**
 * The box (or circle) drawn for a checkbox or radio whose native input stays in the page,
 * hidden with `peer sr-only`, because it needs what the core's FormCheckbox does not take
 * (`aria-describedby`, `required`, a radio in a fieldset). It goes right after that input:
 * the input keeps the focus, the label and the behaviour; this only shows its state, as
 * FormCheckbox draws it. Its place in the line (a top margin) is the caller's.
 */
withDefaults(
    defineProps<{
        checked: boolean;
        type?: 'checkbox' | 'radio';
    }>(),
    { type: 'checkbox' }
);
</script>

<template>
    <span
        aria-hidden="true"
        class="flex h-5 w-5 shrink-0 items-center justify-center border-2 transition-all duration-150 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500 peer-focus-visible:ring-offset-2 peer-disabled:opacity-50 dark:peer-focus-visible:ring-offset-neutral-900"
        :class="[
            type === 'radio' ? 'rounded-full' : 'rounded-md',
            checked && type === 'checkbox'
                ? 'border-primary-600 bg-primary-600 dark:border-primary-500 dark:bg-primary-500'
                : checked
                  ? 'border-primary-600 bg-surface dark:border-primary-500'
                  : 'border-neutral-300 bg-surface dark:border-neutral-600',
        ]"
    >
        <span
            v-if="type === 'radio'"
            data-mark
            class="h-2.5 w-2.5 rounded-full bg-primary-600 transition-all duration-150 dark:bg-primary-500"
            :class="checked ? 'scale-100 opacity-100' : 'scale-0 opacity-0'"
        />
        <Check
            v-else
            data-mark
            class="h-3 w-3 text-white transition-all duration-150"
            :class="checked ? 'scale-100 opacity-100' : 'scale-0 opacity-0'"
            :stroke-width="3"
        />
    </span>
</template>
