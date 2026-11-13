import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PeoplePicker from '../../../resources/js/components/conversations/PeoplePicker.vue';

/**
 * The selector of people for a private conversation: it shows what the server finds, keeps the
 * people chosen as chips and never asks with fewer than two letters.
 */
function serve(people: Array<{ id: string; name: string }>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(
        async () =>
            new Response(JSON.stringify({ people }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            })
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('PeoplePicker', () => {
    it('asks nothing with fewer than two letters', async () => {
        const fetchMock = serve([]);
        const wrapper = mount(PeoplePicker, {
            props: { label: 'Con quién', peopleUrl: '/foro/conversaciones/personas', max: 9 },
        });

        await wrapper.get('[data-testid="people-search"]').setValue('a');
        await vi.advanceTimersByTimeAsync(500);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Escribe al menos dos letras.');
    });

    it('finds people and adds the one chosen', async () => {
        const fetchMock = serve([{ id: 'p-1', name: 'Eva Ruiz' }]);
        const wrapper = mount(PeoplePicker, {
            props: { label: 'Con quién', peopleUrl: '/foro/conversaciones/personas', max: 9 },
        });

        await wrapper.get('[data-testid="people-search"]').setValue('ev');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/foro/conversaciones/personas?q=ev',
            expect.anything()
        );
        await wrapper.get('[data-testid="people-result-p-1"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
            [{ id: 'p-1', name: 'Eva Ruiz' }],
        ]);
    });

    it('adds the text to an address that already has a query', async () => {
        const fetchMock = serve([]);
        const wrapper = mount(PeoplePicker, {
            props: {
                label: 'Añadir',
                peopleUrl: '/foro/conversaciones/personas?conversacion=c-1',
                max: 1,
                single: true,
            },
        });

        await wrapper.get('[data-testid="people-search"]').setValue('lu');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/foro/conversaciones/personas?conversacion=c-1&q=lu',
            expect.anything()
        );
        expect(wrapper.text()).toContain('No hay nadie con ese nombre a quien puedas escribir.');
    });

    it('takes no more people once it is full', () => {
        serve([]);
        const wrapper = mount(PeoplePicker, {
            props: {
                label: 'Con quién',
                peopleUrl: '/foro/conversaciones/personas',
                max: 1,
                modelValue: [{ id: 'p-1', name: 'Eva Ruiz' }],
            },
        });

        expect(wrapper.get('[data-testid="people-search"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Eva Ruiz');
    });
});
