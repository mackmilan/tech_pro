import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import Modal from './Modal.vue';

function mountModal(options: Parameters<typeof mount<typeof Modal>>[1] = {}) {
  return mount(Modal, {
    global: { stubs: { Teleport: true } },
    ...options,
  });
}

afterEach(() => {
  document.body.classList.remove('overflow-hidden');
});

describe('Modal', () => {
  it('renders an accessible dialog with its title and slotted content when open', () => {
    const wrapper = mountModal({
      props: { modelValue: true, title: 'Editar categoria' },
      slots: { default: '<form>Formulário da categoria</form>' },
    });

    expect(wrapper.get('div.fixed').classes()).toContain('backdrop-blur-sm');
    expect(wrapper.get('div.fixed').classes()).toContain('p-4');
    expect(wrapper.get('[role="dialog"]').classes()).toContain('max-h-[calc(100dvh-2rem)]');
    expect(wrapper.get('[role="dialog"]').classes()).toContain('flex');
    expect(wrapper.get('[role="dialog"] > div.p-5').classes()).toContain('overflow-y-auto');
    expect(wrapper.get('[role="dialog"]').attributes('aria-modal')).toBe('true');
    expect(wrapper.text()).toContain('Editar categoria');
    expect(wrapper.text()).toContain('Formulário da categoria');
    wrapper.unmount();
  });

  it('does not render the dialog while closed', () => {
    const wrapper = mountModal({ props: { modelValue: false, title: 'Editar categoria' } });

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('closes when Escape is pressed', async () => {
    const wrapper = mountModal({ props: { modelValue: true, title: 'Editar categoria' } });

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await wrapper.vm.$nextTick();

    expect(wrapper.emitted('update:modelValue')).toEqual([[false]]);
    wrapper.unmount();
  });

  it('keeps the dialog open when the backdrop is clicked', async () => {
    const wrapper = mountModal({ props: { modelValue: true, title: 'Editar categoria' } });

    await wrapper.get('div.fixed').trigger('click.self');

    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });

  it('emits an update when its close button is clicked', async () => {
    const wrapper = mountModal({ props: { modelValue: true, title: 'Editar categoria' } });

    await wrapper.get('button[aria-label="Fechar modal"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')).toEqual([[false]]);
    wrapper.unmount();
  });
});
