import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost, ForumPostCharacter } from '../../../resources/js/types/forum';

function aPost(character: ForumPostCharacter | null): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: false,
        status: 'visible',
        html: '<p>Hola</p>',
        placeholder: null,
        quoteText: null,
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
        character,
        characterId: null,
    };
}

const arwen: ForumPostCharacter = {
    id: 'arwen',
    name: 'Arwen',
    initials: 'A',
    portraitUrl: null,
    description: null,
    isRetired: true,
    actions: null,
};

function mounted(post: ForumPost): ReturnType<typeof mount> {
    return mount(PostItem, {
        props: {
            post,
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
}

describe('a post written as a character', () => {
    it('shows the character and, below, who plays it', () => {
        const wrapper = mounted(aPost(arwen));

        expect(wrapper.find('[data-testid="post-character"]').text()).toContain('Arwen');
        expect(wrapper.find('[data-testid="post-character"]').text()).toContain('Retirado');
        expect(wrapper.find('[data-testid="post-character-player"]').text()).toBe('por Jane Doe');
        // The article is named after the character
        expect(wrapper.find('article').attributes('aria-labelledby')).toBe('post-2-author');
        expect(wrapper.find('#post-2-author').text()).toContain('Arwen');
    });

    it('keeps the badges out of the truncated name, so on a phone they wrap whole', () => {
        const retired = mounted(aPost(arwen));
        expect(retired.find('#post-2-author .truncate').text()).toBe('Arwen');

        const starter = mounted({ ...aPost(null), isStarter: true });
        expect(starter.find('#post-2-author .truncate').text()).toBe('Jane Doe');
        expect(starter.find('#post-2-author').text()).toContain('Autor del tema');
    });

    it('shows the account out of character', () => {
        const wrapper = mounted(aPost(null));

        expect(wrapper.find('[data-testid="post-character"]').exists()).toBe(false);
        expect(wrapper.find('#post-2-author').text()).toBe('Jane Doe');
    });

    it('gives whoever moderates the character its menu, even without moderating the post', () => {
        const wrapper = mounted(
            aPost({
                ...arwen,
                isRetired: false,
                actions: {
                    updateUrl: '/foro/personajes/arwen',
                    retireUrl: '/foro/personajes/arwen/retirar',
                    restoreUrl: null,
                    deleteUrl: '/foro/personajes/arwen',
                },
            })
        );

        expect(wrapper.text()).toContain('Moderar');
    });

    it('shows a shared PNJ with its badge and names who wrote it only when the server says so', () => {
        const mercader: ForumPostCharacter = {
            ...arwen,
            id: 'mercader',
            name: 'Mercader',
            initials: 'M',
            isRetired: false,
            isShared: true,
        };

        // Whoever may know: the directors, moderation and the author
        const known = mounted(aPost(mercader));
        expect(known.find('[data-testid="post-character-shared"]').text()).toBe('PNJ');
        expect(known.find('[data-testid="post-character-player"]').text()).toContain(
            'Escrito por Jane Doe'
        );

        // Everyone else: the server sends no account
        const hidden = mounted({
            ...aPost(mercader),
            authorId: null,
            authorName: null,
            isStarter: false,
        });
        expect(hidden.find('[data-testid="post-character"]').text()).toContain('Mercader');
        const player = hidden.find('[data-testid="post-character-player"]').text();
        expect(player).toContain('PNJ');
        expect(player).toContain('PNJ de la campaña');
        expect(player).not.toContain('Jane Doe');
        expect(player).not.toContain('Escrito por');
        expect(hidden.text()).not.toContain('Cuenta eliminada');
    });

    it('offers a PNJ with posts only retiring, never deleting', async () => {
        const wrapper = mounted(
            aPost({
                ...arwen,
                isShared: true,
                isRetired: false,
                actions: {
                    updateUrl: '/foro/personajes/arwen',
                    retireUrl: '/foro/personajes/arwen/retirar',
                    restoreUrl: null,
                    deleteUrl: null,
                },
            })
        );

        await wrapper.get('[data-testid="moderation-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="moderation-character-retire"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="moderation-character-delete"]').exists()).toBe(false);
    });
});
