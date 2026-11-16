import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, type PropType, type VNode } from 'vue';
import AuthorCard from '../../../resources/js/components/forum/AuthorCard.vue';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import { provideAuthorCards } from '../../../resources/js/composables/useAuthorCards';
import type { ForumAuthorCard, ForumPost } from '../../../resources/js/types/forum';

beforeAll(() => {
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

const CARD_URL = '/foro/mensajes/post-1/autor';

function aPost(overrides: Partial<ForumPost> = {}): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Ana',
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
        authorCardUrl: CARD_URL,
        ...overrides,
    };
}

function aCard(overrides: Partial<ForumAuthorCard> = {}): ForumAuthorCard {
    return {
        profile: {
            handle: 'ana',
            publicName: 'Ana García',
            url: 'https://example.org/miembros/ana',
            avatarUrl: 'https://res.cloudinary.com/demo/image/upload/avatars/ana',
            bannerUrl: null,
            memberSince: '2024-06-03T23:30:00+02:00',
            showsMembership: true,
            isOwner: false,
        },
        title: 'Veterana',
        postCount: 42,
        topic: { count: 3, url: '/foro/t/7-partida?autor=author-1' },
        block: {
            blocked: false,
            blockUrl: '/foro/bloqueos',
            unblockUrl: '/foro/bloqueos/author-1',
        },
        ...overrides,
    };
}

let answers: Record<string, { status: number; body: unknown }>;
let requests: { url: string; method: string; body: unknown }[];

beforeEach(() => {
    answers = { [`GET ${CARD_URL}`]: { status: 200, body: aCard() } };
    requests = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit) => {
            const method = init.method ?? 'GET';
            requests.push({
                url,
                method,
                body: typeof init.body === 'string' ? JSON.parse(init.body) : null,
            });
            const answer = answers[`${method} ${url}`] ?? {
                status: 404,
                body: { message: 'Not Found' },
            };

            return new Response(JSON.stringify(answer.body), {
                status: answer.status,
                headers: { 'Content-Type': 'application/json' },
            });
        })
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

function mounted(
    post: ForumPost = aPost(),
    extra: { canTitle?: boolean; canSuspend?: boolean } = {}
): VueWrapper {
    return mount(AuthorCard, {
        attachTo: document.body,
        props: { post, name: post.authorName ?? '', ...extra },
    });
}

async function opened(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-testid="author-card-toggle"]').trigger('click');
    await flushPromises();
}

describe('AuthorCard', () => {
    it('opens from the name with the profile and what the forum adds', async () => {
        const wrapper = mounted();
        const toggle = wrapper.get('[data-testid="author-card-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toBe('Ana');

        await opened(wrapper);

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(toggle.attributes('aria-controls')).toBe(
            wrapper.get('[data-testid="author-card"]').attributes('id')
        );
        expect(wrapper.get('[data-testid="author-card-name"]').text()).toBe('Ana García');
        expect(wrapper.get('[data-testid="author-card-title"]').text()).toBe('Veterana');
        expect(wrapper.get('[data-testid="author-card-member"]').text()).toBe('Socio');
        // The month and year as the core wrote them: never moved to another day by a zone
        expect(wrapper.get('[data-testid="author-card-since"]').text()).toBe(
            'Miembro desde junio de 2024'
        );
        expect(wrapper.get('[data-testid="author-card-posts"]').text()).toBe(
            '42 mensajes en el foro'
        );
        expect(wrapper.get('[data-testid="author-card-avatar"]').attributes('src')).toBe(
            'https://res.cloudinary.com/demo/image/upload/avatars/ana'
        );
        expect(wrapper.get('[data-testid="author-card-profile"]').attributes('href')).toBe(
            'https://example.org/miembros/ana'
        );
        expect(wrapper.get('[data-testid="author-card-topic"]').text()).toBe(
            'Ver sus 3 mensajes en este tema'
        );
        expect(wrapper.get('[data-testid="author-card-topic"]').attributes('href')).toBe(
            '/foro/t/7-partida?autor=author-1'
        );
        expect(wrapper.get('[data-testid="author-card-ignore"]').text()).toBe('Ignorar');
        // The focus goes into the card
        expect(document.activeElement).toBe(
            wrapper.get('[data-testid="author-card-profile"]').element
        );
    });

    it('closes with Escape and gives the focus back to the name', async () => {
        const wrapper = mounted();
        await opened(wrapper);

        await wrapper.get('[data-testid="author-card-profile"]').trigger('keydown', {
            key: 'Escape',
        });

        const toggle = wrapper.get('[data-testid="author-card-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(toggle.element);
    });

    it('closes with a click outside', async () => {
        const wrapper = mounted();
        await opened(wrapper);

        document.body.click();
        await flushPromises();

        expect(wrapper.get('[data-testid="author-card-toggle"]').attributes('aria-expanded')).toBe(
            'false'
        );
    });

    it('keeps the card while the page is open: a second post of the person asks nothing', async () => {
        const Page = defineComponent({
            props: { posts: { type: Array as PropType<ForumPost[]>, required: true } },
            setup(props) {
                provideAuthorCards();

                return (): VNode =>
                    h(
                        'div',
                        props.posts.map((post) =>
                            h(AuthorCard, { key: post.id, post, name: post.authorName ?? '' })
                        )
                    );
            },
        });
        const second = aPost({ id: 'post-2', authorCardUrl: '/foro/mensajes/post-2/autor' });
        const wrapper = mount(Page, {
            attachTo: document.body,
            props: { posts: [aPost(), second] },
        });
        const toggles = wrapper.findAll('[data-testid="author-card-toggle"]');

        await toggles[0].trigger('click');
        await flushPromises();
        await toggles[0].trigger('click');
        await toggles[1].trigger('click');
        await flushPromises();

        expect(requests.map((request) => request.url)).toEqual([CARD_URL]);
        expect(wrapper.findAll('[data-testid="author-card-name"]')[1].text()).toBe('Ana García');
    });

    it('without a profile it keeps what the post shows and links to no profile', async () => {
        answers[`GET ${CARD_URL}`] = {
            status: 200,
            body: aCard({ profile: null, title: null, postCount: null }),
        };
        const wrapper = mounted(aPost({ authorTitle: 'Narradora' }));
        await opened(wrapper);

        expect(wrapper.get('[data-testid="author-card-name"]').text()).toBe('Ana');
        expect(wrapper.get('[data-testid="author-card-title"]').text()).toBe('Narradora');
        expect(wrapper.find('[data-testid="author-card-profile"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="author-card-since"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="author-card-posts"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="author-card-topic"]').exists()).toBe(true);
    });

    it('offers each action only when it applies', async () => {
        answers[`GET ${CARD_URL}`] = {
            status: 200,
            body: aCard({ block: null, topic: { count: 0, url: null } }),
        };
        const wrapper = mounted(aPost({ messageUrl: '/foro/conversaciones/nueva?para=author-1' }));
        await opened(wrapper);

        expect(wrapper.get('[data-testid="author-card-message"]').attributes('href')).toBe(
            '/foro/conversaciones/nueva?para=author-1'
        );
        expect(wrapper.find('[data-testid="author-card-ignore"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="author-card-topic"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="author-card-title-action"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="author-card-suspend"]').exists()).toBe(false);
    });

    it('ignores the person and takes it back', async () => {
        answers['POST /foro/bloqueos'] = { status: 201, body: { blocked: true } };
        answers['DELETE /foro/bloqueos/author-1'] = { status: 200, body: { blocked: false } };
        const wrapper = mounted();
        await opened(wrapper);

        await wrapper.get('[data-testid="author-card-ignore"]').trigger('click');
        await flushPromises();

        expect(requests[1]).toEqual({
            url: '/foro/bloqueos',
            method: 'POST',
            body: { account: 'author-1' },
        });
        expect(wrapper.get('[data-testid="author-card-status"]').text()).toBe(
            'Has ignorado a Ana García.'
        );
        expect(wrapper.get('[data-testid="author-card-ignore"]').text()).toBe('Dejar de ignorar');

        await wrapper.get('[data-testid="author-card-ignore"]').trigger('click');
        await flushPromises();

        expect(requests[2]?.method).toBe('DELETE');
        expect(wrapper.get('[data-testid="author-card-ignore"]').text()).toBe('Ignorar');
    });

    it('says so when the card cannot be loaded, and asks again next time', async () => {
        answers[`GET ${CARD_URL}`] = { status: 429, body: { message: 'Too Many Attempts.' } };
        const wrapper = mounted();
        await opened(wrapper);

        expect(wrapper.get('[data-testid="author-card-failed"]').text()).toBe(
            'No se ha podido cargar la tarjeta. Inténtalo de nuevo.'
        );
        // What the post shows stays
        expect(wrapper.get('[data-testid="author-card-name"]').text()).toBe('Ana');

        answers[`GET ${CARD_URL}`] = { status: 200, body: aCard() };
        await wrapper.get('[data-testid="author-card-toggle"]').trigger('click');
        await opened(wrapper);

        expect(requests).toHaveLength(2);
        expect(wrapper.find('[data-testid="author-card-failed"]').exists()).toBe(false);
    });

    it('hands the moderation of the person to the post', async () => {
        const wrapper = mounted(aPost(), { canTitle: true, canSuspend: true });
        await opened(wrapper);

        expect(wrapper.get('[data-testid="author-card-title-action"]').text()).toBe(
            'Cambiar título'
        );
        await wrapper.get('[data-testid="author-card-title-action"]').trigger('click');

        expect(wrapper.emitted('moderate')).toEqual([['title', 'Veterana']]);
        expect(wrapper.get('[data-testid="author-card-toggle"]').attributes('aria-expanded')).toBe(
            'false'
        );
    });
});

describe('PostItem with the author card', () => {
    function mountedPost(post: ForumPost, titleUrl: string | null = null): VueWrapper {
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

    it('makes the name the button of the card', () => {
        const wrapper = mountedPost(aPost());

        expect(wrapper.get('[data-testid="author-card-toggle"]').text()).toBe('Ana');
        expect(wrapper.get('[data-testid="post-author-name"]').text()).toBe('Ana');
    });

    it('leaves the name as text where the post names nobody', () => {
        const wrapper = mountedPost(aPost({ authorCardUrl: null }));

        expect(wrapper.find('[data-testid="author-card-toggle"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="post-author-name"]').text()).toBe('Ana');
    });

    it('in character, the name of who plays it opens the card', () => {
        const wrapper = mountedPost(
            aPost({
                character: {
                    id: 'character-1',
                    name: 'Arwen',
                    initials: 'A',
                    portraitUrl: null,
                    description: null,
                    isRetired: false,
                    isShared: false,
                    actions: null,
                },
            })
        );

        const writer = wrapper.get('[data-testid="post-character-writer"]');
        expect(writer.element.firstChild?.textContent).toBe('por ');
        expect(writer.get('[data-testid="author-card-toggle"]').text()).toBe('Ana');
    });

    it('a shared character whose writer is hidden opens no card', () => {
        const wrapper = mountedPost(
            aPost({
                authorId: null,
                authorName: null,
                authorCardUrl: null,
                character: {
                    id: 'character-1',
                    name: 'Mercader',
                    initials: 'M',
                    portraitUrl: null,
                    description: null,
                    isRetired: false,
                    isShared: true,
                    actions: null,
                },
            })
        );

        expect(wrapper.find('[data-testid="author-card-toggle"]').exists()).toBe(false);
    });

    it('a title chosen from the card opens the title dialog with the card’s title', async () => {
        const wrapper = mountedPost(aPost(), '/foro/titulos');
        await wrapper.get('[data-testid="author-card-toggle"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="author-card-title-action"]').trigger('click');
        await flushPromises();

        const input = document.querySelector<HTMLInputElement>('dialog[open] input');
        expect(input?.value).toBe('Veterana');
    });
});
