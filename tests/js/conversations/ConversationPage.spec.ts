import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Conversation from '../../../resources/js/pages/Forum/Conversation.vue';
import type { ForumConversationEntry } from '../../../resources/js/types/forum';
import { signIn } from '../support/page';

/**
 * One private conversation: notes of who joined or left, deleted and hidden messages, the
 * reason only its author sees, and the refusal shown instead of the editor.
 */
function entry(overrides: Partial<ForumConversationEntry>): ForumConversationEntry {
    return {
        id: 'e-1',
        number: 1,
        kind: 'message',
        state: 'shown',
        authorId: 'a-1',
        authorName: 'Ana Pérez',
        subjectName: null,
        isMine: false,
        html: '<p>Hola</p>',
        hiddenReason: null,
        createdIso: '2026-11-20T10:00:00+01:00',
        createdLabel: '20/11/2026 10:00',
        deleteUrl: null,
        reportUrl: '/foro/conversaciones/mensajes/e-1/denuncias',
        ...overrides,
    };
}

function mountPage(props: Record<string, unknown> = {}): VueWrapper {
    return mount(Conversation, {
        props: {
            conversation: {
                id: 'c-1',
                title: 'Partida del viernes',
                url: '/foro/conversaciones/c-1',
            },
            participants: [
                { id: 'me', name: 'Luis Gómez', isMe: true, blocked: false },
                { id: 'a-1', name: 'Ana Pérez', isMe: false, blocked: false },
            ],
            entries: [
                entry({}),
                entry({
                    id: 'e-2',
                    number: 2,
                    kind: 'added',
                    state: 'note',
                    subjectName: 'Eva Ruiz',
                    html: null,
                    reportUrl: null,
                }),
                entry({ id: 'e-3', number: 3, state: 'deleted', html: null, reportUrl: null }),
                entry({
                    id: 'e-4',
                    number: 4,
                    state: 'hidden',
                    html: null,
                    isMine: true,
                    hiddenReason: 'Insultos',
                    reportUrl: null,
                }),
            ],
            pagination: { page: 1, lastPage: 1, previousUrl: null, nextUrl: null },
            write: {
                refusal: null,
                storeUrl: '/foro/conversaciones/c-1/mensajes',
                readUrl: '/foro/conversaciones/c-1/leido',
            },
            addUrl: '/foro/conversaciones/c-1/personas',
            peopleUrl: '/foro/conversaciones/personas?conversacion=c-1',
            leaveUrl: '/foro/conversaciones/c-1/salir',
            blockUrl: '/foro/bloqueos',
            unblockUrlTemplate: '/foro/bloqueos/00000000-0000-4000-8000-000000000000',
            indexUrl: '/foro/conversaciones',
            maxPeople: 10,
            ...props,
        } as never,
        global: {
            stubs: { PostEditor: { template: '<div data-testid="editor" />' }, HelpLink: true },
        },
    });
}

beforeEach(() => {
    signIn();
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ ids: [] }) }))
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('Conversation page', () => {
    it('shows messages, notes, and what is left of deleted and hidden messages', async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="entry-1"]').html()).toContain('<p>Hola</p>');
        expect(wrapper.get('[data-testid="entry-2"]').text()).toContain(
            'Ana Pérez ha añadido a Eva Ruiz'
        );
        expect(wrapper.get('[data-testid="entry-3"]').text()).toContain('Mensaje borrado');
        expect(wrapper.get('[data-testid="entry-4"]').text()).toContain(
            'Mensaje ocultado por la moderación'
        );
        expect(wrapper.get('[data-testid="entry-4"]').text()).toContain('Motivo: Insultos');
        expect(wrapper.find('[data-testid="report-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="report-3"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(true);
    });

    it('offers to block the others, never oneself', async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.find('[data-testid="block-a-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="block-me"]').exists()).toBe(false);
    });

    it('shows the refusal instead of the editor, naming nobody', async () => {
        const wrapper = mountPage({
            write: {
                refusal: 'No puedes escribir en esta conversación.',
                storeUrl: '/x',
                readUrl: '/y',
            },
        });
        await flushPromises();

        expect(wrapper.get('[data-testid="conversation-refusal"]').text()).toBe(
            'No puedes escribir en esta conversación.'
        );
        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(false);
    });

    it('hides «Añadir a alguien» when nobody else fits', async () => {
        const wrapper = mountPage({ addUrl: null });
        await flushPromises();

        expect(wrapper.find('[data-testid="conversation-add"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="conversation-leave"]').exists()).toBe(true);
    });
});
