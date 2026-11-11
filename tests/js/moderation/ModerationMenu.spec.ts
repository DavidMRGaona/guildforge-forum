import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ModerationMenu from '../../../resources/js/components/moderation/ModerationMenu.vue';

const items = [
    { key: 'pin', label: 'Fijar' },
    { key: 'delete', label: 'Borrar', danger: true },
];

describe('ModerationMenu', () => {
    it('opens a list of actions from one button and tells which was chosen', async () => {
        const wrapper = mount(ModerationMenu, {
            props: { items, label: 'Moderar' },
            attachTo: document.body,
        });
        const toggle = wrapper.get('[data-testid="moderation-toggle"]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');

        await wrapper.get('[data-testid="moderation-delete"]').trigger('click');

        expect(wrapper.emitted('select')).toEqual([['delete']]);
        expect(toggle.attributes('aria-expanded')).toBe('false');
    });

    it('closes with Escape', async () => {
        const wrapper = mount(ModerationMenu, {
            props: { items, label: 'Moderar' },
            attachTo: document.body,
        });
        await wrapper.get('[data-testid="moderation-toggle"]').trigger('click');

        await wrapper.get('[data-testid="moderation-pin"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.get('[data-testid="moderation-toggle"]').attributes('aria-expanded')).toBe(
            'false'
        );
        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('draws nothing without actions', () => {
        const wrapper = mount(ModerationMenu, { props: { items: [], label: 'Moderar' } });

        expect(wrapper.html()).toBe('<!--v-if-->');
    });
});
