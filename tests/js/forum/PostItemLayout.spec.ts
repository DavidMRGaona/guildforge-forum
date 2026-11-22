import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { setCloudinaryConfig } from '@/utils/cloudinary';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost } from '../../../resources/js/types/forum';

function aPost(overrides: Partial<ForumPost> = {}): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        authorAvatarPublicId: null,
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

function mounted(post: ForumPost): ReturnType<typeof mount> {
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
        },
    });
}

beforeEach(() => {
    setCloudinaryConfig({ cloudName: 'demo', prefix: '' });
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('a compact post', () => {
    it('shows the profile avatar in the side column', () => {
        const wrapper = mounted(aPost({ authorAvatarPublicId: 'avatars/ana' }));

        const image = wrapper.find('[data-testid="post-avatar"] img');
        expect(image.exists()).toBe(true);
        expect(image.attributes('src')).toContain('avatars/ana');
        expect(image.attributes('src')).toContain('w_144');
    });

    it('falls back to the initial without avatar', () => {
        const wrapper = mounted(aPost());

        expect(wrapper.find('[data-testid="post-avatar"] img').exists()).toBe(false);
        expect(wrapper.find('[data-testid="post-avatar"]').text()).toBe('J');
    });

    it('shows the character portrait instead of the account avatar', () => {
        const wrapper = mounted(
            aPost({
                authorAvatarPublicId: 'avatars/ana',
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

        expect(wrapper.find('.forum-character-avatar').exists()).toBe(true);
        expect(wrapper.find('[data-testid="post-avatar"] img').exists()).toBe(false);
    });

    it('keeps date and permalink on the author line', () => {
        const header = mounted(aPost()).find('header');

        expect(header.text()).toContain('12/11/2026 10:00');
        const permalink = header.find('a[href="/foro/t/7-partida/2"]');
        expect(permalink.exists()).toBe(true);
        expect(permalink.text()).toBe('#2');
    });

    it('labels every icon-only action', () => {
        const wrapper = mounted(
            aPost({
                canEdit: true,
                canDelete: true,
                document: { type: 'doc', content: [] },
                updateUrl: '/foro/mensajes/post-1',
                deleteUrl: '/foro/mensajes/post-1',
                reportUrl: '/foro/mensajes/post-1/denuncias',
                messageUrl: '/foro/privados/nuevo?to=author-1',
            })
        );

        const actions = wrapper.findAll('footer button, footer a');
        expect(actions.length).toBeGreaterThan(0);
        for (const action of actions) {
            expect(action.attributes('aria-label') ?? '').not.toBe('');
        }
    });

    it('dims secondary actions until hover or focus', () => {
        const wrapper = mounted(
            aPost({
                canEdit: true,
                canDelete: true,
                document: { type: 'doc', content: [] },
                updateUrl: '/foro/mensajes/post-1',
                deleteUrl: '/foro/mensajes/post-1',
                reportUrl: '/foro/mensajes/post-1/denuncias',
                messageUrl: '/foro/privados/nuevo?to=author-1',
            })
        );

        const label = (name: string): string | undefined =>
            wrapper
                .findAll('footer button, footer a')
                .find((action) => action.attributes('aria-label') === name)
                ?.attributes('data-secondary');

        expect(label('Editar')).toBeDefined();
        expect(label('Borrar')).toBeDefined();
        expect(label('Denunciar')).toBeDefined();
        expect(label('Escribir un mensaje privado a Jane Doe')).toBeDefined();
        expect(label('Citar')).toBeUndefined();
    });

    it('lets the date, «editado» and «pendiente» wrap instead of widening the page', () => {
        const meta = mounted(aPost({ editedLabel: '12/11/2026 10:05', status: 'pending' })).get(
            '[data-testid="post-meta"]'
        );

        expect(meta.classes()).not.toContain('shrink-0');
        expect(meta.classes()).toContain('flex-wrap');
        expect(meta.classes()).toContain('min-w-0');
    });

    it('keeps the phone avatar on the same line as the name', () => {
        const wrapper = mounted(aPost({ authorName: 'Un nombre bastante largo para un móvil' }));

        const group = wrapper.get('[data-testid="post-author"]');
        expect(group.find('[data-testid="post-avatar"]').exists()).toBe(true);
        expect(group.find('[id$="-author"]').text()).toContain('Un nombre bastante largo');
        expect(group.classes()).toEqual(expect.arrayContaining(['flex', 'min-w-0']));
        expect(group.classes()).not.toContain('flex-wrap');
    });

    it('sends the date below a name that does not fit beside it, instead of cutting the name', () => {
        // flex-1 starts from no width at all, so the line never wraps and the name shrinks to a letter
        const group = mounted(aPost({ isStarter: true })).get('[data-testid="post-author"]');

        expect(group.classes()).toContain('flex-auto');
        expect(group.classes()).not.toContain('flex-1');
    });

    it('cuts the title before the name, keeping a few letters of each', () => {
        const wrapper = mounted(
            aPost({ isStarter: true, authorTitle: 'Lanzador de dados oficial' })
        );

        expect(wrapper.get('[data-testid="post-author-name"]').classes()).toContain('min-w-[6ch]');
        // It shrinks faster than the name but never vanishes (its whole text is in its tooltip)
        expect(wrapper.get('[data-testid="post-author-title"]').classes()).toEqual(
            expect.arrayContaining(['shrink-[2]', 'min-w-[5ch]'])
        );
    });

    it('keeps a few letters of a character name next to «Retirado»', () => {
        const wrapper = mounted(
            aPost({
                character: {
                    id: 'character-1',
                    name: 'Arwen Undómiel de Rivendel',
                    initials: 'AR',
                    portraitUrl: null,
                    description: null,
                    isRetired: true,
                    isShared: false,
                    actions: null,
                },
            })
        );

        expect(wrapper.get('[data-testid="post-character-name"]').classes()).toContain(
            'min-w-[6ch]'
        );
    });

    it('leaves room under a post that has no actions', () => {
        const wrapper = mount(PostItem, {
            props: {
                post: aPost(),
                focused: false,
                canQuote: false,
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
            },
        });

        expect(wrapper.find('footer').exists()).toBe(false);
        expect(wrapper.get('article').classes()).toContain('pb-3');
    });

    it('sizes the permalink for a finger by pointer, not by width', () => {
        const permalink = mounted(aPost()).get('header a[href="/foro/t/7-partida/2"]');

        expect(permalink.classes()).toContain('pointer-coarse:min-h-[44px]');
        expect(permalink.classes().some((name) => name.startsWith('sm:min-'))).toBe(false);
    });
});
