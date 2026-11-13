import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost } from '../../../resources/js/types/forum';

/**
 * «Mensaje privado» in the footer of a post (forum#88): only when the server gives where to
 * write, with the author's name in its accessible name and the text from the `sm` width up.
 */
function aPost(messageUrl: string | null): ForumPost {
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
        messageUrl,
        likes: { count: 0, liked: false, names: [], others: 0, canLike: false, url: null },
        character: null,
        characterId: null,
    };
}

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

describe('PostItem private message', () => {
    it('links to a new conversation with the author when the server allows it', () => {
        const wrapper = mounted(aPost('/foro/conversaciones/nueva?para=author-1'));

        const link = wrapper.get('[data-testid="post-write-privately"]');
        expect(link.attributes('href')).toBe('/foro/conversaciones/nueva?para=author-1');
        expect(link.attributes('aria-label')).toBe('Escribir un mensaje privado a Jane Doe');
        expect(link.text()).toBe('Mensaje privado');
    });

    it('offers nothing when the server gives no address', () => {
        const wrapper = mounted(aPost(null));

        expect(wrapper.find('[data-testid="post-write-privately"]').exists()).toBe(false);
    });
});
