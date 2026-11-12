import { inject, provide, type InjectionKey } from 'vue';
import type { ForumMergedInto } from '../types/forum';

type MergedListener = (target: ForumMergedInto) => void;

/** Who hears that the page's discussion was merged into another (the discussion page) */
export const MERGED_DISCUSSION: InjectionKey<MergedListener> = Symbol('forum.mergedDiscussion');

/**
 * The discussion page hears it from anything on it that asked the server (following, mentions,
 * moderation), so it shows the way to where the discussion went.
 */
export function provideMergedDiscussion(listener: MergedListener): void {
    provide(MERGED_DISCUSSION, listener);
}

/**
 * Tells the page that an answer said its discussion was merged (410 with where it went), and
 * whether it did. Where nobody listens (a record's conversation) it only answers.
 */
export function useMergedDiscussion(): (target: ForumMergedInto | null | undefined) => boolean {
    const listener = inject(MERGED_DISCUSSION, null);

    return (target): boolean => {
        if (!target) {
            return false;
        }
        listener?.(target);

        return true;
    };
}
