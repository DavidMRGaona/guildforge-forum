import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import PostItem from '../../../resources/js/components/forum/PostItem.vue';
import type { ForumPost } from '../../../resources/js/types/forum';

const ROLL_ID = '5f6a7b8c-9d0e-4f1a-8b2c-3d4e5f6a7b8c';

// As the server renders a hidden roll to whoever threw it (RollNode)
const HTML =
    `<div class="forum-roll forum-roll--secret" data-roll-id="${ROLL_ID}">` +
    '<p class="forum-roll__head"><span class="forum-roll__label">Tirada</span> ' +
    '<code class="forum-roll__expression">1d20</code> <span class="forum-roll__reason">Sigilo</span></p>' +
    '<p class="forum-roll__total">Total = <strong>17</strong></p>' +
    '<div class="forum-roll__hidden-bar"><p class="forum-roll__secret">Solo ven el resultado quien tiró y la moderación.</p>' +
    `<button type="button" class="forum-roll__reveal" data-roll-reveal="${ROLL_ID}">Revelar</button></div></div>`;

function aPost(html: string): ForumPost {
    return {
        id: 'post-1',
        number: 2,
        authorId: 'author-1',
        authorName: 'Jane Doe',
        isStarter: false,
        status: 'visible',
        html,
        placeholder: null,
        quoteText: 'Tirada oculta: 1d20 Sigilo',
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

function mounted(html: string): ReturnType<typeof mount> {
    return mount(PostItem, {
        attachTo: document.body,
        props: {
            post: aPost(html),
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

function answer(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/** The reveal dialog, teleported to the end of the page next to the post's other dialogs */
function revealDialog(): HTMLDialogElement {
    const dialog = [...document.querySelectorAll('dialog')].find((candidate) =>
        candidate.textContent?.includes('Revelar la tirada')
    );
    if (dialog === undefined) {
        throw new Error('No reveal dialog');
    }

    return dialog;
}

function submitButton(): HTMLButtonElement {
    const button = revealDialog().querySelector<HTMLButtonElement>(
        '[data-testid="moderation-submit"]'
    );
    if (button === null) {
        throw new Error('No dialog');
    }

    return button;
}

function dialogForm(): HTMLFormElement {
    const form = submitButton().closest('form');
    if (form === null) {
        throw new Error('No form');
    }

    return form;
}

/** Ticks the box that says it cannot be undone and confirms */
async function confirm(): Promise<void> {
    const box = revealDialog().querySelector<HTMLInputElement>(
        '[data-testid="moderation-confirmation"]'
    );
    if (box === null) {
        throw new Error('No confirmation box');
    }
    box.checked = true;
    box.dispatchEvent(new Event('change'));
    await flushPromises();
    dialogForm().dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
}

beforeAll(() => {
    // jsdom has no modal dialogs
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('revealing a hidden roll', () => {
    it('asks twice and only then reveals it, and the page reads the post again', async () => {
        const fetch = vi.fn().mockResolvedValue(answer(200, { post: {} }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mounted(HTML);

        await wrapper.get('[data-roll-reveal]').trigger('click');
        await flushPromises();

        expect(revealDialog().open).toBe(true);
        expect(revealDialog().textContent).toContain('verá el resultado de 1d20 Sigilo');
        expect(submitButton().disabled).toBe(true);
        dialogForm().dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        expect(fetch).not.toHaveBeenCalled();

        await confirm();

        expect(fetch).toHaveBeenCalledWith(
            `/foro/tiradas/${ROLL_ID}/revelar`,
            expect.objectContaining({ method: 'POST' })
        );
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(revealDialog().open).toBe(false);
    });

    it('says why when it cannot be revealed', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                answer(409, {
                    message: 'Esta tirada ya estaba revelada.',
                    reason: 'already_revealed',
                })
            )
        );
        const wrapper = mounted(HTML);

        await wrapper.get('[data-roll-reveal]').trigger('click');
        await flushPromises();
        await confirm();

        expect(revealDialog().textContent).toContain('Esta tirada ya estaba revelada.');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });

    it('ignores a button that does not name a roll', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const wrapper = mounted(
            HTML.replaceAll(`data-roll-reveal="${ROLL_ID}"`, 'data-roll-reveal="x"')
        );

        await wrapper.get('[data-roll-reveal]').trigger('click');
        await flushPromises();

        expect(revealDialog().open).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });
});
