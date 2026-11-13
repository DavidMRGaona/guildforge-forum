import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { FormSelect } from '@/components/form';
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

/** «Fuera de personaje» (the empty choice) and then every option, as the list shows them */
function options(wrapper: ReturnType<typeof mount>): string[] {
    const select = wrapper.getComponent(FormSelect);

    return [
        select.props('placeholder') ?? '',
        ...select.props('options').map((option: { label: string }) => option.label),
    ];
}

function choose(wrapper: ReturnType<typeof mount>, value: string): void {
    wrapper.getComponent(FormSelect).vm.$emit('update:modelValue', value);
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
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.getComponent(FormSelect).props('modelValue')).toBe('arwen');
        expect(wrapper.find('label').text()).toBe('Hablar como');
        expect(wrapper.find('label').attributes('for')).toBe(
            wrapper.getComponent(FormSelect).props('id')
        );
    });

    it('says null when the post goes out of character', async () => {
        const wrapper = mount(SpeakAsSelect, { props: { modelValue: 'arwen', picker: aPicker() } });

        choose(wrapper, '');

        expect(wrapper.emitted('update:modelValue')).toEqual([[null]]);
    });

    it('opens the form instead of choosing «Nuevo personaje…»', async () => {
        const wrapper = mount(SpeakAsSelect, {
            props: { modelValue: null, picker: aPicker() },
            attachTo: document.body,
        });

        choose(wrapper, '__new__');
        await nextTick();

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        expect(wrapper.getComponent(FormSelect).props('modelValue')).toBeNull();
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

    it('puts the shared PNJ of a campaign after the person’s own characters, marked', async () => {
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
                        { id: 'arwen', name: 'Arwen', initials: 'A', portraitUrl: null },
                    ],
                }),
            },
        });

        expect(options(wrapper)).toEqual([
            'Fuera de personaje',
            'Arwen',
            'Mercader · PNJ de la campaña',
            'Nuevo personaje…',
        ]);

        choose(wrapper, 'mercader');
        await nextTick();

        expect(wrapper.emitted('update:modelValue')).toEqual([['mercader']]);
    });

    it('marks the shared PNJ also when only they can be chosen', () => {
        const wrapper = mount(SpeakAsSelect, {
            props: {
                modelValue: null,
                picker: aPicker({
                    createUrl: null,
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

        expect(options(wrapper)).toEqual(['Fuera de personaje', 'Mercader · PNJ de la campaña']);
    });
});
