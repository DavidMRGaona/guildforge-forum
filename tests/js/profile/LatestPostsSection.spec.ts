import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LatestPostsSection from '../../../resources/js/components/profile/LatestPostsSection.vue';
import type { ForumProfilePost } from '../../../resources/js/types/forum';

function post(overrides: Partial<ForumProfilePost> = {}): ForumProfilePost {
    return {
        title: 'Partida del sábado',
        url: '/foro/t/42-partida-del-sabado/7',
        category: 'General',
        categoryUrl: '/foro/c/general',
        excerpt: 'Llevo los dados',
        publishedAt: '2026-11-10T18:00:00+01:00',
        publishedLabel: '10/11/2026 18:00',
        ...overrides,
    };
}

describe('LatestPostsSection', () => {
    it('lists each message with its topic linking to the message, its category, date and excerpt', () => {
        const wrapper = mount(LatestPostsSection, {
            props: {
                posts: [post(), post({ title: 'Presentaciones', url: '/foro/t/3-presentaciones' })],
            },
        });

        const items = wrapper.findAll('li');
        expect(items).toHaveLength(2);

        const links = items[0].findAll('a');
        expect(links[0].text()).toBe('Partida del sábado');
        expect(links[0].attributes('href')).toBe('/foro/t/42-partida-del-sabado/7');
        expect(links[1].text()).toBe('General');
        expect(links[1].attributes('href')).toBe('/foro/c/general');
        expect(items[0].find('time').attributes('datetime')).toBe('2026-11-10T18:00:00+01:00');
        expect(items[0].find('time').text()).toBe('10/11/2026 18:00');
        expect(items[0].text()).toContain('Llevo los dados');
        expect(items[1].find('a').attributes('href')).toBe('/foro/t/3-presentaciones');
    });

    it('draws the excerpt as text, never as HTML', () => {
        const wrapper = mount(LatestPostsSection, {
            props: { posts: [post({ excerpt: '<img src=x onerror=alert(1)> <b>negrita</b>' })] },
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)> <b>negrita</b>');
    });

    it('leaves out an empty excerpt', () => {
        const wrapper = mount(LatestPostsSection, { props: { posts: [post({ excerpt: '' })] } });

        expect(wrapper.find('[data-testid="profile-post-excerpt"]').exists()).toBe(false);
    });
});
