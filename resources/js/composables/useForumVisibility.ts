import { computed, type ComputedRef } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Whether the site draws anything of the forum for this person: false while the forum is a
 * preview they may not see (the shared prop `forumVisible`). The server decides and its pages
 * answer 404 anyway; this only keeps slots from asking for what they would not get.
 */
export function useForumVisibility(): ComputedRef<boolean> {
    const page = usePage();

    return computed(() => page.props.forumVisible !== false);
}
