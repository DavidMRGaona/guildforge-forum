import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FormSelect } from '@/components/form';
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

        await wrapper.findAll('[role="group"] button')[1]?.trigger('click');

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

        const following = wrapper.findAll('[role="group"] button')[2];
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
            .findAll('[role="group"] button')
            .map((button) => button.attributes('aria-pressed'));
        expect(pressed).toEqual(['false', 'false', 'true']);
    });

    it('filters by tag from the first page', async () => {
        const wrapper = mount(DiscussionFilters, { props: { filters: FILTERS } });

        const select = wrapper.getComponent(FormSelect);
        expect(wrapper.find('select').exists()).toBe(false);
        expect(select.props('placeholder')).toBe('Todas');
        expect(select.props('options')).toEqual([{ value: 'game-system.x', label: 'Pathfinder' }]);
        select.vm.$emit('update:modelValue', 'game-system.x');

        expect(router.get).toHaveBeenCalledWith(
            '/foro/c/general',
            { etiqueta: 'game-system.x' },
            expect.anything()
        );
    });

    it('drops the tag when «Todas» is chosen again', () => {
        const wrapper = mount(DiscussionFilters, {
            props: { filters: { ...FILTERS, topic: 'game-system.x' } },
        });

        expect(wrapper.getComponent(FormSelect).props('modelValue')).toBe('game-system.x');
        wrapper.getComponent(FormSelect).vm.$emit('update:modelValue', '');

        expect(router.get).toHaveBeenCalledWith('/foro/c/general', {}, expect.anything());
    });

    it('offers no unread filter without a session', () => {
        const wrapper = mount(DiscussionFilters, {
            props: { filters: { ...FILTERS, canFilterUnread: false } },
        });

        expect(wrapper.find('[role="group"]').exists()).toBe(false);
        expect(wrapper.findComponent(FormSelect).exists()).toBe(true);
    });
});
