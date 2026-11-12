import { computed, ref, type ComputedRef, type Ref } from 'vue';

export interface SplitSelection {
    /** Whether the page is choosing posts to split off */
    active: Readonly<Ref<boolean>>;
    /** The chosen numbers, in order */
    numbers: ComputedRef<number[]>;
    has: (number: number) => boolean;
    toggle: (number: number) => void;
    start: () => void;
    cancel: () => void;
}

interface Stored {
    active: boolean;
    numbers: number[];
}

/**
 * The posts a moderator chooses to split off a discussion. The choice is kept for this tab
 * while they go through the discussion's pages (session storage, per discussion); without
 * storage it lasts as long as the page.
 */
export function useSplitSelection(discussionId: string): SplitSelection {
    const key = `forum-split-${discussionId}`;
    const stored = read(key);
    const active = ref(stored.active);
    const chosen = ref<Set<number>>(new Set(stored.numbers));

    const numbers = computed(() => [...chosen.value].sort((a, b) => a - b));

    function save(): void {
        try {
            if (active.value) {
                const value: Stored = { active: true, numbers: numbers.value };
                window.sessionStorage.setItem(key, JSON.stringify(value));
            } else {
                window.sessionStorage.removeItem(key);
            }
        } catch {
            // Without storage the choice lasts as long as the page
        }
    }

    function toggle(number: number): void {
        const next = new Set(chosen.value);
        if (next.has(number)) {
            next.delete(number);
        } else {
            next.add(number);
        }
        chosen.value = next;
        save();
    }

    function start(): void {
        active.value = true;
        save();
    }

    function cancel(): void {
        active.value = false;
        chosen.value = new Set();
        save();
    }

    return {
        active,
        numbers,
        has: (number) => chosen.value.has(number),
        toggle,
        start,
        cancel,
    };
}

function read(key: string): Stored {
    try {
        const raw = window.sessionStorage.getItem(key);
        const parsed: unknown = raw === null ? null : JSON.parse(raw);
        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            'active' in parsed &&
            parsed.active === true &&
            'numbers' in parsed &&
            Array.isArray(parsed.numbers)
        ) {
            const values: unknown[] = parsed.numbers;

            return {
                active: true,
                numbers: values.filter(
                    (value): value is number => Number.isInteger(value) && Number(value) > 1
                ),
            };
        }
    } catch {
        // Unreadable or unavailable: nothing chosen
    }

    return { active: false, numbers: [] };
}

/**
 * The numbers as people read them: runs joined with a dash («3, 5–7»).
 */
export function describeNumbers(numbers: number[]): string {
    const runs: string[] = [];
    let start: number | null = null;
    let previous: number | null = null;
    for (const number of [...numbers].sort((a, b) => a - b)) {
        if (start !== null && previous !== null && number === previous + 1) {
            previous = number;
            continue;
        }
        if (start !== null && previous !== null) {
            runs.push(start === previous ? `${start}` : `${start}–${previous}`);
        }
        start = number;
        previous = number;
    }
    if (start !== null && previous !== null) {
        runs.push(start === previous ? `${start}` : `${start}–${previous}`);
    }

    return runs.join(', ');
}
