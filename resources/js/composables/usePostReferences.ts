import { nextTick, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import type { ForumPostPreview } from '../types/forum';

/** How many boxes open one inside another; a reference deeper than that goes to its post */
export const MAX_REFERENCE_DEPTH = 3;

/** A reference opened in place: its box, under the block of the body that holds it */
export interface OpenReference {
    key: number;
    url: string;
    link: HTMLElement;
    anchor: HTMLElement;
    preview: ForumPostPreview | null;
    failed: boolean;
}

/**
 * The references of a rendered post body (`a.forum-ref`, drawn by the server only for posts
 * the reader may read). A plain click opens the post in a box under the paragraph instead of
 * going to it, and a second click closes it; with a modifier key or another button the link
 * works as a link. Boxes open inside boxes up to MAX_REFERENCE_DEPTH; past that, links go.
 *
 * The boxes live inside the server's HTML, so they close whenever that HTML is drawn again.
 */
export function usePostReferences(
    body: Ref<HTMLElement | null>,
    options: {
        depth: number;
        load: (url: string) => Promise<ForumPostPreview | null>;
        /** The body's HTML: when it is drawn again, its links are marked again */
        html: () => string | null | undefined;
    }
): { open: Ref<OpenReference[]>; closeAll: () => void } {
    const open = ref<OpenReference[]>([]) as Ref<OpenReference[]>;
    const loaded = new Map<string, Promise<ForumPostPreview | null>>();
    let next = 0;

    function close(item: OpenReference): void {
        open.value = open.value.filter((entry) => entry.key !== item.key);
        item.link.setAttribute('aria-expanded', 'false');
        item.anchor.remove();
    }

    function closeAll(): void {
        for (const item of open.value) {
            item.link.setAttribute('aria-expanded', 'false');
            item.anchor.remove();
        }
        open.value = [];
    }

    /** The block of the body the link sits in: the box goes right after it */
    function blockOf(element: HTMLElement, container: HTMLElement): HTMLElement {
        let block = element;
        while (block.parentElement !== null && block.parentElement !== container) {
            block = block.parentElement;
        }

        return block;
    }

    function onClick(event: MouseEvent): void {
        const container = body.value;
        const target = event.target instanceof Element ? event.target : null;
        const link = target?.closest<HTMLElement>('a.forum-ref[data-preview-url]') ?? null;
        if (container === null || link === null || !container.contains(link)) {
            return;
        }
        // A link inside a box opened here belongs to that box
        const box = link.closest('.forum-ref-preview');
        if (box !== null && container.contains(box)) {
            return;
        }
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.altKey ||
            options.depth >= MAX_REFERENCE_DEPTH
        ) {
            return;
        }

        event.preventDefault();
        const already = open.value.find((entry) => entry.link === link);
        if (already) {
            close(already);

            return;
        }

        const url = link.dataset['previewUrl'] ?? '';
        const anchor = document.createElement('div');
        anchor.className = 'forum-ref-preview';
        blockOf(link, container).insertAdjacentElement('afterend', anchor);
        link.setAttribute('aria-expanded', 'true');

        const item: OpenReference = {
            key: next++,
            url,
            link,
            anchor,
            preview: null,
            failed: false,
        };
        open.value = [...open.value, item];

        let request = loaded.get(url);
        if (!request) {
            request = options.load(url);
            loaded.set(url, request);
        }
        void request.then((preview) => {
            open.value = open.value.map((entry) =>
                entry.key === item.key ? { ...entry, preview, failed: preview === null } : entry
            );
            if (preview === null) {
                // Asked again next time
                loaded.delete(url);
            }
        });
    }

    /**
     * Says on every reference of this body that it opens in place (closed for now), so a screen
     * reader announces it before the first click. Links inside boxes are their own body's.
     */
    function mark(): void {
        const container = body.value;
        if (container === null || options.depth >= MAX_REFERENCE_DEPTH) {
            return;
        }
        container.querySelectorAll<HTMLElement>('a.forum-ref[data-preview-url]').forEach((link) => {
            const box = link.closest('.forum-ref-preview');
            if ((box === null || !container.contains(box)) && !link.hasAttribute('aria-expanded')) {
                link.setAttribute('aria-expanded', 'false');
            }
        });
    }

    let attached: HTMLElement | null = null;

    function attach(element: HTMLElement | null): void {
        attached?.removeEventListener('click', onClick);
        attached = element;
        attached?.addEventListener('click', onClick);
        mark();
    }

    onMounted(() => attach(body.value));
    watch(body, (element) => attach(element));
    watch(options.html, () => void nextTick(mark));
    onBeforeUnmount(() => {
        closeAll();
        attach(null);
    });

    return { open, closeAll };
}
