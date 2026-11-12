import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createPoller,
    type PollOutcome,
    type PollerVisibility,
} from '../../../resources/js/live/poller';

class FakeVisibility implements PollerVisibility {
    hidden = false;
    private listeners = new Set<() => void>();

    addEventListener(_type: 'visibilitychange', listener: () => void): void {
        this.listeners.add(listener);
    }

    removeEventListener(_type: 'visibilitychange', listener: () => void): void {
        this.listeners.delete(listener);
    }

    set(hidden: boolean): void {
        this.hidden = hidden;
        this.listeners.forEach((listener) => listener());
    }

    get listening(): number {
        return this.listeners.size;
    }
}

describe('createPoller', () => {
    let visibility: FakeVisibility;
    let run: ReturnType<typeof vi.fn<() => Promise<PollOutcome>>>;

    beforeEach(() => {
        vi.useFakeTimers();
        visibility = new FakeVisibility();
        run = vi.fn<() => Promise<PollOutcome>>().mockResolvedValue('ok');
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function poller(): ReturnType<typeof createPoller> {
        return createPoller({
            intervalMs: 20_000,
            maxIntervalMs: 160_000,
            run,
            visibility,
            // The middle of the spread: waits are exactly the pace
            random: () => 0.5,
        });
    }

    it('asks every interval, not at once', async () => {
        poller().start();

        await vi.advanceTimersByTimeAsync(19_999);
        expect(run).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(20_000);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('spreads each wait a little so tabs opened together do not ask together', async () => {
        const early = createPoller({
            intervalMs: 20_000,
            maxIntervalMs: 160_000,
            run,
            visibility,
            random: () => 0,
        });
        early.start();

        await vi.advanceTimersByTimeAsync(17_999);
        expect(run).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('stops asking while the tab is hidden and asks at once when it is shown again', async () => {
        poller().start();
        await vi.advanceTimersByTimeAsync(20_000);
        expect(run).toHaveBeenCalledTimes(1);

        visibility.set(true);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(run).toHaveBeenCalledTimes(1);

        visibility.set(false);
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(2);

        // And it goes on at its pace
        await vi.advanceTimersByTimeAsync(20_000);
        expect(run).toHaveBeenCalledTimes(3);
    });

    it('does not ask when it starts with the tab hidden, until it is shown', async () => {
        visibility.hidden = true;
        poller().start();

        await vi.advanceTimersByTimeAsync(60_000);
        expect(run).not.toHaveBeenCalled();

        visibility.set(false);
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('waits twice as long after each failure, up to a ceiling, and recovers with an answer', async () => {
        run.mockResolvedValue('retry');
        poller().start();

        await vi.advanceTimersByTimeAsync(20_000);
        expect(run).toHaveBeenCalledTimes(1);
        // 40 s after the first failure
        await vi.advanceTimersByTimeAsync(39_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);
        // 80 s, then 160 s, and 160 s again: the ceiling
        await vi.advanceTimersByTimeAsync(80_000);
        expect(run).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(160_000);
        expect(run).toHaveBeenCalledTimes(4);
        await vi.advanceTimersByTimeAsync(160_000);
        expect(run).toHaveBeenCalledTimes(5);

        run.mockResolvedValue('ok');
        await vi.advanceTimersByTimeAsync(160_000);
        expect(run).toHaveBeenCalledTimes(6);
        // Back to its pace
        await vi.advanceTimersByTimeAsync(20_000);
        expect(run).toHaveBeenCalledTimes(7);
    });

    it('treats a question that throws as a failure', async () => {
        run.mockRejectedValue(new Error('network'));
        poller().start();

        await vi.advanceTimersByTimeAsync(20_000);
        await vi.advanceTimersByTimeAsync(39_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('ends for good when told to stop', async () => {
        run.mockResolvedValue('stop');
        poller().start();

        await vi.advanceTimersByTimeAsync(20_000);
        await vi.advanceTimersByTimeAsync(600_000);
        expect(run).toHaveBeenCalledTimes(1);
        expect(visibility.listening).toBe(0);
    });

    it('never has two questions in flight, and asks once more after one for requests made meanwhile', async () => {
        let finish: (outcome: PollOutcome) => void = () => undefined;
        run.mockImplementation(
            () =>
                new Promise<PollOutcome>((resolve) => {
                    finish = resolve;
                })
        );
        const instance = poller();
        instance.start();

        await vi.advanceTimersByTimeAsync(20_000);
        instance.pollNow();
        instance.pollNow();
        expect(run).toHaveBeenCalledTimes(1);

        // The requests made meanwhile become one more question, right after the first
        finish('ok');
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(2);
        expect(finish).toBeTypeOf('function');
        finish('ok');
        await vi.advanceTimersByTimeAsync(20_000);
        expect(run).toHaveBeenCalledTimes(3);
    });

    it('asks now on request, and stops cleanly', async () => {
        const instance = poller();
        instance.start();

        instance.pollNow();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        instance.stop();
        instance.pollNow();
        await vi.advanceTimersByTimeAsync(300_000);
        expect(run).toHaveBeenCalledTimes(1);
        expect(visibility.listening).toBe(0);
    });
});
