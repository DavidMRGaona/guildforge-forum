import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
        expect(wrapper.get('select[name="categoria"]').exists()).toBe(true);
    });

    it('searches the text in the chosen category', async () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', query: 'dados', categories: CATEGORIES },
        });

        await wrapper.get('select').setValue('rol');
        await wrapper.get('form').trigger('submit');

        expect(router.get).toHaveBeenCalledWith('/foro/buscar', { q: 'dados', categoria: 'rol' });
    });

    it('starts with the category it is given and offers subcategories under their parent', () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', category: 'rol', categories: CATEGORIES },
        });

        expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('rol');
        expect(wrapper.findAll('option').map((option) => option.text())).toEqual([
            'Todas las categorías',
            'General',
            '— Rol',
        ]);
    });

    it('drops an empty text and the category filter when there is none', async () => {
        const wrapper = mount(ForumSearchForm, {
            props: { action: '/foro/buscar', query: '  ', categories: [] },
        });

        expect(wrapper.find('select').exists()).toBe(false);
        await wrapper.get('form').trigger('submit');

        expect(router.get).toHaveBeenCalledWith('/foro/buscar', {});
    });
});
