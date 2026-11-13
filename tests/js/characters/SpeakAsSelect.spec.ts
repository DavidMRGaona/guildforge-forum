import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import SpeakAsSelect from '../../../resources/js/components/characters/SpeakAsSelect.vue';
import type { ForumCharacterPicker } from '../../../resources/js/types/forum';

beforeAll(() => {
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

function aPicker(overrides: Partial<ForumCharacterPicker> = {}): ForumCharacterPicker {
    return {
        items: [
            { id: 'arwen', name: 'Arwen', initials: 'A', portraitUrl: null },
            {
                id: 'boromir',
                name: 'Boromir',
                initials: 'B',
                portraitUrl: 'https://cdn.example.com/b.png',
            },
        ],
        suggestedId: 'arwen',
        categoryId: 'general',
        createUrl: '/foro/personajes',
        categories: [
            { id: 'general', name: 'General', parentId: null, parentName: null },
            { id: 'espacio', name: 'Espacio', parentId: 'general', parentName: 'General' },
            { id: 'otra', name: 'Otra', parentId: null, parentName: null },
        ],
        limits: {
            nameMax: 60,
            descriptionMax: 300,
            portraitKilobytes: 2048,
            portraitTypes: ['image/png', 'image/jpeg'],
            perAccount: 30,
        },
        ...overrides,
    };
}

function options(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('option').map((option) => option.text());
}

describe('SpeakAsSelect', () => {
    it('offers out of character, the characters and a new one', () => {
        const wrapper = mount(SpeakAsSelect, { props: { modelValue: 'arwen', picker: aPicker() } });

        expect(options(wrapper)).toEqual([
            'Fuera de personaje',
            'Arwen',
            'Boromir',
            'Nuevo personaje…',
        ]);
        expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('arwen');
        expect(wrapper.find('label').text()).toBe('Hablar como');
    });

    it('says null when the post goes out of character', async () => {
        const wrapper = mount(SpeakAsSelect, { props: { modelValue: 'arwen', picker: aPicker() } });

        await wrapper.find('select').setValue('');

        expect(wrapper.emitted('update:modelValue')).toEqual([[null]]);
    });

    it('opens the form instead of choosing «Nuevo personaje…»', async () => {
        const wrapper = mount(SpeakAsSelect, {
            props: { modelValue: null, picker: aPicker() },
            attachTo: document.body,
        });

        await wrapper.find('select').setValue('__new__');
        await nextTick();

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        expect(
            document.body.querySelector<HTMLDialogElement>('[data-testid="character-dialog"]')?.open
        ).toBe(true);
        wrapper.unmount();
    });

    it('keeps the character a post being edited speaks as, even if retired', () => {
        const wrapper = mount(SpeakAsSelect, {
            props: {
                modelValue: 'retired',
                picker: aPicker({ createUrl: null }),
                current: { id: 'retired', name: 'Viejo', initials: 'V', portraitUrl: null },
            },
        });

        expect(options(wrapper)).toEqual(['Fuera de personaje', 'Viejo', 'Arwen', 'Boromir']);
    });

    it('offers on the new discussion page only what the chosen category takes', async () => {
        const picker = aPicker({
            items: [
                {
                    id: 'arwen',
                    name: 'Arwen',
                    initials: 'A',
                    portraitUrl: null,
                    categoryIds: ['general', 'espacio'],
                },
                {
                    id: 'boromir',
                    name: 'Boromir',
                    initials: 'B',
                    portraitUrl: null,
                    categoryIds: ['otra'],
                },
            ],
            categoryId: null,
            createUrl: null,
        });
        const wrapper = mount(SpeakAsSelect, {
            props: { modelValue: 'boromir', picker, categoryId: 'espacio' },
        });
        await nextTick();

        expect(options(wrapper)).toEqual(['Fuera de personaje', 'Arwen']);
        // The character chosen before no longer fits
        expect(wrapper.emitted('update:modelValue')).toEqual([[null]]);
    });

    it('groups the shared PNJ of a campaign apart from the person’s own characters', async () => {
        const wrapper = mount(SpeakAsSelect, {
            props: {
                modelValue: null,
                picker: aPicker({
                    items: [
                        { id: 'arwen', name: 'Arwen', initials: 'A', portraitUrl: null },
                        {
                            id: 'mercader',
                            name: 'Mercader',
                            initials: 'M',
                            portraitUrl: null,
                            isShared: true,
                        },
                    ],
                }),
            },
        });

        const groups = wrapper.findAll('optgroup');
        expect(groups.map((group) => group.attributes('label'))).toEqual([
            'Mis personajes',
            'PNJ de la campaña',
        ]);
        expect(groups[0]?.findAll('option').map((option) => option.text())).toEqual(['Arwen']);
        expect(groups[1]?.findAll('option').map((option) => option.text())).toEqual(['Mercader']);

        await wrapper.find('select').setValue('mercader');

        expect(wrapper.emitted('update:modelValue')).toEqual([['mercader']]);
    });

    it('leaves out the group of their own when only shared PNJ can be chosen', () => {
        const wrapper = mount(SpeakAsSelect, {
            props: {
                modelValue: null,
                picker: aPicker({
                    items: [
                        {
                            id: 'mercader',
                            name: 'Mercader',
                            initials: 'M',
                            portraitUrl: null,
                            isShared: true,
                        },
                    ],
                }),
            },
        });

        expect(wrapper.findAll('optgroup').map((group) => group.attributes('label'))).toEqual([
            'PNJ de la campaña',
        ]);
    });

    it('draws no groups when the person has no shared PNJ here', () => {
        const wrapper = mount(SpeakAsSelect, { props: { modelValue: null, picker: aPicker() } });

        expect(wrapper.findAll('optgroup')).toHaveLength(0);
    });
});
