import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost } from '../../../resources/js/types/forum';

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

function aPost(overrides: Partial<ForumPost> = {}): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        authorTitle: null,
        isStarter: false,
        status: 'visible',
        html: '<p>Hola</p>',
        placeholder: null,
        quoteText: 'Hola',
        publishedAt: '2026-11-12T10:00:00+01:00',
        publishedLabel: '12/11/2026 10:00',
        editedAt: null,
        editedLabel: null,
        url: '/foro/t/7-partida/2',
        canEdit: false,
        canDelete: false,
        document: null,
        updateUrl: null,
        deleteUrl: null,
        hiddenReason: null,
        moderationUrl: null,
        reportUrl: null,
        likes: { count: 0, liked: false, names: [], others: 0, canLike: false, url: null },
        character: null,
        characterId: null,
        ...overrides,
    };
}

function mounted(post: ForumPost, titleUrl: string | null = null): VueWrapper {
    return mount(PostItem, {
        attachTo: document.body,
        props: {
            post,
            focused: false,
            canQuote: true,
            editor: {
                uploadUrl: null,
                maxImages: 10,
                imageTypes: ['image/png'],
                externalImageUrl: null,
                externalImageHosts: [],
                embedUrl: null,
                embedServices: [],
            },
            mentionsUrl: null,
            mentionNames: {},
            titleUrl,
        },
    });
}

function menuKeys(wrapper: VueWrapper): string[] {
    return wrapper
        .findAll('[data-moderation-item]')
        .map((item) => item.attributes('data-testid') ?? '');
}

describe('a post author title', () => {
    it('shows the title next to the name', () => {
        const wrapper = mounted(aPost({ authorTitle: 'Lanzador de dados' }));

        expect(wrapper.get('[data-testid="post-author-title"]').text()).toBe('Lanzador de dados');
        expect(wrapper.get('[data-testid="post-author"]').text()).toContain('Jane Doe');
    });

    it('offers the whole title as a tooltip, since a long one is cut', () => {
        const title = mounted(aPost({ authorTitle: 'Guardián de las reglas oficial' })).get(
            '[data-testid="post-author-title"]'
        );

        expect(title.attributes('title')).toBe('Guardián de las reglas oficial');
    });

    it('shows no title in character', () => {
        const wrapper = mounted(
            aPost({
                authorTitle: 'Bardo',
                character: {
                    id: 'character-1',
                    name: 'Arwen',
                    initials: 'AR',
                    portraitUrl: null,
                    description: null,
                    isRetired: false,
                    isShared: false,
                    actions: null,
                },
            })
        );

        expect(wrapper.find('[data-testid="post-author-title"]').exists()).toBe(false);
    });

    it('keeps markup in a title as text', () => {
        const title = mounted(aPost({ authorTitle: '<b>Jefe</b>' })).get(
            '[data-testid="post-author-title"]'
        );

        expect(title.text()).toBe('<b>Jefe</b>');
        expect(title.find('b').exists()).toBe(false);
    });

    it('offers to set, change and remove a title only with a title url', () => {
        expect(menuKeys(mounted(aPost({ authorTitle: 'Bardo' })))).not.toContain(
            'moderation-title-change'
        );
        expect(menuKeys(mounted(aPost(), '/foro/titulos'))).toEqual(
            expect.arrayContaining(['moderation-title-set'])
        );
        expect(menuKeys(mounted(aPost(), '/foro/titulos'))).not.toContain(
            'moderation-title-remove'
        );

        const titled = menuKeys(mounted(aPost({ authorTitle: 'Bardo' }), '/foro/titulos'));
        expect(titled).toEqual(
            expect.arrayContaining(['moderation-title-change', 'moderation-title-remove'])
        );
        expect(titled).not.toContain('moderation-title-set');
    });

    it('offers nothing for a post without a known author', () => {
        expect(menuKeys(mounted(aPost({ authorId: null }), '/foro/titulos'))).toEqual([]);
    });

    it('removes the title straight away', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(new Response(JSON.stringify({ title: null }), { status: 200 }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mounted(aPost({ authorTitle: 'Bardo' }), '/foro/titulos');

        await wrapper.get('[data-testid="moderation-toggle"]').trigger('click');
        await wrapper.get('[data-testid="moderation-title-remove"]').trigger('click');
        await vi.waitFor(() => expect(wrapper.emitted('changed')).toHaveLength(1));

        const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/foro/titulos');
        expect(JSON.parse(String(init.body))).toEqual({ user: 'author-1', title: null });
    });
});
