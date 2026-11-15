import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PublicNameGate from '../../../resources/js/components/forum/PublicNameGate.vue';
import { page, signIn } from '../support/page';

function mounted(): ReturnType<typeof mount> {
    return mount(PublicNameGate, { slots: { default: '<p data-testid="editor">Editor</p>' } });
}

describe('PublicNameGate', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockReset();
    });

    it('shows the editor to whoever has a public name', () => {
        signIn({ displayName: 'Ana' });

        const wrapper = mounted();

        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="public-name-prompt"]').exists()).toBe(false);
    });

    it('asks for one, instead of the editor, when there is none or it is blank', () => {
        signIn({ displayName: null });
        expect(mounted().find('[data-testid="editor"]').exists()).toBe(false);

        signIn({ displayName: '   ' });
        const wrapper = mounted();
        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="public-name-prompt"]').text()).toContain(
            'Para escribir en el foro necesitas un nombre público'
        );
        expect(wrapper.get('label').text()).toBe('Nombre público');
    });

    it('saves it with the profile update, keeping the account name and email', async () => {
        signIn({ displayName: null });
        const wrapper = mounted();

        await wrapper.get('input').setValue('  Ana  ');
        await wrapper.get('form').trigger('submit');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data] = vi.mocked(router.post).mock.calls[0] ?? [];
        expect(url).toBe('/perfil');
        expect(data).toEqual({
            name: 'John Doe',
            email: 'john.doe@example.com',
            display_name: 'Ana',
        });
    });

    it('sends the address in force, so a pending change of email is not cancelled', async () => {
        signIn({ displayName: null });
        const auth = page.props.auth as { user: Record<string, unknown> };
        auth.user.verificationEmail = 'nuevo@example.com';
        const wrapper = mounted();

        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');

        const [, data] = vi.mocked(router.post).mock.calls[0] ?? [];
        expect(data).toMatchObject({ email: 'nuevo@example.com' });
    });

    it('says it is named, in the order Inertia works: the page first, then onSuccess', async () => {
        signIn({ displayName: null });
        vi.mocked(router.post).mockImplementation(((
            _url: string,
            _data: unknown,
            options?: { onStart?: () => void; onSuccess?: () => void; onFinish?: () => void }
        ) => {
            options?.onStart?.();
            // The new page (with the saved name) is set before the callbacks run
            const auth = page.props.auth as { user: Record<string, unknown> };
            page.props = { ...page.props, auth: { user: { ...auth.user, displayName: 'Ana' } } };
            // The watcher runs a tick later, before Inertia gets to `onSuccess`
            void nextTick().then(() => {
                options?.onSuccess?.();
                options?.onFinish?.();
            });
        }) as never);
        const wrapper = mounted();

        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('named')).toHaveLength(1);
        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(true);
    });

    it('does not say it is named for a name that was already there', async () => {
        signIn({ displayName: null });
        const wrapper = mounted();

        const auth = page.props.auth as { user: Record<string, unknown> };
        page.props = { ...page.props, auth: { user: { ...auth.user, displayName: 'Ana' } } };
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('named')).toBeUndefined();
    });

    it('shows the core email error as a pointer to the profile, never under the name', async () => {
        signIn({ displayName: null });
        vi.mocked(router.post).mockImplementation(((
            _url: string,
            _data: unknown,
            options?: { onError?: (e: Record<string, string>) => void }
        ) => {
            options?.onError?.({ email: 'Ya está en uso', name: 'Otro' });
        }) as never);
        const wrapper = mounted();

        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('[role="alert"]').text()).toContain('en tu perfil');
        expect(wrapper.text()).not.toContain('Ya está en uso');
    });

    it('answers too many attempts in plain words, without Inertia’s dialog', async () => {
        let handled: boolean | void = undefined;
        signIn({ displayName: null });
        vi.mocked(router.post).mockImplementation(((
            _url: string,
            _data: unknown,
            options?: {
                onStart?: () => void;
                onHttpException?: (r: { status: number }) => boolean | void;
            }
        ) => {
            options?.onStart?.();
            handled = options?.onHttpException?.({ status: 429 });
        }) as never);
        const wrapper = mounted();

        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('[role="alert"]').text()).toContain('demasiadas veces');
        // false keeps Inertia from opening its own error dialog
        expect(handled).toBe(false);
    });

    it('does not send an empty name', async () => {
        signIn({ displayName: null });
        const wrapper = mounted();

        await wrapper.get('form').trigger('submit');

        expect(router.post).not.toHaveBeenCalled();
        expect(wrapper.get('[role="alert"]').text()).toBe(
            'Escribe el nombre público que quieras usar.'
        );
    });

    it('shows the editor as soon as the shared props carry the name', async () => {
        signIn({ displayName: null });
        const wrapper = mounted();
        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(false);

        const auth = page.props.auth as { user: { displayName: string | null } };
        auth.user.displayName = 'Ana';
        page.props = { ...page.props, auth: { user: { ...auth.user } } };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(true);
    });

    it('shows the server error of the field', async () => {
        signIn({ displayName: null });
        vi.mocked(router.post).mockImplementation(((
            _url: string,
            _data: unknown,
            options?: { onError?: (e: Record<string, string>) => void }
        ) => {
            options?.onError?.({ display_name: 'Demasiado largo' });
        }) as never);
        const wrapper = mounted();

        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('[role="alert"]').text()).toBe('Demasiado largo');
    });

    it('answers a 403 and a network failure with the generic text, without Inertia’s dialog', async () => {
        signIn({ displayName: null });
        const answers: Array<boolean | void> = [];
        vi.mocked(router.post).mockImplementation(((
            _url: string,
            _data: unknown,
            options?: {
                onStart?: () => void;
                onHttpException?: (r: { status: number }) => boolean | void;
                onNetworkError?: () => boolean | void;
            }
        ) => {
            options?.onStart?.();
            answers.push(options?.onHttpException?.({ status: 403 }));
            answers.push(options?.onNetworkError?.());
        }) as never);
        const wrapper = mounted();

        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('[role="alert"]').text()).toBe(
            'No se ha podido guardar el nombre público. Inténtalo de nuevo.'
        );
        expect(answers).toEqual([false, false]);
    });

    it.each([
        ['a validation error', 'onError', [{ display_name: 'Demasiado largo' }]],
        ['too many attempts', 'onHttpException', [{ status: 429 }]],
        ['a network failure', 'onNetworkError', []],
        ['a cancelled visit', 'onCancel', []],
    ])(
        'does not say it is named when %s ended the save and the name arrives later',
        async (_label, callback, args) => {
            signIn({ displayName: null });
            vi.mocked(router.post).mockImplementation(((
                _url: string,
                _data: unknown,
                options?: Record<string, ((...a: unknown[]) => unknown) | undefined>
            ) => {
                options?.onStart?.();
                options?.[callback]?.(...args);
            }) as never);
            const wrapper = mounted();

            await wrapper.get('input').setValue('Ana');
            await wrapper.get('form').trigger('submit');
            // The name reaches the page afterwards (another tab, a later load)
            const auth = page.props.auth as { user: Record<string, unknown> };
            page.props = { ...page.props, auth: { user: { ...auth.user, displayName: 'Ana' } } };
            await flushPromises();

            expect(wrapper.emitted('named')).toBeUndefined();
        }
    );
});
