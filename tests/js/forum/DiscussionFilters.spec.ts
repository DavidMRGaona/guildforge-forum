import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DiscussionFilters from '../../../resources/js/components/forum/DiscussionFilters.vue';
import type { ForumListFilters } from '../../../resources/js/types/forum';

const FILTERS: ForumListFilters = {
    topic: null,
    unread: false,
    following: false,
    topics: [{ key: 'game-system.x', label: 'Pathfinder' }],
    canFilterUnread: true,
    baseUrl: '/foro/c/general',
};

describe('DiscussionFilters', () => {
    beforeEach(() => {
        vi.mocked(router.get).mockClear();
    });

    it('loads the unread discussions keeping the tag', async () => {
        const wrapper = mount(DiscussionFilters, {
            props: { filters: { ...FILTERS, topic: 'game-system.x' } },
        });

        await wrapper.findAll('button')[1]?.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/foro/c/general',
            { 'sin-leer': '1', etiqueta: 'game-system.x' },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('loads the followed discussions instead of the unread ones', async () => {
        const wrapper = mount(DiscussionFilters, {
            props: { filters: { ...FILTERS, unread: true } },
        });

        const following = wrapper.findAll('button')[2];
        expect(following?.text()).toBe('Siguiendo');
        await following?.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/foro/c/general',
            { siguiendo: '1' },
            expect.anything()
        );
    });

    it('marks the shown filter as pressed', () => {
        const wrapper = mount(DiscussionFilters, {
            props: { filters: { ...FILTERS, following: true } },
        });

        const pressed = wrapper
            .findAll('button')
            .map((button) => button.attributes('aria-pressed'));
        expect(pressed).toEqual(['false', 'false', 'true']);
    });

    it('filters by tag from the first page', async () => {
        const wrapper = mount(DiscussionFilters, { props: { filters: FILTERS } });

        await wrapper.find('select').setValue('game-system.x');

        expect(router.get).toHaveBeenCalledWith(
            '/foro/c/general',
            { etiqueta: 'game-system.x' },
            expect.anything()
        );
    });

    it('offers no unread filter without a session', () => {
        const wrapper = mount(DiscussionFilters, {
            props: { filters: { ...FILTERS, canFilterUnread: false } },
        });

        expect(wrapper.find('[role="group"]').exists()).toBe(false);
        expect(wrapper.find('select').exists()).toBe(true);
    });
});
