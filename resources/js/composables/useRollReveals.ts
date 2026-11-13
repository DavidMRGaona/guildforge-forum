import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

/** A hidden roll the reader asked to reveal: its id and what it reads like («2d6+3 Ataque») */
export interface RollToReveal {
    id: string;
    label: string;
}

const ROLL_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/u;

/**
 * The «Revelar» buttons of a rendered post. The server draws one on every hidden roll the reader
 * may reveal (RollNode); a click only says which roll was asked for, so the page can confirm it
 * before anything is sent. Whether the reader may reveal it is the server's to decide again.
 */
export function useRollReveals(body: Ref<HTMLElement | null>): {
    asked: Ref<RollToReveal | null>;
} {
    const asked = ref<RollToReveal | null>(null);

    function onClick(event: Event): void {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest<HTMLElement>('[data-roll-reveal]') ?? null;
        const id = button?.dataset['rollReveal'] ?? '';
        if (button === null || !ROLL_ID.test(id)) {
            return;
        }

        event.preventDefault();
        const roll = button.closest('.forum-roll');
        const expression = roll?.querySelector('.forum-roll__expression')?.textContent ?? '';
        const reason = roll?.querySelector('.forum-roll__reason')?.textContent ?? '';
        asked.value = { id, label: `${expression} ${reason}`.trim() };
    }

    let attached: HTMLElement | null = null;

    function attach(element: HTMLElement | null): void {
        attached?.removeEventListener('click', onClick);
        attached = element;
        attached?.addEventListener('click', onClick);
    }

    onMounted(() => attach(body.value));
    watch(body, (element) => attach(element));
    onBeforeUnmount(() => attach(null));

    return { asked };
}
