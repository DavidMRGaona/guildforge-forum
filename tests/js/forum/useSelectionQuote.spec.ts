import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { useSelectionQuote } from '../../../resources/js/composables/useSelectionQuote';

interface Options {
    enabled?: boolean;
    fits?: boolean;
}

type Watched = ReturnType<typeof useSelectionQuote>;

/** A post body and some text outside it, watched by the composable */
function watching(options: Options = {}): { wrapper: VueWrapper; watched: () => Watched } {
    let watched: Watched | null = null;
    const Host = defineComponent({
        setup() {
            const body = ref<HTMLElement | null>(null);
            watched = useSelectionQuote(body, {
                enabled: () => options.enabled ?? true,
                fits: () => options.fits ?? true,
            });

            return () =>
                h('div', [
                    h('div', { ref: body, 'data-testid': 'body' }, [
                        h('p', 'Al abordaje, marineros'),
                    ]),
                    h('p', { 'data-testid': 'outside' }, 'Otro mensaje'),
                ]);
        },
    });
    const wrapper = mount(Host, { attachTo: document.body });

    return { wrapper, watched: () => watched as Watched };
}

function textOf(wrapper: VueWrapper, testId: string): Node {
    const node =
        wrapper.get(`[data-testid="${testId}"]`).element.querySelector('p') ??
        wrapper.get(`[data-testid="${testId}"]`).element;
    return node.firstChild as Node;
}

/** Selects from one text node to another (or within one) and tells the document */
function select(start: Node, startOffset: number, end: Node, endOffset: number): void {
    const selection = window.getSelection()!;
    selection.removeAllRanges();
    selection.setBaseAndExtent(start, startOffset, end, endOffset);
    document.dispatchEvent(new Event('selectionchange'));
}

function clear(): void {
    window.getSelection()?.removeAllRanges();
    document.dispatchEvent(new Event('selectionchange'));
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    window.getSelection()?.removeAllRanges();
    document.body.innerHTML = '';
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('useSelectionQuote', () => {
    it('shows for a selection inside the body that fits', () => {
        const { wrapper, watched } = watching();
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);

        expect(watched().visible.value).toBe(true);
        expect(watched().text.value).toBe('abordaje');
    });

    it('waits until the selection stops changing', () => {
        const { wrapper, watched } = watching();
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(100);

        expect(watched().visible.value).toBe(false);
    });

    it('ignores a selection that leaves the body', () => {
        const { wrapper, watched } = watching();
        select(
            textOf(wrapper, 'body'),
            3,
            wrapper.get('[data-testid="outside"]').element.firstChild as Node,
            4
        );
        vi.advanceTimersByTime(150);

        expect(watched().visible.value).toBe(false);
    });

    it('ignores a selection that does not fit', () => {
        const { wrapper, watched } = watching({ fits: false });
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);

        expect(watched().visible.value).toBe(false);
    });

    it('ignores everything while disabled', () => {
        const { wrapper, watched } = watching({ enabled: false });
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);

        expect(watched().visible.value).toBe(false);
    });

    it('hides on Escape and when the selection collapses', () => {
        const { wrapper, watched } = watching();
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(watched().visible.value).toBe(false);

        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);
        expect(watched().visible.value).toBe(true);
        clear();
        vi.advanceTimersByTime(150);

        expect(watched().visible.value).toBe(false);
    });

    it('stops listening once unmounted', () => {
        const { wrapper } = watching();
        const fromDocument = vi.spyOn(document, 'removeEventListener');
        const fromWindow = vi.spyOn(window, 'removeEventListener');
        wrapper.unmount();

        const removed = [...fromDocument.mock.calls, ...fromWindow.mock.calls].map(
            ([type]) => type
        );
        expect(removed).toEqual(
            expect.arrayContaining(['selectionchange', 'keydown', 'scroll', 'resize'])
        );
    });
});
