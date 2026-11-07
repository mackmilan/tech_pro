import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SearchableCombobox from './SearchableCombobox.vue';

const options = [
  { value: 'phone', label: 'Celular' },
  { value: 'case', label: 'Capa' },
  { value: 'charger', label: 'Carregador' },
];

describe('SearchableCombobox', () => {
  it('filters suggestions and supports selecting multiple values', async () => {
    const wrapper = mount(SearchableCombobox, {
      props: { modelValue: [], options, multiple: true, placeholder: 'Buscar produtos' },
    });

    const input = wrapper.get('input[role="combobox"]');
    await input.trigger('focus');
    await input.setValue('cel');

    expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual(['Celular']);
    await wrapper.get('[role="option"]').trigger('click');
    await wrapper.setProps({ modelValue: ['phone'] });
    await input.setValue('ca');
    await wrapper.get('[role="option"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['phone', 'case']]);
    await wrapper.setProps({ modelValue: ['phone', 'case'] });
    expect(wrapper.text()).toContain('Celular');
    expect(wrapper.text()).toContain('Capa');
  });

  it('closes the options when clicking outside the component', async () => {
    const wrapper = mount(SearchableCombobox, {
      attachTo: document.body,
      props: { modelValue: null, options },
    });
    const outside = document.createElement('button');
    document.body.append(outside);

    await wrapper.get('input[role="combobox"]').trigger('focus');
    expect(wrapper.find('[role="listbox"]').exists()).toBe(true);

    outside.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    await wrapper.vm.$nextTick();

    expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    wrapper.unmount();
    outside.remove();
  });

  it('removes focus from the input after selecting an option', async () => {
    const wrapper = mount(SearchableCombobox, {
      attachTo: document.body,
      props: { modelValue: null, options },
    });

    const input = wrapper.get('input[role="combobox"]');
    (input.element as HTMLInputElement).focus();
    expect(document.activeElement).toBe(input.element);
    await wrapper.vm.$nextTick();
    await wrapper.get('[role="option"]').trigger('click');

    expect(document.activeElement).not.toBe(input.element);
    wrapper.unmount();
  });

  it('chooses an autocomplete suggestion in single-selection mode', async () => {
    const wrapper = mount(SearchableCombobox, {
      props: { modelValue: null, options, placeholder: 'Selecione um produto' },
    });

    const input = wrapper.get('input[role="combobox"]');
    await input.trigger('focus');
    await input.setValue('carre');
    expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual(['Carregador']);
    await wrapper.get('[role="option"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')).toEqual([['charger']]);
    expect((wrapper.get('input[role="combobox"]').element as HTMLInputElement).value).toBe('Carregador');
  });
});
