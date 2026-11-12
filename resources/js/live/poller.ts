/**
 * Asks something every so often, politely:
 * - it waits while the tab is hidden and asks at once when it is shown again, so only the tab a
 *   person looks at asks;
 * - after a failure it waits twice as long each time, up to a ceiling, and goes back to its pace
 *   with the first answer that works;
 * - it never has two questions in flight (a request made meanwhile is asked right after), and each wait varies a little so tabs that opened
 *   together do not keep asking together.
 */
export type PollOutcome = 'ok' | 'retry' | 'stop';

export interface PollerVisibility {
    readonly hidden: boolean;
    addEventListener: (type: 'visibilitychange', listener: () => void) => void;
    removeEventListener: (type: 'visibilitychange', listener: () => void) => void;
}

export interface PollerOptions {
    intervalMs: number;
    /** The longest wait after repeated failures */
    maxIntervalMs: number;
    /** Asks once: `ok` keeps the pace, `retry` backs off, `stop` ends the polling for good */
    run: () => Promise<PollOutcome>;
    /** Where visibility is read from (the page's document by default) */
    visibility?: PollerVisibility;
    /** A number from 0 to 1 that spreads the waits (random by default) */
    random?: () => number;
}

export interface Poller {
    start: () => void;
    stop: () => void;
    /** Asks now, unless the tab is hidden; with a question in flight, asks once more right after it */
    pollNow: () => void;
}

export function createPoller(options: PollerOptions): Poller {
    const visibility = options.visibility ?? document;
    const random = options.random ?? Math.random;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let failures = 0;
    let running = false;
    // Asked for news while a question was in flight (an answer that said there is more)
    let again = false;
    let stopped = true;

    function clear(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function wait(): number {
        const base = Math.min(options.intervalMs * 2 ** failures, options.maxIntervalMs);

        return Math.round(base * (0.9 + 0.2 * random()));
    }

    function schedule(): void {
        clear();
        if (stopped || visibility.hidden) {
            return;
        }

        timer = setTimeout(() => void tick(), wait());
    }

    async function tick(): Promise<void> {
        timer = null;
        if (stopped || visibility.hidden || running) {
            return;
        }

        running = true;
        let outcome: PollOutcome;
        try {
            outcome = await options.run();
        } catch {
            outcome = 'retry';
        }
        running = false;
        const rerun = again;
        again = false;

        if (stopped) {
            return;
        }
        if (outcome === 'stop') {
            stop();

            return;
        }

        failures = outcome === 'ok' ? 0 : failures + 1;
        if (rerun && outcome === 'ok') {
            void tick();

            return;
        }
        schedule();
    }

    function onVisibility(): void {
        if (visibility.hidden) {
            clear();

            return;
        }

        pollNow();
    }

    function pollNow(): void {
        if (stopped) {
            return;
        }

        if (running) {
            again = true;

            return;
        }

        clear();
        void tick();
    }

    function start(): void {
        if (!stopped) {
            return;
        }

        stopped = false;
        failures = 0;
        visibility.addEventListener('visibilitychange', onVisibility);
        schedule();
    }

    function stop(): void {
        stopped = true;
        clear();
        visibility.removeEventListener('visibilitychange', onVisibility);
    }

    return { start, stop, pollNow };
}
