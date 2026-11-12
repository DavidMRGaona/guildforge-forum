import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref, type VNode } from 'vue';
import FollowSelect from '../../../resources/js/components/forum/FollowSelect.vue';
import DiscussionModeration from '../../../resources/js/components/moderation/DiscussionModeration.vue';
import MergeDialog from '../../../resources/js/components/moderation/MergeDialog.vue';
import SplitPosts from '../../../resources/js/components/moderation/SplitPosts.vue';
import { MERGED_DISCUSSION } from '../../../resources/js/composables/useMergedDiscussion';
import { useReadTracker } from '../../../resources/js/composables/useReadTracker';
import { mentionSuggestion } from '../../../resources/js/editor/mentionSuggestion';
import type {
    ForumConversation,
    ForumDiscussionModeration,
    ForumMergedInto,
} from '../../../resources/js/types/forum';

/**
 * What a page left open on a discussion that was merged into another does besides asking for
 * news and replying: each answer of 410 tells the page where the discussion went, so it shows
 * the same way on (MergedNotice), and nothing is done on the old one.
 */

const MERGED: ForumMergedInto = { title: 'Destino', url: '/foro/t/2-destino' };
const MESSAGE = 'Este tema se ha unido a «Destino».';

function gone(): Response {
    return new Response(JSON.stringify({ message: MESSAGE, mergedInto: MERGED }), {
        status: 410,
        headers: { 'Content-Type': 'application/json' },
    });
}

function missing(): Response {
    return new Response(JSON.stringify({ message: 'Not Found' }), { status: 404 });
}

function element<T extends HTMLElement>(testId: string): T {
    const found = document.body.querySelector<T>(`[data-testid="${testId}"]`);
    if (found === null) {
        throw new Error(`No ${testId}`);
    }

    return found;
}

const moderation: ForumDiscussionModeration = {
    queueUrl: '/foro/moderacion',
    suspendUrl: null,
    actionsUrl: '/foro/temas/d-1/moderacion',
    deleteUrl: '/foro/temas/d-1',
    moveTargets: [],
    splitUrl: '/foro/temas/d-1/separar',
    mergeUrl: '/foro/temas/d-1/unir',
    mergeTargetUrl: '/foro/temas/d-1/unir/destino',
    splitCategories: [{ id: 'c-own', name: 'General', parentName: null }],
    limits: { titleMin: 3, titleMax: 120, reasonMax: 500, hiddenReasonMax: 300, splitMax: 500 },
};

const discussion: ForumConversation = {
    id: 'd-1',
    number: 1,
    title: 'Mesa del viernes',
    url: '/foro/t/1-mesa-del-viernes',
    isClosed: false,
    isPinned: false,
    isHidden: false,
    isAwaitingApproval: false,
    topics: [],
    // Only what the menu reads
    category: { name: 'General', url: '/foro/c/general', parent: null } as never,
    follow: null,
};

let heard: ForumMergedInto[];

function listening(): { provide: Record<symbol, (target: ForumMergedInto) => void> } {
    return { provide: { [MERGED_DISCUSSION]: (target) => heard.push(target) } };
}

beforeAll(() => {
    // jsdom has no modal dialogs
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

beforeEach(() => {
    heard = [];
    window.sessionStorage.clear();
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.useRealTimers();
    vi.mocked(router.visit).mockClear();
    vi.mocked(router.reload).mockClear();
});

describe('FollowSelect on a merged discussion', () => {
    it('tells the page where it went and says why nothing changed', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(gone()));
        const wrapper = mount(FollowSelect, {
            props: {
                follow: { level: 'normal', url: '/foro/temas/d-1/seguimiento' },
                discussionId: 'd-1',
            },
            global: listening(),
        });

        await wrapper.get('select').setValue('following');
        await flushPromises();

        expect(heard).toEqual([MERGED]);
        expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('normal');
        expect(wrapper.get('[role="alert"]').text()).toBe(MESSAGE);
    });

    it('tells nothing when the discussion is only missing', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(missing()));
        const wrapper = mount(FollowSelect, {
            props: {
                follow: { level: 'normal', url: '/foro/temas/d-1/seguimiento' },
                discussionId: 'd-1',
            },
            global: listening(),
        });

        await wrapper.get('select').setValue('following');
        await flushPromises();

        expect(heard).toEqual([]);
    });

    it('works where nobody listens (a record’s conversation)', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(gone()));
        const wrapper = mount(FollowSelect, {
            props: {
                follow: { level: 'normal', url: '/foro/temas/d-1/seguimiento' },
                discussionId: 'd-1',
            },
        });

        await wrapper.get('select').setValue('following');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe(MESSAGE);
    });
});

describe('Mentions on a merged discussion', () => {
    it('offers nobody and tells the page where the discussion went', async () => {
        const onMerged = vi.fn();
        const suggestion = mentionSuggestion({
            url: '/foro/temas/d-1/menciones',
            onPick: vi.fn(),
            onMerged,
            fetcher: vi.fn().mockResolvedValue(gone()),
        });

        const items = await suggestion.items?.({
            query: 'ja',
            editor: {} as never,
            signal: new AbortController().signal,
        });

        expect(items).toEqual([]);
        expect(onMerged).toHaveBeenCalledWith(MERGED);
    });

    it('tells nothing when the discussion is only missing', async () => {
        const onMerged = vi.fn();
        const suggestion = mentionSuggestion({
            url: '/foro/temas/d-1/menciones',
            onPick: vi.fn(),
            onMerged,
            fetcher: vi.fn().mockResolvedValue(missing()),
        });

        await suggestion.items?.({
            query: 'ja',
            editor: {} as never,
            signal: new AbortController().signal,
        });

        expect(onMerged).not.toHaveBeenCalled();
    });
});

describe('Read marks on a merged discussion', () => {
    type Seen = (entries: Partial<IntersectionObserverEntry>[]) => void;
    let see: Seen;

    function tracked(onMerged: (target: ForumMergedInto) => void): void {
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(callback: Seen) {
                    see = callback;
                }
                observe(): void {}
                disconnect(): void {}
            }
        );
        const Page = defineComponent({
            setup(): () => VNode {
                const container = ref<HTMLElement | null>(null);
                useReadTracker({
                    readUrl: '/foro/temas/d-1/leido',
                    lastReadNumber: null,
                    container,
                    onMerged,
                    delayMs: 10,
                });

                return () =>
                    h('div', { ref: container }, [
                        h('article', { 'data-post-number': '1' }),
                        h('article', { 'data-post-number': '2' }),
                    ]);
            },
        });
        mount(Page, { attachTo: document.body });
    }

    function seeing(number: number): void {
        see([
            {
                target: document.body.querySelector(`[data-post-number="${number}"]`) as Element,
                isIntersecting: true,
                intersectionRatio: 1,
                intersectionRect: { height: 100 } as DOMRectReadOnly,
                rootBounds: { height: 800 } as DOMRectReadOnly,
            },
        ]);
    }

    it('tells the page where it went and reports nothing more', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValue(gone());
        vi.stubGlobal('fetch', fetchMock);
        const onMerged = vi.fn();
        tracked(onMerged);

        seeing(1);
        await vi.advanceTimersByTimeAsync(20);
        await flushPromises();
        expect(onMerged).toHaveBeenCalledWith(MERGED);

        seeing(2);
        await vi.advanceTimersByTimeAsync(20);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('keeps reporting after a missing answer', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValue(missing());
        vi.stubGlobal('fetch', fetchMock);
        const onMerged = vi.fn();
        tracked(onMerged);

        seeing(1);
        await vi.advanceTimersByTimeAsync(20);
        await flushPromises();
        seeing(2);
        await vi.advanceTimersByTimeAsync(20);

        expect(onMerged).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});

describe('Moderation menus on a merged discussion', () => {
    it('a quick action tells the page where it went', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(gone()));
        const wrapper = mount(DiscussionModeration, {
            props: { discussion, moderation },
            global: listening(),
            attachTo: document.body,
        });

        wrapper.findComponent({ name: 'ModerationMenu' }).vm.$emit('select', 'pin');
        await flushPromises();

        expect(heard).toEqual([MERGED]);
        expect(wrapper.text()).toContain(MESSAGE);
        expect(router.reload).not.toHaveBeenCalled();
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('merging tells the page where it went', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(gone()));
        const wrapper = mount(DiscussionModeration, {
            props: { discussion, moderation },
            global: listening(),
            attachTo: document.body,
        });

        wrapper
            .findComponent({ name: 'MergeDialog' })
            .vm.$emit('confirm', { target: 'd-9', reason: '' });
        await flushPromises();

        expect(heard).toEqual([MERGED]);
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('looking up a target tells the page where it went', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(gone()));
        const wrapper = mount(MergeDialog, {
            props: {
                modelValue: false,
                lookupUrl: '/foro/temas/d-1/unir/destino',
                reasonMax: 500,
                busy: false,
                error: null,
            },
            global: listening(),
            attachTo: document.body,
        });
        await wrapper.setProps({ modelValue: true });

        const input = element<HTMLInputElement>('merge-target');
        input.value = '9';
        input.dispatchEvent(new Event('input'));
        element<HTMLButtonElement>('merge-lookup').click();
        await vi.waitFor(() => expect(heard).toEqual([MERGED]));
        expect(document.body.textContent).toContain(MESSAGE);
    });

    it('splitting tells the page where it went', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(gone()));
        const wrapper = mount(SplitPosts, {
            props: { moderation, numbers: [2] },
            global: listening(),
            attachTo: document.body,
        });

        element<HTMLButtonElement>('split-continue').click();
        await nextTick();
        await nextTick();
        const title = element<HTMLInputElement>('moderation-title');
        title.value = 'Aparte';
        title.dispatchEvent(new Event('input'));
        await nextTick();
        element<HTMLInputElement>('moderation-confirmation').click();
        await nextTick();
        element<HTMLButtonElement>('moderation-submit')
            .closest('form')
            ?.dispatchEvent(new Event('submit'));

        await vi.waitFor(() => expect(heard).toEqual([MERGED]));
        expect(wrapper.emitted('done')).toBeUndefined();
        expect(router.visit).not.toHaveBeenCalled();
    });
});
