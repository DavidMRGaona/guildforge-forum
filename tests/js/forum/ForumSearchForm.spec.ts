import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FormSelect } from '@/components/form';
import ForumSearchForm from '../../../resources/js/components/forum/ForumSearchForm.vue';

const CATEGORIES = [
    { slug: 'general', name: 'General', depth: 0 },
    { slug: 'rol', name: 'Rol', depth: 1 },
];

describe('ForumSearchForm', () => {
    beforeEach(() => {
        vi.mocked(router.get).mockClear();
    });

    it('is a labelled search form that also works without scripts', () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', categories: CATEGORIES },
        });

        const form = wrapper.get('form');
        expect(form.attributes('role')).toBe('search');
        expect(form.attributes('method')).toBe('get');
        expect(form.attributes('action')).toBe('/foro/buscar');
        const input = wrapper.get('input[name="q"]');
        expect(wrapper.get(`label[for="${input.attributes('id')}"]`).text()).toBe(
            'Buscar en el foro'
        );
        expect(wrapper.find('select').exists()).toBe(false);
        // No category chosen: nothing to send
        expect(wrapper.get('input[type="hidden"][name="categoria"]').attributes()).toHaveProperty(
            'disabled'
        );
    });

    it('searches the text in the chosen category', async () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', query: 'dados', categories: CATEGORIES },
        });

        wrapper.getComponent(FormSelect).vm.$emit('update:modelValue', 'rol');
        await nextTick();
        const hidden = wrapper.get('input[type="hidden"][name="categoria"]');
        expect((hidden.element as HTMLInputElement).value).toBe('rol');
        expect(hidden.attributes()).not.toHaveProperty('disabled');
        await wrapper.get('form').trigger('submit');

        expect(router.get).toHaveBeenCalledWith('/foro/buscar', { q: 'dados', categoria: 'rol' });
    });

    it('starts with the category it is given and offers subcategories under their parent', () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', category: 'rol', categories: CATEGORIES },
        });

        const select = wrapper.getComponent(FormSelect);
        expect(select.props('modelValue')).toBe('rol');
        expect(select.props('id')).toBe('forum-search-category');
        expect(select.props('placeholder')).toBe('Todas las categorías');
        expect(select.props('options')).toEqual([
            { value: 'general', label: 'General' },
            { value: 'rol', label: '— Rol' },
        ]);
    });

    it('drops an empty text and the category filter when there is none', async () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', query: '  ', categories: [] },
        });

        expect(wrapper.findComponent(FormSelect).exists()).toBe(false);
        expect(wrapper.find('input[name="categoria"]').exists()).toBe(false);
        await wrapper.get('form').trigger('submit');

        expect(router.get).toHaveBeenCalledWith('/foro/buscar', {});
    });
});
