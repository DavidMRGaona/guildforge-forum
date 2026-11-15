import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
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

        // The prompt saves the name, in Inertia's order: the page with the name, then onSuccess
        vi.mocked(router.post).mockImplementation(((
            _url: string,
            _data: unknown,
            options?: { onStart?: () => void; onSuccess?: () => void }
        ) => {
            options?.onStart?.();
            const auth = page.props.auth as { user: Record<string, unknown> };
            page.props = { ...page.props, auth: { user: { ...auth.user, displayName: 'Ana' } } };
            // The watcher runs a tick later, before Inertia gets to `onSuccess`
            void nextTick().then(() => options?.onSuccess?.());
        }) as never);
        await wrapper.get('input').setValue('Ana');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(quote).toHaveBeenCalledWith({ postId: 'post-1', text: 'Hola' });
        expect(focus).toHaveBeenCalled();
    });
});
