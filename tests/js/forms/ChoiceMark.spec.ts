import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChoiceMark from '../../../resources/js/components/forms/ChoiceMark.vue';

describe('ChoiceMark', () => {
    it('draws a hidden box with its tick only when checked', async () => {
        const wrapper = mount(ChoiceMark, { props: { checked: false } });

        expect(wrapper.attributes('aria-hidden')).toBe('true');
        expect(wrapper.classes()).toContain('rounded-md');
        expect(wrapper.find('[data-mark]').classes()).toContain('opacity-0');

        await wrapper.setProps({ checked: true });

        expect(wrapper.classes()).toContain('bg-primary-600');
        expect(wrapper.find('[data-mark]').classes()).toContain('opacity-100');
    });

    it('draws a round mark with a dot for a radio', async () => {
        const wrapper = mount(ChoiceMark, { props: { checked: true, type: 'radio' } });

        expect(wrapper.classes()).toContain('rounded-full');
        expect(wrapper.find('[data-mark]').classes()).toContain('rounded-full');
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it('shows the focus of the hidden input right before it', () => {
        const wrapper = mount(ChoiceMark, { props: { checked: false } });

        expect(wrapper.classes()).toContain('peer-focus-visible:ring-2');
        expect(wrapper.classes()).toContain('peer-disabled:opacity-50');
    });
});
