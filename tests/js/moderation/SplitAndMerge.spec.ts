import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import MovedPostsNotice from '../../../resources/js/components/forum/MovedPostsNotice.vue';
import MergeDialog from '../../../resources/js/components/moderation/MergeDialog.vue';
import SplitPosts from '../../../resources/js/components/moderation/SplitPosts.vue';
import {
    describeNumbers,
    useSplitSelection,
} from '../../../resources/js/composables/useSplitSelection';
import type { ForumDiscussionModeration } from '../../../resources/js/types/forum';

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
    window.sessionStorage.clear();
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.mocked(router.visit).mockClear();
});

function element<T extends HTMLElement>(testId: string): T {
    const found = document.body.querySelector<T>(`[data-testid="${testId}"]`);
    if (found === null) {
        throw new Error(`No ${testId}`);
    }

    return found;
}

function type(testId: string, value: string): void {
    const input = element<HTMLInputElement>(testId);
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

function answer(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status });
}

const moderation: ForumDiscussionModeration = {
    queueUrl: '/foro/moderacion',
    suspendUrl: null,
    actionsUrl: '/foro/temas/d-1/moderacion',
    deleteUrl: null,
    moveTargets: [],
    splitUrl: '/foro/temas/d-1/separar',
    mergeUrl: '/foro/temas/d-1/unir',
    mergeTargetUrl: '/foro/temas/d-1/unir/destino',
    splitCategories: [
        { id: 'c-own', name: 'General', parentName: null },
        { id: 'c-other', name: 'Otra', parentName: null },
    ],
    limits: { titleMin: 3, titleMax: 120, reasonMax: 500, hiddenReasonMax: 300, splitMax: 500 },
};

describe('useSplitSelection', () => {
    it('keeps the chosen numbers in order for the tab, per discussion', () => {
        const selection = useSplitSelection('d-1');
        selection.start();
        selection.toggle(7);
        selection.toggle(3);
        selection.toggle(5);
        selection.toggle(7);

        expect(selection.numbers.value).toEqual([3, 5]);
        // Another page of the same discussion finds the choice
        const again = useSplitSelection('d-1');
        expect(again.active.value).toBe(true);
        expect(again.numbers.value).toEqual([3, 5]);
        expect(useSplitSelection('d-2').active.value).toBe(false);

        again.cancel();
        expect(useSplitSelection('d-1').numbers.value).toEqual([]);
    });

    it('ignores what it cannot read', () => {
        window.sessionStorage.setItem('forum-split-d-1', '{"active":true,"numbers":[1,"x",4]}');
        expect(useSplitSelection('d-1').numbers.value).toEqual([4]);

        window.sessionStorage.setItem('forum-split-d-1', 'not json');
        expect(useSplitSelection('d-1').active.value).toBe(false);
    });

    it('describes runs of numbers', () => {
        expect(describeNumbers([7, 3, 5, 6, 9])).toBe('3, 5–7, 9');
        expect(describeNumbers([])).toBe('');
    });
});

describe('MovedPostsNotice', () => {
    it('links to where the posts went, or says only that they moved', () => {
        const linked = mount(MovedPostsNotice, {
            props: {
                moved: {
                    fromNumber: 5,
                    throughNumber: 6,
                    count: 2,
                    title: 'Otro asunto',
                    url: '/foro/t/2-otro-asunto/2',
                },
            },
        });
        expect(linked.text()).toContain('2 mensajes movidos a');
        expect(linked.text()).toContain('n.º 5–6');
        expect(linked.get('a').attributes('href')).toBe('/foro/t/2-otro-asunto/2');

        const hidden = mount(MovedPostsNotice, {
            props: { moved: { fromNumber: 3, throughNumber: 3, count: 1, title: null, url: null } },
        });
        expect(hidden.text()).toContain('Un mensaje movido a otro tema');
        expect(hidden.find('a').exists()).toBe(false);
    });
});

describe('SplitPosts', () => {
    it('asks for the box and sends the chosen posts to the new discussion', async () => {
        const fetch = vi.fn().mockResolvedValue(answer({ url: '/foro/t/2-otro-asunto' }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(SplitPosts, {
            props: { moderation, numbers: [3, 5, 6] },
            attachTo: document.body,
        });

        expect(wrapper.text()).toContain('3 mensajes elegidos');
        element<HTMLButtonElement>('split-continue').click();
        await nextTick();
        await nextTick();

        // Its own category first
        expect(element<HTMLSelectElement>('moderation-category').value).toBe('c-own');
        expect(document.body.textContent).toContain('n.º 3, 5–6');
        type('moderation-title', 'Otro asunto');
        await nextTick();
        expect(element<HTMLButtonElement>('moderation-submit').disabled).toBe(true);

        element<HTMLInputElement>('moderation-confirmation').click();
        await nextTick();
        element<HTMLButtonElement>('moderation-submit')
            .closest('form')
            ?.dispatchEvent(new Event('submit'));
        await vi.waitFor(() => expect(router.visit).toHaveBeenCalledWith('/foro/t/2-otro-asunto'));

        expect(fetch).toHaveBeenCalledWith(
            '/foro/temas/d-1/separar',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({
                    numbers: [3, 5, 6],
                    title: 'Otro asunto',
                    category: 'c-own',
                    confirmed: true,
                }),
            })
        );
        expect(wrapper.emitted('done')).toHaveLength(1);
    });

    it('keeps its text and buttons clear of a floating button in the bottom left corner', () => {
        // Cookie consent's button sits fixed at 1rem from the left, 3rem wide: below lg the bar's
        // content starts after it, from lg the centred column already does
        const wrapper = mount(SplitPosts, { props: { moderation, numbers: [] } });
        const content = wrapper.find('[data-testid="split-bar-content"]').classes();

        expect(content).toContain('pl-20');
        expect(content).toContain('lg:pl-8');
        expect(content).not.toContain('px-4');
        expect(content).not.toContain('sm:px-6');
    });

    it('cannot continue with nothing chosen', () => {
        const wrapper = mount(SplitPosts, { props: { moderation, numbers: [] } });

        expect(wrapper.get('[data-testid="split-continue"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Ningún mensaje elegido');
    });
});

describe('MergeDialog', () => {
    async function opened(): Promise<ReturnType<typeof mount>> {
        const wrapper = mount(MergeDialog, {
            props: {
                modelValue: false,
                lookupUrl: '/foro/temas/d-1/unir/destino',
                reasonMax: 500,
                busy: false,
                error: null,
            },
            attachTo: document.body,
        });
        await wrapper.setProps({ modelValue: true });

        return wrapper;
    }

    it('merges only into the discussion the server found, once the box is ticked', async () => {
        const fetch = vi.fn().mockResolvedValue(
            answer({
                discussion: {
                    id: 'd-9',
                    number: 9,
                    title: 'Destino',
                    url: '/foro/t/9-destino',
                    categoryName: 'General',
                },
            })
        );
        vi.stubGlobal('fetch', fetch);
        const wrapper = await opened();

        expect(element<HTMLButtonElement>('merge-submit').disabled).toBe(true);
        type('merge-target', '/foro/t/9-destino');
        element<HTMLButtonElement>('merge-lookup').click();
        await vi.waitFor(() => expect(element('merge-found').textContent).toContain('«Destino»'));

        expect(fetch.mock.calls[0]?.[0]).toBe(
            '/foro/temas/d-1/unir/destino?tema=%2Fforo%2Ft%2F9-destino'
        );
        expect(element<HTMLButtonElement>('merge-submit').disabled).toBe(true);

        element<HTMLInputElement>('merge-confirmation').click();
        await nextTick();
        element<HTMLButtonElement>('merge-submit')
            .closest('form')
            ?.dispatchEvent(new Event('submit'));

        expect(wrapper.emitted('confirm')).toEqual([[{ target: 'd-9', reason: '' }]]);
    });

    it('says why nothing was found and forgets a target once the text changes', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    answer({
                        discussion: {
                            id: 'd-9',
                            number: 9,
                            title: 'Destino',
                            url: '/x',
                            categoryName: null,
                        },
                    })
                )
                .mockResolvedValueOnce(answer({ message: 'No hay ningún tema' }, 422))
        );
        await opened();

        type('merge-target', '9');
        element<HTMLButtonElement>('merge-lookup').click();
        await vi.waitFor(() => element('merge-found'));
        type('merge-target', '99');
        await nextTick();
        expect(document.body.querySelector('[data-testid="merge-found"]')).toBeNull();

        element<HTMLButtonElement>('merge-lookup').click();
        await vi.waitFor(() => expect(document.body.textContent).toContain('No hay ningún tema'));
        expect(element<HTMLButtonElement>('merge-submit').disabled).toBe(true);
    });
});
