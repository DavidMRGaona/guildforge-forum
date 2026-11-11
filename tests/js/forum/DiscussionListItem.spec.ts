import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DiscussionListItem from '../../../resources/js/components/forum/DiscussionListItem.vue';
import type { ForumDiscussionRow } from '../../../resources/js/types/forum';

function row(overrides: Partial<ForumDiscussionRow> = {}): ForumDiscussionRow {
    return {
        id: 'd-1',
        number: 42,
        title: 'Mesa del viernes',
        url: '/foro/t/42-mesa-del-viernes',
        resumeUrl: null,
        category: { name: 'General', url: '/foro/c/general', color: null },
        authorName: 'Ana',
        topics: [{ key: 'game-system.x', label: 'Pathfinder' }],
        replyCount: 3,
        lastPost: {
            authorName: 'Bruno',
            at: '2026-11-10T18:00:00+01:00',
            atLabel: '10/11/2026 18:00',
            url: '/foro/t/42-mesa-del-viernes/4',
        },
        isPinned: false,
        isClosed: false,
        isHidden: false,
        isUnread: null,
        unreadCount: null,
        ...overrides,
    };
}

describe('DiscussionListItem', () => {
    it('shows the discussion, where it is and its last post', () => {
        const wrapper = mount(DiscussionListItem, {
            props: { discussion: row(), showCategory: true },
        });

        expect(wrapper.find('a').attributes('href')).toBe('/foro/t/42-mesa-del-viernes');
        expect(wrapper.text()).toContain('Abierto por Ana');
        expect(wrapper.text()).toContain('General');
        expect(wrapper.text()).toContain('Pathfinder');
        expect(wrapper.text()).toContain('Bruno');
        expect(wrapper.find('time').attributes('datetime')).toBe('2026-11-10T18:00:00+01:00');
    });

    it('marks unread discussions and resumes at the first unread post', () => {
        const wrapper = mount(DiscussionListItem, {
            props: {
                discussion: row({
                    isUnread: true,
                    unreadCount: 2,
                    resumeUrl: '/foro/t/42-mesa-del-viernes/3',
                }),
            },
        });

        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('/foro/t/42-mesa-del-viernes/3');
        expect(link.classes()).toContain('font-semibold');
        expect(link.text()).toContain('2 mensajes sin leer');
    });

    it('labels pinned, closed and hidden discussions', () => {
        const wrapper = mount(DiscussionListItem, {
            props: { discussion: row({ isPinned: true, isClosed: true, isHidden: true }) },
        });

        expect(wrapper.text()).toContain('Fijado');
        expect(wrapper.text()).toContain('Cerrado');
        expect(wrapper.text()).toContain('Oculto');
    });
});
