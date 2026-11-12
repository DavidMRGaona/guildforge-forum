import { onBeforeUnmount, onMounted, type Ref } from 'vue';
import { csrfHeaders } from '@/utils/csrf';
import { mergedTarget } from '../forum/api';
import type { ForumMergedInto } from '../types/forum';

export interface ReadTrackerOptions {
    /** Where the page reports the highest post it showed; null for guests (nothing is kept) */
    readUrl: string | null;
    /** The reader's mark when the page loaded */
    lastReadNumber: number | null;
    /** The list holding the posts, each with `data-post-number` and `data-post-url` */
    container: Ref<HTMLElement | null>;
    /** Called with the post at the top of the screen, so the address can follow it */
    onTopPost?: (url: string) => void;
    /** Waits this long before reporting, so scrolling past posts sends one request */
    delayMs?: number;
    /** Hears that the discussion was merged into another and where it went; nothing more is sent */
    onMerged?: (target: ForumMergedInto) => void;
}

/**
 * Reports to the server the highest post the reader actually had on screen. A post counts as
 * seen once most of it (or the screen's height of it) was visible; nothing is reported for
 * posts never scrolled to, so the mark never goes ahead of the reader.
 */
export function useReadTracker(options: ReadTrackerOptions): {
    flush: () => void;
    refresh: () => void;
} {
    const delay = options.delayMs ?? 1200;
    let highestSeen = options.lastReadNumber ?? 0;
    let reported = highestSeen;
    // The discussion was merged into another: there is nothing left to mark here
    let merged = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let observer: IntersectionObserver | null = null;
    const visible = new Map<Element, number>();
    const watched = new WeakSet<Element>();

    function send(keepalive: boolean): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
        if (options.readUrl === null || merged || highestSeen <= reported) {
            return;
        }

        const number = highestSeen;
        reported = number;
        void fetch(options.readUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            body: JSON.stringify({ number }),
        })
            .then(async (response) => {
                if (response.status !== 410) {
                    return;
                }
                const target = mergedTarget(
                    response.status,
                    await response.json().catch(() => null)
                );
                if (target !== null) {
                    merged = true;
                    options.onMerged?.(target);
                }
            })
            .catch(() => {
                // Not kept this time: the next post seen reports it again
                reported = Math.min(reported, number - 1);
            });
    }

    function schedule(): void {
        if (timer === null) {
            timer = setTimeout(() => send(false), delay);
        }
    }

    function topPost(): void {
        // The first post that still fills part of the top quarter: one barely showing its end
        // above it does not count
        const limit = window.innerHeight * 0.25;
        let top: HTMLElement | null = null;
        let topY = Number.POSITIVE_INFINITY;
        for (const element of visible.keys()) {
            const rect = element.getBoundingClientRect();
            if (element instanceof HTMLElement && rect.bottom > limit && rect.top < topY) {
                topY = rect.top;
                top = element;
            }
        }
        const url = top?.dataset.postUrl;
        if (url && options.onTopPost) {
            options.onTopPost(url);
        }
    }

    function onPageHide(): void {
        send(true);
    }

    /**
     * Watches the posts that came to the list after it mounted. A post counts as read only when
     * it is seen on screen, as any other: arriving marks nothing.
     */
    function observeNew(): void {
        options.container.value?.querySelectorAll('[data-post-number]').forEach((element) => {
            if (!watched.has(element)) {
                watched.add(element);
                observer?.observe(element);
            }
        });
    }

    onMounted(() => {
        const root = options.container.value;
        if (root === null || typeof IntersectionObserver === 'undefined') {
            return;
        }

        observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const number = Number((entry.target as HTMLElement).dataset.postNumber);
                    if (!Number.isInteger(number) || number < 1) {
                        continue;
                    }

                    if (entry.isIntersecting) {
                        visible.set(entry.target, number);
                    } else {
                        visible.delete(entry.target);
                    }

                    const seen =
                        entry.intersectionRatio >= 0.6 ||
                        entry.intersectionRect.height >=
                            (entry.rootBounds?.height ?? Infinity) * 0.6;
                    if (seen && number > highestSeen) {
                        highestSeen = number;
                        schedule();
                    }
                }
                topPost();
            },
            { threshold: [0, 0.3, 0.6, 1] }
        );

        observeNew();
        window.addEventListener('pagehide', onPageHide);
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        window.removeEventListener('pagehide', onPageHide);
        send(true);
    });

    return { flush: () => send(false), refresh: observeNew };
}
