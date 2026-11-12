import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { JsonAnswer } from '../../../resources/js/forum/api';
import { pollingTransport } from '../../../resources/js/live/transport';

function answer<T>(status: number, data: T | null = null): JsonAnswer<T> {
    return { ok: status >= 200 && status < 300, status, data, message: null, errors: {} };
}

const VISIBLE = {
    hidden: false,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
};

describe('pollingTransport', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    function connect(
        request: () => Promise<JsonAnswer<{ n: number }>>,
        extra: { immediate?: boolean } = {}
    ): { updates: { n: number }[]; stop: () => void; refresh: () => void } {
        const updates: { n: number }[] = [];
        const connection = pollingTransport<{ n: number }>({
            intervalMs: 1000,
            request,
            visibility: VISIBLE,
            random: () => 0.5,
            ...extra,
        }).connect({ cursor: () => 7 }, (update) => updates.push(update));

        return { updates, ...connection };
    }

    it('hands over what the server sends, and nothing for no content', async () => {
        const request = vi
            .fn()
            .mockResolvedValueOnce(answer(204))
            .mockResolvedValueOnce(answer(200, { n: 1 }));
        const { updates } = connect(request);

        await vi.advanceTimersByTimeAsync(1000);
        expect(updates).toEqual([]);
        await vi.advanceTimersByTimeAsync(1000);
        expect(updates).toEqual([{ n: 1 }]);
    });

    it('gives the request what the page has', async () => {
        const request = vi.fn().mockResolvedValue(answer(204));
        connect(request);

        await vi.advanceTimersByTimeAsync(1000);
        expect(request).toHaveBeenCalledWith(
            expect.objectContaining({ cursor: expect.any(Function) })
        );
        expect(request.mock.calls[0]?.[0].cursor()).toBe(7);
    });

    it('asks as soon as it connects when told to', async () => {
        const request = vi.fn().mockResolvedValue(answer(200, { n: 3 }));
        const { updates } = connect(request, { immediate: true });

        await vi.advanceTimersByTimeAsync(0);
        expect(updates).toEqual([{ n: 3 }]);
    });

    it.each([0, 429, 500, 503])('backs off after a %s', async (status) => {
        const request = vi.fn().mockResolvedValue(answer(status));
        connect(request);

        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(1999);
        expect(request).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(request).toHaveBeenCalledTimes(2);
    });

    it.each([401, 403, 404])('ends after a %s: asking again changes nothing', async (status) => {
        const request = vi.fn().mockResolvedValue(answer(status));
        connect(request);

        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(600_000);
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('tells where a discussion went when the server says it was merged, and ends', async () => {
        const merged = { title: 'Destino', url: '/foro/t/2-destino' };
        const request = vi.fn().mockResolvedValue({ ...answer(410), mergedInto: merged });
        const onMerged = vi.fn();
        pollingTransport<{ n: number }>({
            intervalMs: 1000,
            request,
            visibility: VISIBLE,
            random: () => 0.5,
        }).connect({ cursor: () => 7 }, () => undefined, onMerged);

        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(600_000);

        expect(onMerged).toHaveBeenCalledExactlyOnceWith(merged);
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('ends quietly after a 410 that says nothing about where', async () => {
        const request = vi.fn().mockResolvedValue(answer(410));
        const onMerged = vi.fn();
        pollingTransport<{ n: number }>({
            intervalMs: 1000,
            request,
            visibility: VISIBLE,
            random: () => 0.5,
        }).connect({ cursor: () => 7 }, () => undefined, onMerged);

        await vi.advanceTimersByTimeAsync(600_000);

        expect(onMerged).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('can be asked for news now and stopped', async () => {
        const request = vi.fn().mockResolvedValue(answer(204));
        const { refresh, stop } = connect(request);

        refresh();
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(1);

        stop();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(request).toHaveBeenCalledTimes(1);
    });
});
