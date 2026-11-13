import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ForumPreferences from '../../../resources/js/components/profile/ForumPreferences.vue';

function answer(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const PREFERENCES = {
    autoFollow: true,
    updateUrl: '/foro/preferencias',
    categories: [],
    discussions: [],
    forumUrl: '/foro',
};

async function mounted(
    save: Response
): Promise<{ wrapper: ReturnType<typeof mount>; fetch: ReturnType<typeof vi.fn> }> {
    const fetch = vi.fn((url: string, init?: RequestInit) =>
        Promise.resolve(
            init?.method === 'PUT'
                ? save
                : url === '/foro/preferencias'
                  ? answer(PREFERENCES)
                  : answer({ blocks: [] })
        )
    );
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(ForumPreferences, { global: { stubs: { ForumBlocks: true } } });
    await flushPromises();

    return { wrapper, fetch };
}

describe('ForumPreferences', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('draws the site’s box over the native one, which keeps its hint', async () => {
        const { wrapper } = await mounted(answer({ autoFollow: false }));

        const box = wrapper.get('input[type="checkbox"]');
        expect(box.classes()).toContain('sr-only');
        expect((box.element as HTMLInputElement).checked).toBe(true);
        const hint = wrapper.get(`#${box.attributes('aria-describedby')}`);
        expect(hint.text()).toContain('Te avisamos de cada respuesta nueva');
        expect(box.element.nextElementSibling?.getAttribute('aria-hidden')).toBe('true');
        expect(box.element.closest('label')?.textContent).toContain(
            'Seguir los temas que abro o en los que respondo'
        );
    });

    it('saves the choice as soon as it changes', async () => {
        const { wrapper, fetch } = await mounted(answer({ autoFollow: false }));

        await wrapper.get('input[type="checkbox"]').setValue(false);
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/preferencias',
            expect.objectContaining({ method: 'PUT', body: JSON.stringify({ autoFollow: false }) })
        );
        expect((wrapper.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(
            false
        );
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('puts the box back when saving fails', async () => {
        const { wrapper } = await mounted(answer({}, 500));

        await wrapper.get('input[type="checkbox"]').setValue(false);
        await flushPromises();

        const box = wrapper.get('input[type="checkbox"]');
        expect((box.element as HTMLInputElement).checked).toBe(true);
        expect(box.element.nextElementSibling?.className).toContain('bg-primary-600');
        expect(wrapper.get('[role="alert"]').text()).not.toBe('');
    });
});
