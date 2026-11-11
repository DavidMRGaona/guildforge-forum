import type { JSONContent } from '@tiptap/core';

/** Drafts older than this are dropped instead of restored */
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

const PREFIX = 'guildforge:forum:draft:';

interface StoredDraft {
    savedAt: number;
    document: JSONContent;
}

/**
 * A post being written, kept in this browser under a key per discussion (or per category for
 * a new one) until it is published or emptied. Storage may be missing or full (private
 * windows): then nothing is kept and writing goes on.
 */
export interface PostDraft {
    load: () => JSONContent | null;
    save: (document: JSONContent) => void;
    clear: () => void;
}

function storage(): Storage | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
}

export function postDraft(key: string | null, now: () => number = Date.now): PostDraft {
    const storageKey = key === null || key === '' ? null : PREFIX + key;

    return {
        load(): JSONContent | null {
            if (storageKey === null) {
                return null;
            }

            try {
                const raw = storage()?.getItem(storageKey);
                const draft =
                    raw === null || raw === undefined
                        ? null
                        : (JSON.parse(raw) as Partial<StoredDraft>);
                if (
                    draft === null ||
                    typeof draft.savedAt !== 'number' ||
                    draft.document?.type !== 'doc'
                ) {
                    return null;
                }
                if (now() - draft.savedAt > MAX_AGE_MS) {
                    storage()?.removeItem(storageKey);

                    return null;
                }

                return draft.document;
            } catch {
                return null;
            }
        },

        save(document: JSONContent): void {
            if (storageKey === null) {
                return;
            }

            try {
                storage()?.setItem(
                    storageKey,
                    JSON.stringify({ savedAt: now(), document } satisfies StoredDraft)
                );
            } catch {
                // Full or blocked storage: the draft is simply not kept
            }
        },

        clear(): void {
            if (storageKey === null) {
                return;
            }

            try {
                storage()?.removeItem(storageKey);
            } catch {
                // Nothing to clear
            }
        },
    };
}
