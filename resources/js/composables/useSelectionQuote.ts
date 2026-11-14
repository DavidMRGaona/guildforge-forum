import { computePosition, flip, offset, shift } from '@floating-ui/dom';
import { nextTick, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

/** How long the selection must stay still before the button shows: never while dragging */
const SETTLE_MS = 150;

export interface SelectionQuoteOptions {
    /** Whether the post may be quoted right now (visible, the reader may reply, not editing) */
    enabled: () => boolean;
    /** Whether the server would take the text as words of the post */
    fits: (text: string) => boolean;
}

export interface SelectionQuote {
    /** Whether the floating «Citar» button shows */
    visible: Ref<boolean>;
    /** The selected text, trimmed */
    text: Ref<string>;
    /** The button, placed next to the selection once it is drawn */
    floating: Ref<HTMLElement | null>;
    /** Hides the button until the next selection */
    hide: () => void;
}

/**
 * Watches the document's selection for one post's body and offers quoting it: the button shows
 * once a selection that lies wholly in the body (both ends) settles, fits the post's stored text
 * and the post may be quoted. It sits above the selection with a mouse and below it on touch
 * screens, where the system's own selection menu takes the space above.
 */
export function useSelectionQuote(
    body: Ref<HTMLElement | null>,
    options: SelectionQuoteOptions
): SelectionQuote {
    const visible = ref(false);
    const text = ref('');
    const floating = ref<HTMLElement | null>(null);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let range: Range | null = null;

    function hide(): void {
        visible.value = false;
        text.value = '';
        range = null;
    }

    /** The selection's trimmed text when it lies wholly in the body and may be quoted */
    function read(): { text: string; range: Range } | null {
        const container = body.value;
        const selection = window.getSelection();
        if (
            container === null ||
            selection === null ||
            selection.isCollapsed ||
            selection.rangeCount === 0 ||
            !options.enabled()
        ) {
            return null;
        }
        const { anchorNode, focusNode } = selection;
        if (
            anchorNode === null ||
            focusNode === null ||
            !container.contains(anchorNode) ||
            !container.contains(focusNode)
        ) {
            return null;
        }
        const selected = selection.toString().trim();

        return selected !== '' && options.fits(selected)
            ? { text: selected, range: selection.getRangeAt(0) }
            : null;
    }

    function settle(): void {
        timer = null;
        const found = read();
        if (found === null) {
            hide();

            return;
        }
        text.value = found.text;
        range = found.range;
        visible.value = true;
        void nextTick(place);
    }

    function onSelectionChange(): void {
        if (timer !== null) {
            clearTimeout(timer);
        }
        // Gone at once when the selection leaves; shown only once it stops changing
        if (visible.value && read() === null) {
            hide();
        }
        timer = setTimeout(settle, SETTLE_MS);
    }

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && visible.value) {
            hide();
        }
    }

    function place(): void {
        const element = floating.value;
        const current = range;
        if (element === null || current === null) {
            return;
        }
        const touch =
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(pointer: coarse)').matches;
        void computePosition(
            { getBoundingClientRect: () => current.getBoundingClientRect() },
            element,
            {
                placement: touch ? 'bottom' : 'top',
                strategy: 'fixed',
                middleware: [offset(8), flip(), shift({ padding: 8 })],
            }
        ).then(({ x, y }) => {
            Object.assign(element.style, { left: `${x}px`, top: `${y}px` });
        });
    }

    function onViewportChange(): void {
        if (visible.value) {
            place();
        }
    }

    watch(floating, () => place());

    onMounted(() => {
        document.addEventListener('selectionchange', onSelectionChange);
        document.addEventListener('keydown', onKeydown);
        window.addEventListener('scroll', onViewportChange, { passive: true, capture: true });
        window.addEventListener('resize', onViewportChange, { passive: true });
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearTimeout(timer);
        }
        document.removeEventListener('selectionchange', onSelectionChange);
        document.removeEventListener('keydown', onKeydown);
        window.removeEventListener('scroll', onViewportChange, { capture: true });
        window.removeEventListener('resize', onViewportChange);
    });

    return { visible, text, floating, hide };
}
