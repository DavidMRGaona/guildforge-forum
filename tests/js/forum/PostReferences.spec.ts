import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost, ForumPostPreview } from '../../../resources/js/types/forum';

function link(id: string, number: number): string {
    return `<a class="forum-ref" href="/foro/t/7-partida/${number}" data-post-id="${id}" data-preview-url="/foro/mensajes/${id}/vista">#${number}</a>`;
}

function aPreview(overrides: Partial<ForumPostPreview> = {}): ForumPostPreview {
    return {
        id: 'post-3',
        number: 3,
        url: '/foro/t/7-partida/3',
        status: 'visible',
        authorId: 'author-3',
        authorName: 'Luis',
        authorAvatarPublicId: null,
        authorTitle: 'Bardo',
        character: null,
        publishedAt: '2026-11-12T10:00:00+01:00',
        publishedLabel: '12/11/2026 10:00',
        html: '<p>Lo que dijo Luis</p>',
        discussionTitle: 'Partida',
        truncated: false,
        hiddenImages: 0,
        hiddenEmbeds: 0,
        ...overrides,
    };
}

function aPost(html: string, overrides: Partial<ForumPost> = {}): ForumPost {
    return {
        id: 'post-1',
        number: 5,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: false,
        status: 'visible',
        html,
        placeholder: null,
        quoteText: 'Mira  ahora',
        publishedAt: '2026-11-12T10:00:00+01:00',
        publishedLabel: '12/11/2026 10:00',
        editedAt: null,
        editedLabel: null,
        url: '/foro/t/7-partida/5',
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

const wrappers: VueWrapper[] = [];
let answers: Record<string, ForumPostPreview | null> = {};

function mounted(html: string, overrides: Partial<ForumPost> = {}): VueWrapper {
    const wrapper = mount(PostItem, {
        attachTo: document.body,
        props: {
            post: aPost(html, overrides),
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
    wrappers.push(wrapper);

    return wrapper;
}

function click(target: Element, init: MouseEventInit = {}): MouseEvent {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    target.dispatchEvent(event);

    return event;
}

function boxes(wrapper: VueWrapper): HTMLElement[] {
    return [...(wrapper.element as Element).querySelectorAll<HTMLElement>('.forum-ref-preview')];
}

beforeEach(() => {
    answers = { '/foro/mensajes/post-3/vista': aPreview() };
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            const preview = answers[url] ?? null;

            return new Response(JSON.stringify(preview ?? { message: 'Not Found' }), {
                status: preview ? 200 : 404,
                headers: { 'Content-Type': 'application/json' },
            });
        })
    );
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('opening a reference in place', () => {
    it('opens the post under its paragraph and closes it again', async () => {
        const wrapper = mounted(`<p>Mira ${link('post-3', 3)} ahora</p><p>Fin</p>`);
        const reference = wrapper.get('a.forum-ref').element;

        expect(click(reference).defaultPrevented).toBe(true);
        await flushPromises();

        const [box] = boxes(wrapper);
        expect(box).toBeDefined();
        expect(box!.previousElementSibling?.tagName).toBe('P');
        expect(box!.nextElementSibling?.textContent).toBe('Fin');
        expect(box!.textContent).toContain('Lo que dijo Luis');
        expect(box!.textContent).toContain('Luis');
        expect(box!.textContent).toContain('Bardo');
        expect(reference.getAttribute('aria-expanded')).toBe('true');

        click(reference);
        await flushPromises();

        expect(boxes(wrapper)).toHaveLength(0);
        expect(reference.getAttribute('aria-expanded')).toBe('false');
    });

    it('tells from the start that a reference opens in place', () => {
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);

        expect(wrapper.get('a.forum-ref').attributes('aria-expanded')).toBe('false');
    });

    it('opens with one click after editing was cancelled with a box open', async () => {
        const wrapper = mounted(`<p>Mira ${link('post-3', 3)}</p>`, {
            canEdit: true,
            document: { type: 'doc', content: [{ type: 'paragraph' }] },
            updateUrl: '/foro/mensajes/post-1',
        });
        click(wrapper.get('a.forum-ref').element);
        await flushPromises();
        expect(boxes(wrapper)).toHaveLength(1);

        await wrapper.get('button[aria-label="Editar"]').trigger('click');
        await flushPromises();
        const cancel = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancelar la edición');
        await cancel!.trigger('click');
        await flushPromises();

        click(wrapper.get('a.forum-ref').element);
        await flushPromises();
        expect(boxes(wrapper)).toHaveLength(1);
    });

    it('gives the number in an opened post a touch target of 44 px', async () => {
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        click(wrapper.get('a.forum-ref').element);
        await flushPromises();

        expect(boxes(wrapper)[0]!.querySelector('header a')?.className).toContain('min-h-[44px]');
    });

    it('lets a click with a modifier or another button go to the post', () => {
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        const reference = wrapper.get('a.forum-ref').element;

        // Navigation itself is the browser's: here it is only not prevented
        expect(click(reference, { ctrlKey: true }).defaultPrevented).toBe(false);
        expect(click(reference, { metaKey: true }).defaultPrevented).toBe(false);
        expect(click(reference, { button: 1 }).defaultPrevented).toBe(false);
    });

    it('opens a reference inside an opened post within it', async () => {
        answers['/foro/mensajes/post-3/vista'] = aPreview({
            html: `<p>Como en ${link('post-2', 2)}</p>`,
        });
        answers['/foro/mensajes/post-2/vista'] = aPreview({
            id: 'post-2',
            number: 2,
            html: '<p>El origen</p>',
        });
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        click(wrapper.get('a.forum-ref').element);
        await flushPromises();

        click(boxes(wrapper)[0]!.querySelector('a.forum-ref')!);
        await flushPromises();

        const inner = boxes(wrapper)[0]!.querySelector('.forum-ref-preview');
        expect(inner?.textContent).toContain('El origen');
    });

    it('goes to the post past three levels', async () => {
        for (const [id, next] of [
            ['post-3', 'post-4'],
            ['post-4', 'post-6'],
            ['post-6', 'post-7'],
        ] as const) {
            answers[`/foro/mensajes/${id}/vista`] = aPreview({
                id,
                html: `<p>${link(next, 9)}</p>`,
            });
        }
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        let reference: Element = wrapper.get('a.forum-ref').element;
        for (let level = 0; level < 3; level++) {
            expect(click(reference).defaultPrevented).toBe(true);
            await flushPromises();
            const all = [...wrapper.element.querySelectorAll('.forum-ref-preview a.forum-ref')];
            reference = all.at(-1)!;
        }

        expect(click(reference).defaultPrevented).toBe(false);
    });

    it('closes what is open when the post is drawn again', async () => {
        const errors = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const warnings = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        click(wrapper.get('a.forum-ref').element);
        await flushPromises();
        expect(boxes(wrapper)).toHaveLength(1);

        await wrapper.setProps({ post: aPost('<p>Editado</p>') });
        await flushPromises();

        expect(boxes(wrapper)).toHaveLength(0);
        expect(errors).not.toHaveBeenCalled();
        expect(warnings).not.toHaveBeenCalled();
        errors.mockRestore();
        warnings.mockRestore();
    });

    it('says so when the post cannot be opened', async () => {
        answers['/foro/mensajes/post-3/vista'] = null;
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        click(wrapper.get('a.forum-ref').element);
        await flushPromises();

        expect(boxes(wrapper)[0]!.textContent).toContain('No se ha podido cargar el mensaje.');
    });

    it('shows a long post shortened, with what it leaves out and a way to it', async () => {
        answers['/foro/mensajes/post-3/vista'] = aPreview({
            truncated: true,
            hiddenImages: 2,
            hiddenEmbeds: 1,
        });
        const wrapper = mounted(`<p>${link('post-3', 3)}</p>`);
        click(wrapper.get('a.forum-ref').element);
        await flushPromises();

        const box = boxes(wrapper)[0]!;
        expect(box.textContent).toContain(
            '2 imágenes y 1 contenido incrustado no se muestran aquí'
        );
        const whole = box.querySelector('[data-testid="post-preview-whole"]');
        expect(whole?.getAttribute('href')).toBe('/foro/t/7-partida/3');
        expect(whole?.textContent).toContain('Ver mensaje completo');
    });
});
