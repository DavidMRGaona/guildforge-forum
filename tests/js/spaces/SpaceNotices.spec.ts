import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SpaceNotices from '../../../resources/js/components/spaces/SpaceNotices.vue';

describe('SpaceNotices', () => {
    it('tells that the forum moderators may enter a private space', () => {
        const wrapper = mount(SpaceNotices, {
            props: { isPublicationSpace: true, readOnly: false },
        });

        expect(wrapper.find('[data-testid="space-moderation-notice"]').text()).toBe(
            'Este espacio es privado, pero la moderación del foro puede entrar en él para atender reportes.'
        );
        expect(wrapper.find('[data-testid="space-read-only-notice"]').exists()).toBe(false);
    });

    it('tells that a read-only space takes no posts', () => {
        const wrapper = mount(SpaceNotices, {
            props: { isPublicationSpace: true, readOnly: true },
        });

        expect(wrapper.find('[data-testid="space-read-only-notice"]').text()).toBe(
            'Solo lectura: ya no se puede escribir en este espacio.'
        );
    });

    it('draws nothing for an ordinary category', () => {
        const wrapper = mount(SpaceNotices, {
            props: { isPublicationSpace: false, readOnly: false },
        });

        expect(wrapper.html()).toBe('<!--v-if-->');
    });
});
