import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type {
    ForumPost,
    ForumPostCharacter,
    ForumProfileBadge,
} from '../../../resources/js/types/forum';

afterEach(() => {
    document.body.innerHTML = '';
});

const badge: ForumProfileBadge = {
    key: 'achievements.first',
    label: 'Primera partida',
    description: 'Jugó su primera partida en la asociación.',
    icon: 'trophy',
    colour: 'amber',
    imageUrl: null,
    url: null,
};

const arwen: ForumPostCharacter = {
    id: 'arwen',
    name: 'Arwen',
    initials: 'A',
    portraitUrl: null,
    description: null,
    isRetired: false,
    actions: null,
};

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

function mounted(post: ForumPost): VueWrapper {
    return mount(PostItem, {
        attachTo: document.body,
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

describe('the featured badges of a post’s author', () => {
    it('go next to the name, in a line that lets them wrap whole', () => {
        const wrapper = mounted(aPost({ authorBadges: [badge] }));

        const line = wrapper.find('[data-testid="post-author-line"]');
        expect(line.classes()).toContain('flex-wrap');
        const badges = line.find('[data-testid="profile-badges"]');
        expect(badges.exists()).toBe(true);
        expect(badges.classes()).toContain('shrink-0');
        expect(badges.findAll('li')).toHaveLength(1);
        // Icons only (`sm`): the label is there for screen readers
        expect(badges.find('[data-badge="achievements.first"]').classes()).toContain('h-6');
        expect(badges.text()).toContain('Primera partida');
        // Outside the name that labels the post, so it is not read as part of it
        expect(wrapper.find(`#${wrapper.attributes('aria-labelledby')}`).text()).toBe('Jane Doe');
    });

    it('show nothing when the post carries none', () => {
        expect(
            mounted(aPost({ authorBadges: [] }))
                .find('[data-testid="profile-badges"]')
                .exists()
        ).toBe(false);
        expect(mounted(aPost()).find('[data-testid="profile-badges"]').exists()).toBe(false);
    });

    it('in character go on the line of who plays it, never beside the character', () => {
        const wrapper = mounted(aPost({ character: arwen, authorBadges: [badge] }));

        expect(
            wrapper.find('[data-testid="post-character"] [data-testid="profile-badges"]').exists()
        ).toBe(false);
        const player = wrapper.find('[data-testid="post-character-player"]');
        expect(player.text()).toContain('por Jane Doe');
        expect(player.find('[data-testid="profile-badges"]').exists()).toBe(true);
    });

    it('in character show nothing when the line names nobody', () => {
        const wrapper = mounted(
            aPost({
                character: arwen,
                authorId: null,
                authorName: null,
                writerUnnamed: true,
                authorBadges: [badge],
            })
        );

        expect(wrapper.find('[data-testid="profile-badges"]').exists()).toBe(false);
    });
});
