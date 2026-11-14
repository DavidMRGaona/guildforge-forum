import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref, type Ref, type VNode } from 'vue';
import { useSelectionQuote } from '../../../resources/js/composables/useSelectionQuote';

interface Options {
    enabled?: Ref<boolean>;
    fits?: boolean;
}

const hosts: VueWrapper[] = [];

type Watched = ReturnType<typeof useSelectionQuote>;

/** A post body and some text outside it, watched by the composable */
function watching(options: Options = {}): { wrapper: VueWrapper; watched: () => Watched } {
    let watched: Watched | null = null;
    const Host = defineComponent({
        setup(): () => VNode {
            const body = ref<HTMLElement | null>(null);
            watched = useSelectionQuote(body, {
                enabled: () => options.enabled?.value ?? true,
                fits: () => options.fits ?? true,
            });

            return () =>
                h('div', [
                    h('div', { ref: body, 'data-testid': 'body' }, [
                        h('p', 'Al abordaje, marineros'),
                    ]),
                    h('p', { 'data-testid': 'outside' }, 'Otro mensaje'),
                    h('button', {
                        'data-testid': 'floating',
                        ref: (element: unknown) => {
                            if (watched !== null) {
                                watched.floating.value =
                                    element instanceof HTMLElement ? element : null;
                            }
                        },
                    }),
                ]);
        },
    });
    const wrapper = mount(Host, { attachTo: document.body });
    hosts.push(wrapper);

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

/** The selection drawn at a given place, as the browser would measure it */
function rangeAt(top: number): void {
    vi.spyOn(Range.prototype, 'getBoundingClientRect').mockReturnValue(
        DOMRect.fromRect({ x: 100, y: top, width: 50, height: 20 })
    );
}

/** happy-dom lays nothing out, so the page measures 0 px: give it a real window */
function viewport(width: number, height: number): void {
    vi.spyOn(document.documentElement, 'clientWidth', 'get').mockReturnValue(width);
    vi.spyOn(document.documentElement, 'clientHeight', 'get').mockReturnValue(height);
}

function touchScreen(coarse: boolean): void {
    viewport(1024, 768);
    vi.spyOn(window, 'matchMedia').mockImplementation(
        (query: string) =>
            ({ matches: coarse && query === '(pointer: coarse)', media: query }) as MediaQueryList
    );
}

afterEach(() => {
    window.getSelection()?.removeAllRanges();
    hosts.splice(0).forEach((host) => {
        if (host.exists()) {
            host.unmount();
        }
    });
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
        const { wrapper, watched } = watching({ enabled: ref(false) });
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
        hosts.splice(hosts.indexOf(wrapper), 1);
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

    it('shows when the selection runs past the body without taking in more text', () => {
        // A triple click on the last paragraph ends the selection at the start of what follows
        const { wrapper, watched } = watching();
        select(textOf(wrapper, 'body'), 0, textOf(wrapper, 'outside'), 0);
        vi.advanceTimersByTime(150);

        expect(watched().visible.value).toBe(true);
        expect(watched().text.value).toBe('Al abordaje, marineros');
    });

    it('hides once it may no longer quote, without waiting for a selection change', async () => {
        // Removing the selected nodes (opening the editor) collapses the selection silently
        const enabled = ref(true);
        const { wrapper, watched } = watching({ enabled });
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);
        expect(watched().visible.value).toBe(true);

        enabled.value = false;
        await nextTick();

        expect(watched().visible.value).toBe(false);
    });

    it('sits 8 px above the selection with a mouse', async () => {
        touchScreen(false);
        rangeAt(300);
        const { wrapper } = watching();
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);
        const floating = wrapper.get('[data-testid="floating"]').element as HTMLElement;

        await vi.waitFor(() => expect(floating.style.top).toBe('292px'));
    });

    it('sits 24 px below the selection on a touch screen, clear of its handles', async () => {
        touchScreen(true);
        rangeAt(300);
        const { wrapper } = watching();
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);
        const floating = wrapper.get('[data-testid="floating"]').element as HTMLElement;

        await vi.waitFor(() => expect(floating.style.top).toBe('344px'));
    });

    it('hides while the selection is scrolled out of view', async () => {
        touchScreen(false);
        rangeAt(-500);
        const { wrapper } = watching();
        const text = textOf(wrapper, 'body');
        select(text, 3, text, 11);
        vi.advanceTimersByTime(150);
        const floating = wrapper.get('[data-testid="floating"]').element as HTMLElement;

        await vi.waitFor(() => expect(floating.style.visibility).toBe('hidden'));
    });
});
