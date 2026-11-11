import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import MentionList from '../../../resources/js/editor/MentionList.vue';

const PEOPLE = [
    { id: 'a', name: 'Ana', avatarUrl: null },
    { id: 'b', name: 'Bruno', avatarUrl: 'https://example.com/b.png' },
];

describe('MentionList', () => {
    it('moves with the arrow keys and picks with Enter', () => {
        const command = vi.fn();
        const wrapper = mount(MentionList, { props: { items: PEOPLE, query: 'a', command } });
        const list = wrapper.vm as unknown as { onKeyDown: (event: KeyboardEvent) => boolean };

        expect(wrapper.findAll('[role="option"]')[0]?.attributes('aria-selected')).toBe('true');
        expect(list.onKeyDown(new KeyboardEvent('keydown', { key: 'ArrowDown' }))).toBe(true);
        expect(list.onKeyDown(new KeyboardEvent('keydown', { key: 'Enter' }))).toBe(true);

        expect(command).toHaveBeenCalledWith(PEOPLE[1]);
    });

    it('says so when nobody matches', () => {
        const wrapper = mount(MentionList, { props: { items: [], query: 'zz', command: vi.fn() } });

        expect(wrapper.find('[role="status"]').text()).toBe(
            'Nadie con ese nombre puede ver este tema'
        );
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });
});
