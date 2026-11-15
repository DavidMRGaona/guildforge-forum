import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AuthorFilterNotice from '../../../resources/js/components/forum/AuthorFilterNotice.vue';

describe('AuthorFilterNotice', () => {
    it('says how many messages of whom it shows and links back to the whole discussion', () => {
        const wrapper = mount(AuthorFilterNotice, {
            props: { filter: { authorName: 'Ana', count: 13, clearUrl: '/foro/t/7-partida' } },
        });

        expect(wrapper.text()).toContain('Mostrando 13 mensajes de Ana');
        expect(wrapper.find('a').attributes('href')).toBe('/foro/t/7-partida');
        expect(wrapper.find('a').text()).toBe('Ver todo el tema');
    });

    it('uses the singular for one message', () => {
        const wrapper = mount(AuthorFilterNotice, {
            props: { filter: { authorName: 'Ana', count: 1, clearUrl: '/foro/t/7-partida' } },
        });

        expect(wrapper.text()).toContain('Mostrando un mensaje de Ana');
    });

    it('says the same for a person without messages as for one that does not exist', () => {
        const wrapper = mount(AuthorFilterNotice, {
            props: { filter: { authorName: null, count: 0, clearUrl: '/foro/t/7-partida' } },
        });

        expect(wrapper.text()).toContain('Este tema no tiene mensajes visibles de esa persona.');
        expect(wrapper.find('a').attributes('href')).toBe('/foro/t/7-partida');
    });
});
