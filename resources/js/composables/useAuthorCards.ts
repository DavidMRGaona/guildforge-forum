import { inject, provide, type InjectionKey } from 'vue';
import { sendJson } from '../forum/api';
import type { ForumAuthorCard } from '../types/forum';

/**
 * The author cards a page asked for (forum#134), by the person's account: asked when one opens and
 * kept while the page is open, so every post of the same person opens at once. A failed request is
 * not kept, so opening the card again asks again.
 */
export interface AuthorCardCache {
    /** The card of the person, from the post's card address; null when it could not be had */
    load: (key: string, url: string) => Promise<ForumAuthorCard | null>;
    /** What is kept for the person changed (they were ignored): the card as it is now */
    update: (key: string, card: ForumAuthorCard) => void;
    /** Something the card shows changed (a title, a suspension): ask again next time */
    forget: (key: string) => void;
    /** The page now shows another discussion: its counts are not this one's */
    clear: () => void;
}

export const AUTHOR_CARDS: InjectionKey<AuthorCardCache> = Symbol('forum.authorCards');

export function createAuthorCardCache(): AuthorCardCache {
    const cards = new Map<string, Promise<ForumAuthorCard | null>>();

    return {
        load(key: string, url: string): Promise<ForumAuthorCard | null> {
            const kept = cards.get(key);
            if (kept) {
                return kept;
            }
            const asked = sendJson<ForumAuthorCard>(url, 'GET').then((answer) => {
                if (!answer.ok || answer.data === null) {
                    cards.delete(key);

                    return null;
                }

                return answer.data;
            });
            cards.set(key, asked);

            return asked;
        },
        update(key: string, card: ForumAuthorCard): void {
            cards.set(key, Promise.resolve(card));
        },
        forget(key: string): void {
            cards.delete(key);
        },
        clear(): void {
            cards.clear();
        },
    };
}

/** The discussion page keeps one cache for all its posts */
export function provideAuthorCards(): AuthorCardCache {
    const cache = createAuthorCardCache();
    provide(AUTHOR_CARDS, cache);

    return cache;
}

/** The page's cache, or one of the card's own where no page keeps one */
export function useAuthorCards(): AuthorCardCache {
    return inject(AUTHOR_CARDS, null) ?? createAuthorCardCache();
}
