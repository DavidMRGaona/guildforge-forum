import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LikeButton from '../../../resources/js/components/forum/LikeButton.vue';
import type { ForumPostLikes } from '../../../resources/js/types/forum';

const LIKES: ForumPostLikes = {
    count: 2,
    liked: false,
    names: ['Ana', 'Luis'],
    others: 0,
    canLike: true,
    url: '/foro/mensajes/p-1/me-gusta',
};

function answer(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('LikeButton', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('gives a like and shows the new count', async () => {
        const fetch = vi.fn().mockResolvedValue(
            answer({
                likes: { count: 3, liked: true, names: ['Tú', 'Ana', 'Luis'], others: 0 },
            })
        );
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(LikeButton, {
            props: { likes: LIKES, postId: 'p-1', postNumber: 4 },
        });

        const like = wrapper.get('button[aria-pressed]');
        expect(like.attributes('aria-label')).toBe('Dar me gusta al mensaje 4');
        await like.trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/mensajes/p-1/me-gusta',
            expect.objectContaining({ method: 'POST' })
        );
        expect(wrapper.get('button[aria-pressed]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.text()).toContain('3');
    });

    it('takes the like back with another click', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(
                answer({ likes: { count: 1, liked: false, names: ['Ana'], others: 0 } })
            );
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(LikeButton, {
            props: { likes: { ...LIKES, liked: true }, postId: 'p-1', postNumber: 4 },
        });

        await wrapper.get('button[aria-pressed]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith(
            '/foro/mensajes/p-1/me-gusta',
            expect.objectContaining({ method: 'DELETE' })
        );
    });

    it('lists who liked it when the count is tapped', async () => {
        const wrapper = mount(LikeButton, {
            props: { likes: { ...LIKES, others: 3 }, postId: 'p-1', postNumber: 4 },
        });

        const count = wrapper.get('button[aria-expanded]');
        expect(count.attributes('aria-expanded')).toBe('false');
        await count.trigger('click');

        expect(count.attributes('aria-expanded')).toBe('true');
        const list = wrapper.get('#forum-likes-p-1');
        expect(list.text()).toContain('Ana');
        expect(list.text()).toContain('y 3 más');
    });

    it('shows only the count to whoever cannot like it', () => {
        const wrapper = mount(LikeButton, {
            props: { likes: { ...LIKES, canLike: false, url: null }, postId: 'p-1', postNumber: 4 },
        });

        expect(wrapper.find('button[aria-pressed]').exists()).toBe(false);
        expect(wrapper.get('button[aria-expanded]').text()).toContain('2');
    });

    it('says when the like could not be saved', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(answer({ message: 'No' }, 403)));
        const wrapper = mount(LikeButton, {
            props: { likes: LIKES, postId: 'p-1', postNumber: 4 },
        });

        await wrapper.get('button[aria-pressed]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('No');
        expect(wrapper.get('button[aria-pressed]').attributes('aria-pressed')).toBe('false');
    });
});
