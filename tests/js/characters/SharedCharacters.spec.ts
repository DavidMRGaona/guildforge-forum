import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import SharedCharacters from '../../../resources/js/components/characters/SharedCharacters.vue';
import type { ForumSharedCharacters } from '../../../resources/js/types/forum';

beforeAll(() => {
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

function aList(overrides: Partial<ForumSharedCharacters> = {}): ForumSharedCharacters {
    return {
        items: [
            {
                id: 'mercader',
                name: 'Mercader',
                initials: 'M',
                portraitUrl: null,
                isShared: true,
                description: 'Vende de todo',
                isRetired: false,
                isRetirementLocked: false,
                retiredLabel: null,
                hasPosts: true,
                actions: {
                    updateUrl: '/foro/personajes/mercader',
                    retireUrl: '/foro/personajes/mercader/retirar',
                    restoreUrl: null,
                    deleteUrl: null,
                },
            },
            {
                id: 'alguacil',
                name: 'Alguacil',
                initials: 'A',
                portraitUrl: null,
                isShared: true,
                description: null,
                isRetired: true,
                isRetirementLocked: false,
                retiredLabel: '12/11/2026',
                hasPosts: false,
                actions: {
                    updateUrl: '/foro/personajes/alguacil',
                    retireUrl: null,
                    restoreUrl: '/foro/personajes/alguacil/recuperar',
                    deleteUrl: '/foro/personajes/alguacil',
                },
            },
        ],
        createUrl: '/foro/c/arrakis/pnj',
        categories: [],
        limits: {
            nameMax: 60,
            descriptionMax: 300,
            portraitKilobytes: 2048,
            portraitTypes: ['image/png'],
            perAccount: 30,
        },
        max: 30,
        ...overrides,
    };
}

function stubList(list: ForumSharedCharacters): ReturnType<typeof vi.fn> {
    const fetcher = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve(list) });
    vi.stubGlobal('fetch', fetcher);

    return fetcher;
}

async function opened(list: ForumSharedCharacters): Promise<ReturnType<typeof mount>> {
    stubList(list);
    const wrapper = mount(SharedCharacters, {
        props: { url: '/foro/c/arrakis/pnj' },
        attachTo: document.body,
    });
    await wrapper.find('[data-testid="shared-characters-toggle"]').trigger('click');
    await flushPromises();

    return wrapper;
}

describe('SharedCharacters', () => {
    it('loads nothing until it is opened', async () => {
        const fetcher = stubList(aList());
        const wrapper = mount(SharedCharacters, { props: { url: '/foro/c/arrakis/pnj' } });

        expect(fetcher).not.toHaveBeenCalled();
        const toggle = wrapper.find('[data-testid="shared-characters-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');

        await toggle.trigger('click');
        await flushPromises();

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(toggle.attributes('aria-expanded')).toBe('true');
    });

    it('lists the PNJ with what each may do: one with posts is only retired, a retired one comes back', async () => {
        const wrapper = await opened(aList());

        const cards = wrapper.findAll('[data-testid="shared-character-list"] > li');
        expect(cards).toHaveLength(2);

        const [mercader, alguacil] = cards;
        expect(mercader?.text()).toContain('Mercader');
        expect(mercader?.text()).toContain('Retirar');
        expect(mercader?.text()).not.toContain('Borrar');
        expect(mercader?.text()).toContain('Tiene mensajes: se retira en lugar de borrarse.');

        expect(alguacil?.text()).toContain('Retirado');
        expect(alguacil?.text()).toContain('Recuperar');
        expect(alguacil?.text()).toContain('Borrar');
        expect(alguacil?.text()).not.toContain('Retirar');
    });

    it('offers to create one while the space has room, and says when it has none', async () => {
        const open = await opened(aList());
        expect(open.find('[data-testid="shared-character-create"]').exists()).toBe(true);

        const full = await opened(aList({ max: 2 }));
        expect(full.find('[data-testid="shared-character-create"]').exists()).toBe(false);
        expect(full.text()).toContain('La campaña ya tiene 2 PNJ.');
    });

    it('offers no creating to whoever only moderates the space', async () => {
        const wrapper = await opened(aList({ createUrl: null }));

        expect(wrapper.find('[data-testid="shared-character-create"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('los personajes están desactivados');
    });

    it('retires a PNJ and loads the list again', async () => {
        const wrapper = await opened(aList());
        const send = vi
            .fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({}) })
            .mockResolvedValue({ ok: true, json: () => Promise.resolve(aList()) });
        vi.stubGlobal('fetch', send);

        const retire = wrapper
            .findAll('button')
            .find((button) => button.attributes('aria-label') === 'Retirar a Mercader');
        await retire?.trigger('click');
        await flushPromises();

        expect(send.mock.calls[0]?.[0]).toBe('/foro/personajes/mercader/retirar');
        expect(send.mock.calls[0]?.[1]).toMatchObject({ method: 'POST' });
        expect(send.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(wrapper.find('[role="status"]').text()).toBe('Mercader se ha retirado.');
    });

    it('says so when the list cannot be loaded, and tries again', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
        const wrapper = mount(SharedCharacters, { props: { url: '/foro/c/arrakis/pnj' } });
        await wrapper.find('[data-testid="shared-characters-toggle"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('No se han podido cargar los PNJ.');

        const fetcher = stubList(aList());
        const retry = wrapper.findAll('button').find((button) => button.text() === 'Reintentar');
        await retry?.trigger('click');
        await flushPromises();

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(wrapper.findAll('[data-testid="shared-character-list"] > li')).toHaveLength(2);
    });
});
