import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DiscussionList from '../../../resources/js/components/forum/DiscussionList.vue';
import type { ForumDiscussionList, ForumDiscussionRow } from '../../../resources/js/types/forum';
import { signIn } from '../support/page';

function row(id: string, lastPostNumber: number): ForumDiscussionRow {
    return {
        id,
        number: 42,
        title: `Tema ${id}`,
        categoryId: 'category-1',
        url: `/foro/t/42-${id}`,
        resumeUrl: null,
        category: null,
        authorName: 'Ana',
        topics: [],
        replyCount: 1,
        lastPost: { authorName: 'Bruno', at: null, atLabel: '10/11/2026 18:00', url: null },
        lastPostNumber,
        isPinned: false,
        isClosed: false,
        isHidden: false,
        isUnread: false,
        unreadCount: 0,
    };
}

const LIST: ForumDiscussionList = {
    pinned: [row('fixed', 3)],
    items: [row('a', 2), row('b', 5)],
    nextUrl: null,
    firstUrl: null,
};

function serve(discussions: Record<string, number>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(
        async () =>
            new Response(JSON.stringify({ unreadFollowed: 0, discussions }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            })
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function tick(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
}

const mountList = (list: ForumDiscussionList = LIST): ReturnType<typeof mount> =>
    mount(DiscussionList, { props: { discussions: list, emptyDescription: 'Nada' } });

beforeEach(() => {
    vi.useFakeTimers();
    // Waits are spread ±10 %: the middle of the spread makes them exact
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    vi.mocked(router.reload).mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('DiscussionList, while it stays open', () => {
    it('asks nothing without a session', async () => {
        const fetchMock = serve({});
        mountList();

        await tick(180_000);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('asks every minute about the discussions it shows, not at once', async () => {
        signIn();
        const fetchMock = serve({});
        mountList();

        await tick(30_000);
        expect(fetchMock).not.toHaveBeenCalled();
        await tick(31_000);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(
            '/foro/novedades?temas=fixed,a,b',
            expect.anything()
        );
    });

    it('marks the discussions with a newer last post and says so politely', async () => {
        signIn();
        serve({ fixed: 3, a: 4, b: 5 });
        const wrapper = mountList();

        const region = wrapper.get('[data-testid="list-changes"]');
        expect(region.attributes('role')).toBe('status');
        expect(region.attributes('aria-live')).toBe('polite');
        expect(region.text()).toBe('');

        await tick(61_000);

        expect(wrapper.findAll('[data-testid="discussion-changed"]')).toHaveLength(1);
        expect(wrapper.get('li:has([data-testid="discussion-changed"])').text()).toContain(
            'Tema a'
        );
        expect(region.text()).toContain('Un tema tiene novedades');
    });

    it('redraws the list only when the reader asks', async () => {
        signIn();
        serve({ b: 9 });
        const wrapper = mountList();
        await tick(61_000);
        expect(router.reload).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="list-changes"] button').trigger('click');

        expect(router.reload).toHaveBeenCalledWith({ only: ['discussions'] });
    });

    it('marks nothing when the forum has nothing newer', async () => {
        signIn();
        serve({ fixed: 3, a: 2, b: 5 });
        const wrapper = mountList();

        await tick(61_000);

        expect(wrapper.find('[data-testid="discussion-changed"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="list-changes"]').text()).toBe('');
    });

    it('asks nothing for an empty list', async () => {
        signIn();
        const fetchMock = serve({});
        mountList({ pinned: [], items: [], nextUrl: null, firstUrl: null });

        await tick(180_000);

        expect(fetchMock).not.toHaveBeenCalled();
    });
});
