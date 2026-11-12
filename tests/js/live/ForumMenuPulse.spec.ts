import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { clearMenuBadge, useMenuBadges } from '@/composables/useMenuBadges';
import ForumMenuPulse from '../../../resources/js/components/live/ForumMenuPulse.vue';
import { signIn } from '../support/page';

const KEY = 'forum:forum.index';

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

function badge(): string | undefined {
    return useMenuBadges().badges.value[KEY];
}

beforeEach(() => {
    vi.useFakeTimers();
    // Waits are spread ±10 %: the middle of the spread makes them exact
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
});

afterEach(() => {
    clearMenuBadge(KEY);
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('ForumMenuPulse', () => {
    it('asks nothing without a session', async () => {
        const fetchMock = serve(3);
        mount(ForumMenuPulse);

        await tick(180_000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(badge()).toBeUndefined();
    });

    it('asks at once, marks the «Foro» link and announces it politely', async () => {
        signIn();
        const fetchMock = serve(3);
        const wrapper = mount(ForumMenuPulse);

        await tick(0);

        expect(fetchMock).toHaveBeenCalledWith('/foro/novedades', expect.anything());
        expect(badge()).toBe('3 temas sin leer');
        const status = wrapper.get('[role="status"]');
        expect(status.attributes('aria-live')).toBe('polite');
        expect(status.text()).toBe('Hay 3 temas sin leer entre los que sigues en el foro');
    });

    it('uses the singular for one discussion', async () => {
        signIn();
        serve(1);
        mount(ForumMenuPulse);

        await tick(0);

        expect(badge()).toBe('un tema sin leer');
    });

    it('asks again every minute and clears the mark when nothing is left', async () => {
        signIn();
        const fetchMock = serve(2);
        const wrapper = mount(ForumMenuPulse);
        await tick(0);
        expect(badge()).toBe('2 temas sin leer');

        serve(0);
        await tick(59_000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await tick(2_000);

        expect(badge()).toBeUndefined();
        expect(wrapper.get('[role="status"]').text()).toBe('');
    });

    it('says «many» instead of a number at the cap', async () => {
        signIn();
        serve(100);
        const wrapper = mount(ForumMenuPulse);

        await tick(0);

        expect(badge()).toBe('muchos temas sin leer');
        expect(wrapper.get('[role="status"]').text()).toBe(
            'Hay muchos temas sin leer entre los que sigues en el foro'
        );
    });

    it('takes the mark off when it goes away', async () => {
        signIn();
        serve(1);
        const wrapper = mount(ForumMenuPulse);
        await tick(0);
        expect(badge()).toBe('un tema sin leer');

        wrapper.unmount();

        expect(badge()).toBeUndefined();
    });
});
