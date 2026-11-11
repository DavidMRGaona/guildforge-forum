import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import FollowSelect from '../../../resources/js/components/forum/FollowSelect.vue';

function answer(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('FollowSelect', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('saves the level as soon as it changes', async () => {
        const fetch = vi.fn().mockResolvedValue(answer({ level: 'muted' }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(FollowSelect, {
            props: {
                follow: { level: 'normal', url: '/foro/temas/d-1/seguimiento' },
                discussionId: 'd-1',
            },
        });

        expect(wrapper.get('label').text()).toBe('Avisos de este tema');
        await wrapper.get('select').setValue('muted');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/temas/d-1/seguimiento',
            expect.objectContaining({ method: 'PUT', body: JSON.stringify({ level: 'muted' }) })
        );
        expect(wrapper.text()).toContain('Guardado');
    });

    it('goes back to the previous level when saving fails', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(answer({}, 500)));
        const wrapper = mount(FollowSelect, {
            props: {
                follow: { level: 'following', url: '/foro/temas/d-1/seguimiento' },
                discussionId: 'd-1',
            },
        });

        await wrapper.get('select').setValue('normal');
        await flushPromises();

        expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('following');
        expect(wrapper.get('[role="alert"]').text()).toBe(
            'No se ha podido guardar. Inténtalo de nuevo.'
        );
    });
});
