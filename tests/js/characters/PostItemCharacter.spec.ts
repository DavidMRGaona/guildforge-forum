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
});
