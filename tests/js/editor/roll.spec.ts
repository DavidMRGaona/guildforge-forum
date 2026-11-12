import { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import PostEditor from '../../../resources/js/components/editor/PostEditor.vue';
import { isDocumentEmpty, sanitizeContent } from '../../../resources/js/editor/document';
import { createForumExtensions } from '../../../resources/js/editor/extensions';

const ROLL_ID = '5f6a7b8c-9d0e-4f1a-8b2c-3d4e5f6a7b8c';

interface Exposed {
    getDocument: () => JSONContent | null;
}

let editor: Editor | null = null;

function makeEditor(content: string | JSONContent): Editor {
    editor = new Editor({
        content,
        extensions: createForumExtensions({
            imageUrl: () => null,
            mentionNames: { nameOf: () => null },
            mentionSuggestion: null,
        }),
    });

    return editor;
}

afterEach(() => {
    editor?.destroy();
    editor = null;
});

describe('the roll node', () => {
    it('keeps the expression and the reason, and an id only when it has one', () => {
        const json = makeEditor({
            type: 'doc',
            content: [
                { type: 'roll', attrs: { expression: '2d6+3', reason: 'Ataque', rollId: ROLL_ID } },
                { type: 'roll', attrs: { expression: '1d20', reason: null, rollId: null } },
            ],
        }).getJSON();

        expect(json.content).toEqual([
            { type: 'roll', attrs: { expression: '2d6+3', reason: 'Ataque', rollId: ROLL_ID } },
            { type: 'roll', attrs: { expression: '1d20', reason: null, rollId: null } },
        ]);
    });

    it('never copies the id of a throw into pasted HTML: a copy is a new roll', () => {
        const source = makeEditor({
            type: 'doc',
            content: [
                { type: 'roll', attrs: { expression: '2d6+3', reason: 'Ataque', rollId: ROLL_ID } },
            ],
        });
        const html = source.getHTML();

        expect(html).not.toContain(ROLL_ID);

        const pasted = makeEditor(html).getJSON();
        expect(pasted.content).toEqual([
            { type: 'roll', attrs: { expression: '2d6+3', reason: 'Ataque', rollId: null } },
        ]);
    });

    it('adds a roll to fill in with the button command, and a paragraph to go on', () => {
        const current = makeEditor('<p>Hola</p>');
        current.commands.focus('end');

        current.commands.insertRoll();

        expect(current.getJSON().content?.map((node) => node.type)).toEqual([
            'paragraph',
            'roll',
            'paragraph',
        ]);
        expect(current.getJSON().content?.[1]?.attrs).toEqual({
            expression: '',
            reason: null,
            rollId: null,
        });
    });

    it('turns a /tirada line into a roll on Enter', () => {
        const current = makeEditor('<p>/tirada 2d6+3 Ataque con espada</p>');
        current.commands.focus('end');

        current.view.someProp('handleKeyDown', (handler) =>
            handler(current.view, new KeyboardEvent('keydown', { key: 'Enter' }))
        );

        expect(current.getJSON().content).toEqual([
            {
                type: 'roll',
                attrs: { expression: '2d6+3', reason: 'Ataque con espada', rollId: null },
            },
            { type: 'paragraph' },
        ]);
    });

    it('does not take Enter on any other line', () => {
        const current = makeEditor('<p>Hoy tiramos 2d6</p>');
        current.commands.focus('end');

        current.commands.keyboardShortcut('Enter');

        expect(current.getJSON().content?.map((node) => node.type)).toEqual([
            'paragraph',
            'paragraph',
        ]);
    });

    it('counts as something to publish', () => {
        expect(
            isDocumentEmpty({
                type: 'doc',
                content: [
                    { type: 'roll', attrs: { expression: '1d6', reason: null, rollId: null } },
                ],
            })
        ).toBe(false);
    });

    it('is sanitized to a new roll when it is pasted or converted', () => {
        expect(
            sanitizeContent({
                type: 'roll',
                attrs: { expression: '1d20', reason: '', rollId: ROLL_ID, total: 20 },
            })
        ).toEqual({ type: 'roll', attrs: { expression: '1d20', reason: null, rollId: null } });
    });
});

describe('the roll in the post editor', () => {
    async function mountEditor(modelValue?: JSONContent): Promise<VueWrapper> {
        const wrapper = mount(PostEditor, {
            props: { label: 'Tu respuesta', submitLabel: 'Responder', modelValue },
            attachTo: document.body,
        });
        await flushPromises();
        await nextTick();

        return wrapper;
    }

    it('offers the «Tirada de dados» button, which adds a roll to fill in', async () => {
        const wrapper = await mountEditor();

        const button = wrapper.find('[data-toolbar-item="roll"]');
        expect(button.attributes('aria-label')).toBe('Tirada de dados');
        await button.trigger('click');
        await flushPromises();

        const inputs = wrapper.findAll('[data-forum-roll] input');
        expect(inputs).toHaveLength(2);
        expect(wrapper.find('[data-roll-expression]').attributes('readonly')).toBeUndefined();
        expect(wrapper.find('[data-forum-roll]').text()).toContain(
            'Se tira en el servidor al publicar'
        );
    });

    it('writes what is typed into the node and sends the roll without a result', async () => {
        const wrapper = await mountEditor();
        await wrapper.find('[data-toolbar-item="roll"]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-roll-expression]').setValue('2d6+3');
        await wrapper.find('[data-roll-reason]').setValue('Ataque');

        const document = (wrapper.vm as unknown as Exposed).getDocument();
        expect(document?.content?.[0]).toEqual({
            type: 'roll',
            attrs: { expression: '2d6+3', reason: 'Ataque', rollId: null },
        });
    });

    it('locks the expression of a roll the post already has: another expression is another roll', async () => {
        const wrapper = await mountEditor({
            type: 'doc',
            content: [
                { type: 'roll', attrs: { expression: '2d6+3', reason: 'Ataque', rollId: ROLL_ID } },
            ],
        });

        expect(wrapper.find('[data-roll-expression]').attributes('readonly')).toBeDefined();
        expect(wrapper.find('[data-roll-reason]').attributes('readonly')).toBeUndefined();
        expect(wrapper.find('[data-forum-roll]').text()).toContain('Ya está tirada');
    });

    it('removes the roll', async () => {
        const wrapper = await mountEditor({
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'Hola' }] },
                { type: 'roll', attrs: { expression: '2d6+3', reason: null, rollId: null } },
            ],
        });

        await wrapper.find('[data-roll-remove]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-forum-roll]').exists()).toBe(false);
    });
});
