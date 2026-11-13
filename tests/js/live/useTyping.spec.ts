import { effectScope } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { RealtimeListener } from '@/composables/useRealtimeChannel';
import {
    TYPING_MAX_PEOPLE,
    useTyping,
    type Typing,
} from '../../../resources/js/composables/useTyping';

interface TestChannel {
    sent: Array<Record<string, unknown>>;
    whisper: (event: string, data: Record<string, unknown>) => boolean;
    onWhisper: (event: string, listener: RealtimeListener) => () => void;
    hear: (data: Record<string, unknown>) => void;
    listening: () => number;
}

/** A channel whose whispers the test sends and records */
function aChannel(live = true): TestChannel {
    const listeners: RealtimeListener[] = [];

    return {
        sent: [] as Array<Record<string, unknown>>,
        whisper(event: string, data: Record<string, unknown>): boolean {
            expect(event).toBe('typing');
            if (live) {
                this.sent.push(data);
            }

            return live;
        },
        onWhisper(event: string, listener: RealtimeListener): () => void {
            expect(event).toBe('typing');
            listeners.push(listener);

            return (): void => {
                listeners.splice(listeners.indexOf(listener), 1);
            };
        },
        hear: (data: Record<string, unknown>): void =>
            listeners.forEach((listener) => listener(Object.freeze({ ...data }))),
        listening: (): number => listeners.length,
    };
}

const NAMES: Record<string, string> = { 'user-ana': 'Ana', 'user-luis': 'Luis' };

let scope = effectScope();

function typingWith(channel: TestChannel, selfId: string | null = 'user-me'): Typing {
    return scope.run(() =>
        useTyping({ channel, selfId: () => selfId, names: () => NAMES })
    ) as Typing;
}

beforeEach(() => {
    vi.useFakeTimers();
    scope = effectScope();
});

afterEach(() => {
    scope.stop();
    vi.useRealTimers();
});

describe('useTyping', () => {
    it('tells the others with the reader own id only, at most once every three seconds', () => {
        const channel = aChannel();
        const typing = typingWith(channel);

        typing.notify();
        typing.notify();
        vi.advanceTimersByTime(2_999);
        typing.notify();
        expect(channel.sent).toEqual([{ user: 'user-me' }]);

        vi.advanceTimersByTime(1);
        typing.notify();
        expect(channel.sent).toEqual([{ user: 'user-me' }, { user: 'user-me' }]);
    });

    it('sends nothing without a session, and tries again at once while the channel is not live', () => {
        const guest = aChannel();
        typingWith(guest, null).notify();
        expect(guest.sent).toEqual([]);

        const offline = aChannel(false);
        const typing = typingWith(offline);
        typing.notify();
        typing.notify();
        // Not sent, so nothing to wait for
        expect(offline.sent).toEqual([]);
    });

    it('names whoever the page knows and counts the others', () => {
        const channel = aChannel();
        const typing = typingWith(channel);

        channel.hear({ user: 'user-ana' });
        channel.hear({ user: 'user-stranger' });

        expect(typing.people.value).toEqual({ known: ['Ana'], unknown: 1 });
    });

    it('ignores what is not an account id, and the reader own whispers', () => {
        const channel = aChannel();
        const typing = typingWith(channel);

        channel.hear({ user: '<img src=x onerror=alert(1)>' });
        channel.hear({ user: 42 });
        channel.hear({ name: 'Ana' });
        channel.hear({ user: 'user-me' });
        channel.hear({ user: 'USER-ME' });

        expect(typing.people.value).toEqual({ known: [], unknown: 0 });
    });

    it('stops showing someone six seconds after their last whisper', () => {
        const channel = aChannel();
        const typing = typingWith(channel);

        channel.hear({ user: 'user-ana' });
        vi.advanceTimersByTime(4_000);
        channel.hear({ user: 'user-luis' });
        vi.advanceTimersByTime(2_000);
        expect(typing.people.value.known).toEqual(['Luis']);

        vi.advanceTimersByTime(4_000);
        expect(typing.people.value.known).toEqual([]);
    });

    it('keeps track of a few people at most', () => {
        const channel = aChannel();
        const typing = typingWith(channel);

        for (let index = 0; index < TYPING_MAX_PEOPLE + 5; index++) {
            channel.hear({ user: `user-${index}` });
        }

        expect(typing.people.value.unknown).toBe(TYPING_MAX_PEOPLE);
    });

    it('forgets someone whose post arrived, and stops listening with its component', () => {
        const channel = aChannel();
        const typing = typingWith(channel);

        channel.hear({ user: 'user-ana' });
        typing.clear('USER-ANA');
        expect(typing.people.value.known).toEqual([]);

        scope.stop();
        expect(channel.listening()).toBe(0);
    });
});
