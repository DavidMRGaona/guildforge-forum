import { computed, onScopeDispose, ref, type ComputedRef } from 'vue';
import type { RealtimePayload } from '@/composables/useRealtimeChannel';
import type { ChannelFeed } from '../live/channels';

/** The client event readers of a discussion whisper while they write a reply */
export const TYPING_EVENT = 'typing';

/** The shortest time between two «typing» whispers of one person */
export const TYPING_SEND_EVERY_MS = 3_000;

/** How long someone shows as typing after their last whisper */
export const TYPING_SHOW_FOR_MS = 6_000;

/** The most people the page keeps track of at once (whispers come from anyone on the channel) */
export const TYPING_MAX_PEOPLE = 10;

/** An account id as it travels: whatever else arrives is ignored */
const ACCOUNT_ID = /^[A-Za-z0-9_-]{1,64}$/;

export interface TypingOptions {
    channel: Pick<ChannelFeed, 'whisper' | 'onWhisper'>;
    /** The reader's own account; null without a session (nothing is sent) */
    selfId: () => string | null;
    /** Names the page already has, by account id (authors of its posts, mentioned people) */
    names: () => Record<string, string>;
    now?: () => number;
}

export interface TypingPeople {
    /** Names of the people typing that the page knows, in the order they started */
    known: string[];
    /** People typing whose name the page does not have */
    unknown: number;
}

export interface Typing {
    people: ComputedRef<TypingPeople>;
    /** The reader typed: tells the others, at most once every few seconds */
    notify: () => void;
    /** The person's post arrived: they are no longer typing */
    clear: (accountId: string | null) => void;
}

/**
 * «Typing…» in a discussion, through the channel's client events (whispers). A whisper never goes
 * through the application, so anyone on the channel may send anything: only an account id is
 * sent and read, a name is only ever taken from what the page already shows, and nothing is
 * stored or decided by it. It is an ephemeral hint and nothing more.
 */
export function useTyping(options: TypingOptions): Typing {
    const now = options.now ?? Date.now;
    // Account id → when it stops showing
    const typing = ref(new Map<string, number>());
    let lastSent = Number.NEGATIVE_INFINITY;
    let timer: ReturnType<typeof setTimeout> | null = null;

    function self(): string | null {
        return options.selfId()?.toLowerCase() ?? null;
    }

    function prune(): void {
        timer = null;
        const at = now();
        const next = new Map([...typing.value].filter(([, until]) => until > at));
        if (next.size !== typing.value.size) {
            typing.value = next;
        }
        schedule();
    }

    function schedule(): void {
        if (timer !== null || typing.value.size === 0) {
            return;
        }

        const soonest = Math.min(...typing.value.values());
        timer = setTimeout(prune, Math.max(0, soonest - now()));
    }

    function heard(payload: RealtimePayload): void {
        const id = payload.user;
        if (typeof id !== 'string' || !ACCOUNT_ID.test(id)) {
            return;
        }

        const accountId = id.toLowerCase();
        if (accountId === self()) {
            return;
        }

        const next = new Map(typing.value);
        next.delete(accountId);
        next.set(accountId, now() + TYPING_SHOW_FOR_MS);
        // The oldest go first when too many whisper at once
        while (next.size > TYPING_MAX_PEOPLE) {
            const oldest = next.keys().next().value;
            if (oldest === undefined) {
                break;
            }
            next.delete(oldest);
        }
        typing.value = next;
        schedule();
    }

    const stopListening = options.channel.onWhisper(TYPING_EVENT, heard);
    onScopeDispose(() => {
        stopListening();
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    });

    const people = computed<TypingPeople>(() => {
        const names = options.names();
        const known: string[] = [];
        let unknown = 0;
        for (const accountId of typing.value.keys()) {
            const name = names[accountId];
            if (name !== undefined && name !== '') {
                known.push(name);
            } else {
                unknown++;
            }
        }

        return { known, unknown };
    });

    return {
        people,
        notify: (): void => {
            const accountId = self();
            if (accountId === null || now() - lastSent < TYPING_SEND_EVERY_MS) {
                return;
            }

            if (options.channel.whisper(TYPING_EVENT, { user: accountId })) {
                lastSent = now();
            }
        },
        clear: (accountId): void => {
            if (accountId === null || !typing.value.has(accountId.toLowerCase())) {
                return;
            }

            const next = new Map(typing.value);
            next.delete(accountId.toLowerCase());
            typing.value = next;
        },
    };
}
