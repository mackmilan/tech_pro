import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import ConfirmationDialog from './ConfirmationDialog.vue';

afterEach(() => document.body.classList.remove('overflow-hidden'));

describe('ConfirmationDialog', () => {
  it('shows a confirmation prompt with cancel and confirm actions', () => {
    const wrapper = mount(ConfirmationDialog, {
      props: {
        modelValue: true,
        title: 'Excluir categoria',
        message: 'Excluir “Acessórios”?',
      },
      attachTo: document.body,
    });

    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
    expect(document.body.textContent).toContain('Excluir “Acessórios”?');
    expect(Array.from(document.body.querySelectorAll('button')).map((button) => button.textContent)).toContain('Cancelar');
    expect(Array.from(document.body.querySelectorAll('button')).map((button) => button.textContent)).toContain('Confirmar');
    wrapper.unmount();
  });

  it('emits cancel and confirm events from the corresponding actions', async () => {
    const wrapper = mount(ConfirmationDialog, {
      props: {
        modelValue: true,
        title: 'Excluir categoria',
        message: 'Tem certeza?',
      },
      attachTo: document.body,
    });

    await document.body.querySelector<HTMLButtonElement>('button[aria-label="Fechar modal"]')?.click();
    await Array.from(document.body.querySelectorAll('button')).find((button) => button.textContent === 'Confirmar')?.click();

    expect(wrapper.emitted('update:modelValue')).toEqual([[false], [false]]);
    expect(wrapper.emitted('confirm')).toHaveLength(1);
    wrapper.unmount();
  });
});
