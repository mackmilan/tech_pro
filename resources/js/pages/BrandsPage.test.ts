import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BrandsPage from './BrandsPage.vue';

afterEach(() => vi.unstubAllGlobals());

describe('BrandsPage', () => {
  it('lists brands and creates one through the modal form', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'brand-1', name: 'Samsung', active: true }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'brand-2', name: 'Apple', active: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [
        { id: 'brand-1', name: 'Samsung', active: true },
        { id: 'brand-2', name: 'Apple', active: true },
      ] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(BrandsPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.findAll('tbody tr').map((row) => row.text())).toEqual([expect.stringContaining('Samsung')]);
    await wrapper.get('button').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Nova marca');
    const input = document.body.querySelector<HTMLInputElement>('#brand-name');
    expect(input).not.toBeNull();
    input!.value = 'Apple';
    input!.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/brands');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ name: 'Apple', active: true });
    expect(wrapper.text()).toContain('Apple');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    wrapper.unmount();
  });

  it('uploads an image when creating a brand and displays image in the list', async () => {
    const brandWithImage = {
      id: 'brand-10',
      name: 'Xiaomi',
      active: true,
      images: [{ id: 'img-1', url: 'http://localhost:4566/infocell/images/brands/brand-10/logo.png', is_primary: true }],
    };

    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'brand-10', name: 'Xiaomi', active: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'img-1', url: 'http://localhost:4566/infocell/images/brands/brand-10/logo.png', is_primary: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [brandWithImage] }) });

    vi.stubGlobal('fetch', fetch);
    const createObjectURL = vi.fn().mockReturnValue('blob:preview');
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL: vi.fn() });

    const wrapper = mount(BrandsPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    await wrapper.get('button').trigger('click');
    const inputName = document.body.querySelector<HTMLInputElement>('#brand-name');
    inputName!.value = 'Xiaomi';
    inputName!.dispatchEvent(new Event('input', { bubbles: true }));

    const inputFile = document.body.querySelector<HTMLInputElement>('#brand-image');
    const file = new File(['fake image content'], 'logo.png', { type: 'image/png' });
    Object.defineProperty(inputFile, 'files', { value: [file] });
    inputFile!.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();

    expect(createObjectURL).toHaveBeenCalledWith(file);
    expect(document.body.querySelector('img[alt="Prévia do logotipo"]')).not.toBeNull();

    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/brands');
    expect(fetch.mock.calls[2][0]).toBe('/api/v1/brands/brand-10/images');
    expect(fetch.mock.calls[2][1].method).toBe('POST');
    expect(fetch.mock.calls[2][1].body).toBeInstanceOf(FormData);

    expect(wrapper.find('img[alt="Xiaomi"]').exists()).toBe(true);
    wrapper.unmount();
  });
});
