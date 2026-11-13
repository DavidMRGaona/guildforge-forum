import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import { quoteFits } from '../../../resources/js/editor/extensions/PostQuote';
import type { ForumPost } from '../../../resources/js/types/forum';

// As the server renders a post with a roll and an embed: labels, each die and buttons
const HTML =
    '<p>Al abordaje</p><div class="forum-roll"><p><span>Tirada</span> <code>2d6+3</code> Ataque</p>' +
    '<p>4 3 (7) + 3</p><p>Total = <strong>10</strong></p></div>' +
    '<div class="forum-embed"><p>YouTube</p><button>Cargar contenido de YouTube</button></div>';

function aPost(quoteText: string | null): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: false,
        status: 'visible',
        html: HTML,
        placeholder: null,
        quoteText,
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

function select(wrapper: ReturnType<typeof mount>, text: string): void {
    const node = [...wrapper.element.querySelectorAll('p, button')]
        .flatMap((element) => [...element.childNodes])
        .find((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.includes(text));
    if (!node) {
        throw new Error(`No text «${text}» in the post`);
    }
    const start = node.textContent!.indexOf(text);
    const range = document.createRange();
    range.setStart(node, start);
    range.setEnd(node, start + text.length);
    const selection = window.getSelection()!;
    selection.removeAllRanges();
    selection.addRange(range);
}

async function quoted(wrapper: ReturnType<typeof mount>): Promise<string | undefined> {
    const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Citar');
    await button!.trigger('click');
    const events = wrapper.emitted('quote') as [{ postId: string; text: string }][] | undefined;

    return events?.at(-1)?.[0].text;
}

const STORED = 'Al abordaje\n2d6+3 Ataque = 10\nhttps://www.youtube.com/watch?v=x';

afterEach(() => {
    window.getSelection()?.removeAllRanges();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('quoting a post', () => {
    it('quotes the whole post with its stored text, not the labels drawn around rolls and embeds', async () => {
        const wrapper = mounted(aPost(STORED));

        expect(await quoted(wrapper)).toBe(STORED);
    });

    it('quotes a selection the stored text holds as it is', async () => {
        const wrapper = mounted(aPost(STORED));
        select(wrapper, 'abordaje');

        expect(await quoted(wrapper)).toBe('abordaje');
    });

    it('quotes the whole post when the selection takes in a label the server would not find', async () => {
        const wrapper = mounted(aPost(STORED));
        select(wrapper, 'Cargar contenido');

        expect(await quoted(wrapper)).toBe(STORED);
    });

    it('quotes nothing from a post without text to quote', async () => {
        const wrapper = mounted(aPost(null));

        expect(await quoted(wrapper)).toBeUndefined();
    });
});

describe('quoteFits', () => {
    it('compares words only, as the server does', () => {
        expect(quoteFits('Hola, ¿qué tal?', 'QUÉ  tal')).toBe(true);
        expect(quoteFits('Hola, ¿qué tal?', 'Tirada 2d6')).toBe(false);
        expect(quoteFits('Hola', '¡¿ !?')).toBe(false);
    });
});
