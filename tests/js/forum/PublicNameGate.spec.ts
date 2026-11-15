import { mount } from '@vue/test-utils';
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
});
