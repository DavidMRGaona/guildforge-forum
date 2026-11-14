import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { FormCheckbox, FormSelect } from '@/components/form';
import ModerationDialog from '../../../resources/js/components/moderation/ModerationDialog.vue';

beforeAll(() => {
    // jsdom has no modal dialogs
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

async function opened(props: Record<string, unknown>): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(ModerationDialog, {
        props: { modelValue: false, title: 'Borrar el tema', confirmLabel: 'Borrar', ...props },
        attachTo: document.body,
    });
    await wrapper.setProps({ modelValue: true });
    await nextTick();

    return wrapper;
}

function field(testId: string): HTMLInputElement {
    const element = document.body.querySelector<HTMLInputElement>(`[data-testid="${testId}"]`);
    if (element === null) {
        throw new Error(`No ${testId}`);
    }

    return element;
}

describe('ModerationDialog', () => {
    it('waits for the box to be ticked before something irreversible', async () => {
        const wrapper = await opened({
            askReason: true,
            confirmation: 'Entiendo que no se puede deshacer',
            danger: true,
        });
        const submit = field('moderation-submit');

        expect(submit.disabled).toBe(true);

        field('moderation-reason').value = '  Spam  ';
        field('moderation-reason').dispatchEvent(new Event('input'));
        const box = field('moderation-confirmation');
        // The site's checkbox: the native input stays, hidden, inside its label
        expect(wrapper.findComponent(FormCheckbox).exists()).toBe(true);
        expect(box.querySelector('input')?.classList.contains('sr-only')).toBe(true);
        box.querySelector('input')?.click();
        await nextTick();
        expect(submit.disabled).toBe(false);

        submit.closest('form')?.dispatchEvent(new Event('submit'));
        await nextTick();

        expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Spam', title: '', category: '' }]]);
    });

    it('asks for a title within bounds when renaming', async () => {
        const wrapper = await opened({
            askTitle: true,
            initialTitle: 'Mesa del viernes',
            titleMin: 3,
            titleMax: 120,
        });
        const title = field('moderation-title');

        expect(title.value).toBe('Mesa del viernes');
        title.value = 'ab';
        title.dispatchEvent(new Event('input'));
        await nextTick();
        expect(field('moderation-submit').disabled).toBe(true);

        title.value = 'Mesa del sábado';
        title.dispatchEvent(new Event('input'));
        await nextTick();
        field('moderation-submit').closest('form')?.dispatchEvent(new Event('submit'));
        await nextTick();

        expect(wrapper.emitted('confirm')?.[0]).toEqual([
            { reason: '', title: 'Mesa del sábado', category: '' },
        ]);
    });

    it('offers the categories a discussion may move to', async () => {
        const wrapper = await opened({
            moveTargets: [{ id: 'c-1', name: 'Dudas', parentName: 'General' }],
        });

        expect(document.body.querySelector('select')).toBeNull();
        const select = wrapper.getComponent(FormSelect);
        expect(select.props('placeholder')).toBe('Elige una categoría');
        // A subcategory goes under its category, which is not a target here (forum#108)
        expect(select.props('options')).toEqual([
            { value: 'parent:name:General', label: 'General', disabled: true },
            { value: 'c-1', label: 'Dudas', level: 1 },
        ]);
        expect(field('moderation-submit').disabled).toBe(true);

        select.vm.$emit('update:modelValue', 'c-1');
        await nextTick();
        expect(field('moderation-submit').disabled).toBe(false);
    });
});
