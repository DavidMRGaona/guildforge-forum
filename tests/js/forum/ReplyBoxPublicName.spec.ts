import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import ReplyBox from '../../../resources/js/components/forum/ReplyBox.vue';
import type { ForumReplySettings } from '../../../resources/js/types/forum';
import { page, signIn } from '../support/page';

const quote = vi.fn();
const focus = vi.fn();
// Only what the box asks of its editor: quoting and focus
const EditorStub = defineComponent({
    name: 'PostEditor',
    setup(_props, { expose }): () => ReturnType<typeof h> {
        expose({ quote, focus });

        return () => h('div', { 'data-testid': 'editor' });
    },
});

const reply = {
    canPost: true,
    postUrl: '/foro/temas/1/mensajes',
    mentionsUrl: null,
} as unknown as ForumReplySettings;

function mounted(): ReturnType<typeof mount> {
    return mount(ReplyBox, {
        props: {
            reply,
            draftKey: 'draft',
            isClosed: false,
            label: 'Tu respuesta',
            submitLabel: 'Responder',
            sending: false,
            error: null,
            signInSuffix: 'para responder.',
        },
        global: { stubs: { PostEditor: EditorStub } },
    });
}

describe('ReplyBox without a public name', () => {
    it('keeps a quotation until the editor appears, then quotes and focuses it', async () => {
        signIn({ displayName: null });
        quote.mockReset();
        focus.mockReset();
        const wrapper = mounted();
        expect(wrapper.find('[data-testid="editor"]').exists()).toBe(false);

        (wrapper.vm as unknown as { quote: (s: { postId: string; text: string }) => void }).quote({
            postId: 'post-1',
            text: 'Hola',
        });
        expect(quote).not.toHaveBeenCalled();

        // The prompt saves the name: it is the real flow (the gate tells the box it is named)
        await wrapper.get('input').setValue('Ana');
        const auth = page.props.auth as { user: Record<string, unknown> };
        const form = wrapper.get('form');
        await form.trigger('submit');
        page.props = { ...page.props, auth: { user: { ...auth.user, displayName: 'Ana' } } };
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(quote).toHaveBeenCalledWith({ postId: 'post-1', text: 'Hola' });
    });
});
