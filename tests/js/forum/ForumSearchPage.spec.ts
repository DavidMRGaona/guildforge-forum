import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Search from '../../../resources/js/pages/Forum/Search.vue';
import type { ForumSearchResults } from '../../../resources/js/types/forum';

const RESULTS: ForumSearchResults = {
    total: 2,
    page: 1,
    truncated: false,
    prevUrl: null,
    nextUrl: '/foro/buscar?q=dados&pagina=2',
    items: [
        {
            kind: 'discussion',
            title: 'Partida del viernes',
            url: '/foro/t/1-partida-del-viernes',
            categoryName: 'General',
            categoryUrl: '/foro/c/general',
            postNumber: null,
            snippet: [
                { text: 'Traed los ', match: false },
                { text: 'dados', match: true },
                { text: ' de seis caras', match: false },
            ],
            publishedAt: '2026-11-10T18:00:00+01:00',
            publishedLabel: '10/11/2026 18:00',
        },
        {
            kind: 'post',
            title: 'Partida del viernes',
            url: '/foro/t/1-partida-del-viernes/2',
            categoryName: null,
            categoryUrl: null,
            postNumber: 2,
            snippet: [],
            publishedAt: null,
            publishedLabel: null,
        },
    ],
};

const BASE = {
    query: 'dados',
    category: null,
    categories: [{ slug: 'general', name: 'General', depth: 0 }],
    searchUrl: '/foro/buscar',
    failed: false,
};

describe('Forum search page', () => {
    it('lists the results with the matches marked and their place in the forum', () => {
        const wrapper = mount(Search, { props: { ...BASE, results: RESULTS } });

        expect(wrapper.get('h2').text()).toBe('2 resultados');
        const items = wrapper.findAll('[data-testid="forum-search-results"] > li');
        expect(items).toHaveLength(2);
        expect(items[0]?.get('a[href="/foro/t/1-partida-del-viernes"]').text()).toBe(
            'Partida del viernes'
        );
        expect(items[0]?.get('mark').text()).toBe('dados');
        expect(items[0]?.text()).toContain('Tema');
        expect(items[0]?.get('a[href="/foro/c/general"]').text()).toBe('General');
        expect(items[0]?.get('time').text()).toBe('10/11/2026 18:00');
        expect(items[1]?.text()).toContain('Mensaje n.º 2');
        expect(wrapper.get('a[rel="next"]').attributes('href')).toBe(
            '/foro/buscar?q=dados&pagina=2'
        );
        expect(wrapper.find('a[rel="prev"]').exists()).toBe(false);
    });

    it('says when nothing was found', () => {
        const wrapper = mount(Search, {
            props: { ...BASE, results: { ...RESULTS, items: [], total: 0, nextUrl: null } },
        });

        expect(wrapper.text()).toContain('No se ha encontrado nada');
        expect(wrapper.find('[data-testid="forum-search-results"]').exists()).toBe(false);
    });

    it('invites to type before there is a query', () => {
        const wrapper = mount(Search, { props: { ...BASE, query: '', results: null } });

        expect(wrapper.text()).toContain('Escribe qué quieres encontrar');
    });

    it('announces a failing search instead of showing an empty list', () => {
        const wrapper = mount(Search, { props: { ...BASE, failed: true, results: null } });

        expect(wrapper.get('[role="alert"]').text()).toContain('no está disponible');
    });

    it('warns when there are more matches than shown', () => {
        const wrapper = mount(Search, {
            props: { ...BASE, results: { ...RESULTS, truncated: true } },
        });

        expect(wrapper.text()).toContain('Hay más coincidencias de las que se muestran');
    });
});
