import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ForumMenuPulse from '../../../resources/js/components/live/ForumMenuPulse.vue';
import { signIn } from '../support/page';

function serve(unreadFollowed: number): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(
        async () =>
            new Response(JSON.stringify({ unreadFollowed, discussions: {} }), {
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

beforeEach(() => {
    vi.useFakeTimers();
    // Waits are spread ±10 %: the middle of the spread makes them exact
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    document.body.innerHTML =
        '<header><nav><a href="/foro" id="menu">Foro</a><a href="/eventos">Eventos</a></nav></header>';
});

afterEach(() => {
    vi.restoreAllMocks();
    delete document.documentElement.dataset.forumUnread;
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('ForumMenuPulse', () => {
    it('asks nothing without a session', async () => {
        const fetchMock = serve(3);
        mount(ForumMenuPulse);

        await tick(180_000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.documentElement.dataset.forumUnread).toBeUndefined();
    });

    it('asks at once, marks the «Foro» link and describes it for screen readers', async () => {
        signIn();
        const fetchMock = serve(3);
        const wrapper = mount(ForumMenuPulse);

        await tick(0);

        expect(fetchMock).toHaveBeenCalledWith('/foro/novedades', expect.anything());
        expect(document.documentElement.dataset.forumUnread).toBe('');
        const status = wrapper.get('[role="status"]');
        expect(status.attributes('aria-live')).toBe('polite');
        expect(status.text()).toBe('Hay 3 temas sin leer entre los que sigues en el foro');
        expect(document.getElementById('menu')?.getAttribute('aria-describedby')).toBe(
            status.attributes('id')
        );
        // Other links are left alone
        expect(document.querySelector('a[href="/eventos"]')?.hasAttribute('aria-describedby')).toBe(
            false
        );
    });

    it('asks again every minute and clears the mark when nothing is left', async () => {
        signIn();
        const fetchMock = serve(2);
        const wrapper = mount(ForumMenuPulse);
        await tick(0);
        expect(document.documentElement.dataset.forumUnread).toBe('');

        serve(0);
        await tick(59_000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await tick(2_000);

        expect(document.documentElement.dataset.forumUnread).toBeUndefined();
        expect(wrapper.get('[role="status"]').text()).toBe('');
        expect(document.getElementById('menu')?.hasAttribute('aria-describedby')).toBe(false);
    });

    it('says «many» instead of a number at the cap', async () => {
        signIn();
        serve(100);
        const wrapper = mount(ForumMenuPulse);

        await tick(0);

        expect(wrapper.get('[role="status"]').text()).toBe(
            'Hay muchos temas sin leer entre los que sigues en el foro'
        );
    });

    it('leaves the page clean when it goes away', async () => {
        signIn();
        serve(1);
        const wrapper = mount(ForumMenuPulse);
        await tick(0);

        wrapper.unmount();

        expect(document.documentElement.dataset.forumUnread).toBeUndefined();
        expect(document.getElementById('menu')?.hasAttribute('aria-describedby')).toBe(false);
    });
});
