import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost, ForumPostCharacter } from '../../../resources/js/types/forum';

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

const arwen: ForumPostCharacter = {
    id: 'arwen',
    name: 'Arwen',
    initials: 'A',
    portraitUrl: null,
    description: null,
    isRetired: false,
    actions: null,
};

describe('the topic starter mark', () => {
    it('marks the starter post with a border instead of a pill', () => {
        const wrapper = mounted(aPost({ isStarter: true }));
        const article = wrapper.find('article');

        expect(article.attributes('data-starter')).toBe('true');
        expect(article.classes()).toContain('border-primary');
        const label = wrapper.find('[data-testid="post-starter-label"]');
        expect(label.exists()).toBe(true);
        expect(label.classes()).toContain('sr-only');
        expect(label.text()).toBe('(autor del tema)');
        expect(label.element.closest('#post-2-author')).not.toBeNull();
        // No visible text says it any more
        const visible = wrapper
            .findAll('*')
            .filter((el) => !el.classes().includes('sr-only') && el.element.children.length === 0)
            .map((el) => el.text());
        expect(visible.join(' ')).not.toMatch(/autor del tema/i);
    });

    it('leaves other posts unmarked', () => {
        const wrapper = mounted(aPost({ isStarter: false }));

        expect(wrapper.find('article').attributes('data-starter')).toBeUndefined();
        expect(wrapper.find('article').classes()).not.toContain('border-primary');
        expect(wrapper.find('[data-testid="post-starter-label"]').exists()).toBe(false);
    });

    it('marks a starter writing in character inside the character line', () => {
        const wrapper = mounted(aPost({ isStarter: true, character: arwen }));

        const label = wrapper.find('[data-testid="post-starter-label"]');
        expect(label.exists()).toBe(true);
        expect(label.element.closest('[data-testid="post-character"]')).not.toBeNull();
        expect(label.element.closest('#post-2-author')).not.toBeNull();
        expect(wrapper.find('[data-testid="post-character-player"]').text()).not.toMatch(
            /autor del tema/i
        );
        expect(wrapper.findAll('[data-testid="post-starter-label"]')).toHaveLength(1);
    });

    it('keeps the border for a deleted starter account', () => {
        const wrapper = mounted(aPost({ isStarter: true, authorName: null }));

        expect(wrapper.find('article').attributes('data-starter')).toBe('true');
        expect(wrapper.find('[data-testid="post-starter-label"]').exists()).toBe(true);
    });
});
