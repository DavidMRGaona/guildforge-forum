import { mount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import TitleDialog from '../../../resources/js/components/moderation/TitleDialog.vue';

beforeAll(() => {
    // happy-dom has no modal dialogs
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

function element<T extends HTMLElement>(testId: string): T {
    const found = document.body.querySelector<T>(`[data-testid="${testId}"]`);
    if (found === null) {
        throw new Error(`No ${testId}`);
    }

    return found;
}

async function opened(current: string | null): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(TitleDialog, {
        props: {
            modelValue: false,
            titleUrl: '/foro/titulos',
            userId: 'author-1',
            userName: 'Jane Doe',
            current,
        },
        attachTo: document.body,
    });
    await wrapper.setProps({ modelValue: true });

    return wrapper;
}

describe('TitleDialog', () => {
    it('starts from the current title and limits it to 30 characters', async () => {
        await opened('Bardo');

        const input = element<HTMLInputElement>('title-input');
        expect(input.value).toBe('Bardo');
        expect(input.getAttribute('maxlength')).toBe('30');
        expect(document.body.textContent).toContain('Título de Jane Doe');
    });

    it('sends the title trimmed and closes once saved', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(new Response(JSON.stringify({ title: 'Juglar' }), { status: 200 }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = await opened('Bardo');
        const input = element<HTMLInputElement>('title-input');
        input.value = '  Juglar  ';
        input.dispatchEvent(new Event('input'));
        await nextTick();
        element<HTMLFormElement>('title-form').dispatchEvent(
            new Event('submit', { cancelable: true })
        );
        await vi.waitFor(() => expect(wrapper.emitted('saved')).toHaveLength(1));

        const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/foro/titulos');
        expect(JSON.parse(String(init.body))).toEqual({ user: 'author-1', title: 'Juglar' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false]);
    });

    it('shows what the server refused without closing', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(JSON.stringify({ message: 'El título debe ser una sola línea.' }), {
                    status: 422,
                })
            )
        );
        const wrapper = await opened(null);
        const input = element<HTMLInputElement>('title-input');
        input.value = 'Bardo';
        input.dispatchEvent(new Event('input'));
        await nextTick();
        element<HTMLFormElement>('title-form').dispatchEvent(
            new Event('submit', { cancelable: true })
        );

        await vi.waitFor(() =>
            expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
                'El título debe ser una sola línea.'
            )
        );
        expect(wrapper.emitted('saved')).toBeUndefined();
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
