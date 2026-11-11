import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
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
        field('moderation-confirmation').click();
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
        await opened({ moveTargets: [{ id: 'c-1', name: 'Dudas', parentName: 'General' }] });

        expect(
            document.body.querySelector('[data-testid="moderation-category"]')?.textContent
        ).toContain('General › Dudas');
        expect(field('moderation-submit').disabled).toBe(true);
    });
});
