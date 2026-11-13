import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { FormSelect } from '@/components/form';
import CharacterDialog from '../../../resources/js/components/characters/CharacterDialog.vue';
import type { ForumCharacterForm } from '../../../resources/js/types/forum';

beforeAll(() => {
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

const form: ForumCharacterForm = {
    createUrl: '/foro/personajes',
    categories: [{ id: 'campanas', name: 'Campañas', parentId: null, parentName: null }],
    limits: {
        nameMax: 60,
        descriptionMax: 300,
        portraitKilobytes: 2048,
        portraitTypes: ['image/png'],
        perAccount: 30,
    },
};

function field<T extends HTMLElement>(testId: string): T {
    const element = document.body.querySelector<T>(`[data-testid="${testId}"]`);
    if (element === null) {
        throw new Error(`No ${testId}`);
    }

    return element;
}

async function opened(props: Record<string, unknown> = {}): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(CharacterDialog, {
        props: { modelValue: false, form, ...props },
        attachTo: document.body,
    });
    await wrapper.setProps({ modelValue: true });
    await nextTick();

    return wrapper;
}

describe('CharacterDialog', () => {
    it('needs a name before it sends', async () => {
        await opened();

        expect(field<HTMLButtonElement>('character-submit').disabled).toBe(true);

        const name = field<HTMLInputElement>('character-name');
        name.value = 'Arwen';
        name.dispatchEvent(new Event('input'));
        await nextTick();

        expect(field<HTMLButtonElement>('character-submit').disabled).toBe(false);
    });

    it('sends the form and tells which category the character was tied to', async () => {
        const fetchMock = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        character: {
                            id: 'c1',
                            name: 'Arwen',
                            initials: 'A',
                            portraitUrl: null,
                            description: null,
                            isRetired: false,
                            actions: null,
                        },
                    }),
                    { status: 201, headers: { 'Content-Type': 'application/json' } }
                )
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = await opened();

        const name = field<HTMLInputElement>('character-name');
        name.value = 'Arwen';
        name.dispatchEvent(new Event('input'));
        expect(document.body.querySelector('select')).toBeNull();
        const category = wrapper.getComponent(FormSelect);
        expect(category.props('describedBy')).toBe(
            document.body.querySelector('[id^="character-category-help-"]')?.id
        );
        category.vm.$emit('update:modelValue', 'campanas');
        await nextTick();
        field<HTMLButtonElement>('character-submit')
            .closest('form')
            ?.dispatchEvent(new Event('submit'));
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/foro/personajes');
        const body = init.body as FormData;
        expect(body.get('name')).toBe('Arwen');
        expect(body.get('category')).toBe('campanas');
        expect(wrapper.emitted('saved')?.[0]?.[1]).toBe('campanas');
    });

    it('refuses a portrait in a format the forum does not take', async () => {
        await opened();
        const input = field<HTMLInputElement>('character-portrait');
        Object.defineProperty(input, 'files', {
            value: [new File(['<svg/>'], 'x.svg', { type: 'image/svg+xml' })],
        });
        input.dispatchEvent(new Event('change'));
        await nextTick();

        expect(document.body.textContent).toContain('Ese formato de imagen no se admite.');
    });

    it('leaves the category alone for a moderator', async () => {
        await opened({
            choosesCategory: false,
            start: {
                name: 'Arwen',
                description: null,
                categoryId: 'campanas',
                initials: 'A',
                portraitUrl: null,
                updateUrl: '/foro/personajes/c1',
            },
        });

        expect(document.body.querySelector('[data-testid="character-category"]')).toBeNull();
        expect(field<HTMLInputElement>('character-name').value).toBe('Arwen');
    });
});
