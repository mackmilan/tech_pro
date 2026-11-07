<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import Modal from '../components/Modal.vue';

interface Branch {
  id: string;
  name: string;
  code: string;
}

interface Customer {
  id: string;
  name: string;
  document: string | null;
  phone: string | null;
  email?: string | null;
  source?: string | null;
  notes?: string | null;
}

interface Technician {
  id: string;
  name: string;
  specialty?: string | null;
  phone?: string | null;
  email?: string | null;
  active?: boolean;
}

interface Product {
  id: string;
  sku: string;
  name: string;
  sale_price: string | number;
}

interface Service {
  id: string;
  name: string;
  code: string | null;
  price: string | number;
  estimated_minutes: number | null;
  warranty_days: number | null;
}

interface ChecklistDefinition {
  id: string;
  name: string;
  category: string | null;
  stage: 'entry' | 'exit' | 'both';
  is_required: boolean;
  sort_order: number;
}

interface ChecklistImage {
  id: string;
  imageable_id: string;
  imageable_type: string;
  url: string | null;
  original_name: string | null;
  mime_type: string | null;
  size: number | null;
  is_primary: boolean;
  sort_order: number;
}

interface FormChecklist {
  id: string;
  service_checklist_id: string;
  item_name: string;
  category: string;
  stage: 'entry' | 'exit';
  status: 'ok' | 'defect' | 'not_tested' | 'not_applicable';
  notes: string;
  images?: ChecklistImage[];
  pendingFiles?: Array<{ file: File; url: string }>;
  uploading?: boolean;
}

interface FormItem {
  id: string; // client-side key
  type: 'service' | 'product';
  product_id: string;
  service_id: string;
  description: string;
  quantity: number;
  unit_price: number;
  discount: number;
  notes: string;
}

interface ServiceOrderResponse {
  id: string;
  branch_id: string;
  customer_id: string;
  technician_id: string | null;
  number: string;
  status: 'open' | 'in_analysis' | 'approved' | 'in_progress' | 'waiting_parts' | 'completed' | 'delivered' | 'cancelled';
  priority: 'low' | 'normal' | 'high' | 'urgent';
  device_brand: string | null;
  device_model: string;
  device_imei_serial: string | null;
  device_color: string | null;
  device_password: string | null;
  device_condition: string | null;
  accessories: string | null;
  defect_reported: string;
  defect_diagnosed: string | null;
  solution_applied: string | null;
  technical_notes: string | null;
  opened_at: string;
  expected_at: string | null;
  completed_at: string | null;
  delivered_at: string | null;
  services_amount: string | number;
  products_amount: string | number;
  discount: string | number;
  total: string | number;
  notes: string | null;
  warranty_terms: string | null;
  items?: Array<{
    id: string;
    type: 'service' | 'product';
    product_id: string | null;
    service_id: string | null;
    description: string;
    quantity: number;
    unit_price: string | number;
    discount: string | number;
    total: string | number;
    notes: string | null;
  }>;
  checklists?: Array<{
    id: string;
    service_checklist_id: string | null;
    item_name: string;
    category: string | null;
    stage: 'entry' | 'exit';
    status: 'ok' | 'defect' | 'not_tested' | 'not_applicable';
    notes: string | null;
    verified_at: string | null;
    images?: ChecklistImage[];
  }>;
}

const route = useRoute();
const router = useRouter();

const orderId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null));
const editing = computed(() => orderId.value !== null);

const branches = ref<Branch[]>([]);
const customers = ref<Customer[]>([]);
const technicians = ref<Technician[]>([]);
const products = ref<Product[]>([]);
const services = ref<Service[]>([]);
const checklistDefs = ref<ChecklistDefinition[]>([]);

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');

function toIsoDateInput(isoString: string | null | undefined): string {
  if (!isoString) return '';
  return isoString.slice(0, 10);
}

const today = new Date().toISOString().slice(0, 10);

const form = ref({
  branch_id: '',
  customer_id: '',
  technician_id: '',
  number: '',
  status: 'open',
  priority: 'normal',
  device_brand: '',
  device_model: '',
  device_imei_serial: '',
  device_color: '',
  device_password: '',
  device_condition: '',
  accessories: '',
  defect_reported: '',
  defect_diagnosed: '',
  solution_applied: '',
  technical_notes: '',
  opened_at: today,
  expected_at: '',
  completed_at: '',
  delivered_at: '',
  discount: 0,
  notes: '',
  warranty_terms: 'Garantia legal de 90 dias conforme CDC sobre serviços e peças aplicadas.',
  items: [] as FormItem[],
  checklists: [] as FormChecklist[],
});

const branchOptions = computed<ComboboxOption[]>(() =>
  branches.value.map((b) => ({ value: b.id, label: `${b.name} (${b.code})` }))
);

const customerOptions = computed<ComboboxOption[]>(() =>
  customers.value.map((c) => ({
    value: c.id,
    label: c.phone ? `${c.name} — ${c.phone}` : (c.document ? `${c.name} (${c.document})` : c.name),
  }))
);

const technicianOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Nenhum técnico atribuído' },
  ...technicians.value.map((t) => ({
    value: t.id,
    label: t.specialty ? `${t.name} (${t.specialty})` : t.name,
  })),
]);

const statusOptions: ComboboxOption[] = [
  { value: 'open', label: 'Aberta' },
  { value: 'in_analysis', label: 'Em análise / Orçamento' },
  { value: 'approved', label: 'Aprovada' },
  { value: 'in_progress', label: 'Em andamento' },
  { value: 'waiting_parts', label: 'Aguardando peças' },
  { value: 'completed', label: 'Concluída' },
  { value: 'delivered', label: 'Entregue' },
  { value: 'cancelled', label: 'Cancelada' },
];

const priorityOptions: ComboboxOption[] = [
  { value: 'low', label: 'Baixa' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

const itemTypeOptions: ComboboxOption[] = [
  { value: 'service', label: 'Serviço' },
  { value: 'product', label: 'Peça / Produto' },
];

const productOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Item avulso / Não catalogado' },
  ...products.value.map((p) => ({
    value: p.id,
    label: `${p.name} (SKU: ${p.sku}) — ${formatPrice(Number(p.sale_price) || 0)}`,
  })),
]);

const serviceOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Serviço avulso / Não catalogado' },
  ...services.value.map((s) => ({
    value: s.id,
    label: s.code ? `[${s.code}] ${s.name} — ${formatPrice(Number(s.price) || 0)}` : `${s.name} — ${formatPrice(Number(s.price)) || 0}`,
  })),
]);

function formatPrice(value: number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

function getItemSubtotal(item: FormItem): number {
  const qty = Number(item.quantity) || 0;
  const unit = Number(item.unit_price) || 0;
  const disc = Number(item.discount) || 0;
  return Math.max(0, qty * unit - disc);
}

const servicesSubtotal = computed(() =>
  form.value.items
    .filter((i) => i.type === 'service')
    .reduce((sum, item) => sum + getItemSubtotal(item), 0)
);

const productsSubtotal = computed(() =>
  form.value.items
    .filter((i) => i.type === 'product')
    .reduce((sum, item) => sum + getItemSubtotal(item), 0)
);

const grossTotal = computed(() => servicesSubtotal.value + productsSubtotal.value);

const netTotal = computed(() => {
  const calc = grossTotal.value - (Number(form.value.discount) || 0);
  return Math.max(0, calc);
});

function addItem(type: 'service' | 'product' = 'service') {
  form.value.items.push({
    id: `item-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    type,
    product_id: '',
    service_id: '',
    description: '',
    quantity: 1,
    unit_price: 0,
    discount: 0,
    notes: '',
  });
}

function removeItem(index: number) {
  form.value.items.splice(index, 1);
}

function handleProductSelect(item: FormItem, productId: string | string[] | null) {
  const id = typeof productId === 'string' ? productId : '';
  item.product_id = id;
  if (id) {
    const product = products.value.find((p) => p.id === id);
    if (product) {
      if (!item.description || item.description.trim() === '') {
        item.description = product.name;
      }
      if (item.unit_price === 0) {
        item.unit_price = Number(product.sale_price) || 0;
      }
    }
  }
}

function handleServiceSelect(item: FormItem, serviceId: string | string[] | null) {
  const id = typeof serviceId === 'string' ? serviceId : '';
  item.service_id = id;
  if (id) {
    const service = services.value.find((s) => s.id === id);
    if (service) {
      if (!item.description || item.description.trim() === '') {
        item.description = service.name;
      }
      if (item.unit_price === 0) {
        item.unit_price = Number(service.price) || 0;
      }
    }
  }
}

function initDefaultChecklist() {
  const entryDefs = checklistDefs.value.filter((d) => d.stage === 'entry' || d.stage === 'both');
  form.value.checklists = entryDefs.map((def) => ({
    id: `chk-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    service_checklist_id: def.id,
    item_name: def.name,
    category: def.category ?? '',
    stage: 'entry' as const,
    status: 'not_tested' as const,
    notes: '',
    images: [],
    pendingFiles: [],
    uploading: false,
  }));
}

function addChecklistItem(stage: 'entry' | 'exit') {
  form.value.checklists.push({
    id: `chk-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    service_checklist_id: '',
    item_name: '',
    category: '',
    stage,
    status: 'not_tested',
    notes: '',
    images: [],
    pendingFiles: [],
    uploading: false,
  });
}

function removeChecklistItem(id: string) {
  const item = form.value.checklists.find((c) => c.id === id);
  if (item?.pendingFiles) {
    for (const f of item.pendingFiles) {
      URL.revokeObjectURL(f.url);
    }
  }
  form.value.checklists = form.value.checklists.filter((c) => c.id !== id);
}

function setChecklistStatus(item: FormChecklist, status: 'ok' | 'defect' | 'not_tested' | 'not_applicable') {
  item.status = status;
}

const previewingImage = ref<{ url: string; name?: string } | null>(null);
const photoModalOpen = computed({
  get: () => previewingImage.value !== null,
  set: (val: boolean) => {
    if (!val) previewingImage.value = null;
  },
});
const checklistImageError = ref<Record<string, string>>({});

async function handleChecklistPhotoSelect(chk: FormChecklist, event: Event) {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  if (files.length === 0) return;

  delete checklistImageError.value[chk.id];

  if (chk.id && !chk.id.startsWith('chk-')) {
    chk.uploading = true;
    try {
      for (const file of files) {
        const formData = new FormData();
        formData.append('image', file);
        const res = await fetch(`/api/v1/service-order-checklists/${chk.id}/images`, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
          },
          credentials: 'same-origin',
          body: formData,
        });
        if (!res.ok) throw await responseError(res, 'Erro ao enviar foto do checklist.');
        const savedImg = await res.json() as ChecklistImage;
        if (!chk.images) chk.images = [];
        chk.images.push(savedImg);
      }
    } catch (ex) {
      checklistImageError.value[chk.id] = ex instanceof Error ? ex.message : 'Erro ao enviar imagem.';
    } finally {
      chk.uploading = false;
    }
  } else {
    if (!chk.pendingFiles) chk.pendingFiles = [];
    for (const file of files) {
      chk.pendingFiles.push({
        file,
        url: URL.createObjectURL(file),
      });
    }
  }

  target.value = '';
}

async function removeChecklistImage(chk: FormChecklist, img: ChecklistImage) {
  delete checklistImageError.value[chk.id];
  try {
    const res = await fetch(`/api/v1/images/${img.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!res.ok) throw await responseError(res, 'Não foi possível excluir a foto.');
    if (chk.images) {
      chk.images = chk.images.filter((i) => i.id !== img.id);
    }
  } catch (ex) {
    checklistImageError.value[chk.id] = ex instanceof Error ? ex.message : 'Erro ao excluir foto.';
  }
}

function removePendingChecklistPhoto(chk: FormChecklist, index: number) {
  if (!chk.pendingFiles) return;
  const removed = chk.pendingFiles.splice(index, 1);
  if (removed[0]) {
    URL.revokeObjectURL(removed[0].url);
  }
}

function openPhotoPreview(url: string, name?: string) {
  previewingImage.value = { url, name };
}

const activeChecklistTab = ref<'entry' | 'exit'>('entry');
const entryChecklists = computed(() => form.value.checklists.filter((c) => c.stage === 'entry'));
const exitChecklists = computed(() => form.value.checklists.filter((c) => c.stage === 'exit'));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadLookups() {
  const [branchesRes, customersRes, techniciansRes, productsRes, servicesRes, checklistsRes] = await Promise.all([
    fetch('/api/v1/branches?per_page=100', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/customers?per_page=200', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/technicians?per_page=100&active=1', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/products?per_page=200&active=1', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/services?per_page=200&active=1', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/service-checklists?per_page=200&active=1', { headers: { Accept: 'application/json' } }),
  ]);

  if (!branchesRes.ok) throw await responseError(branchesRes, 'Não foi possível carregar as filiais.');
  if (!customersRes.ok) throw await responseError(customersRes, 'Não foi possível carregar os clientes.');
  if (!techniciansRes.ok) throw await responseError(techniciansRes, 'Não foi possível carregar os técnicos.');
  if (!productsRes.ok) throw await responseError(productsRes, 'Não foi possível carregar os produtos.');
  if (!servicesRes.ok) throw await responseError(servicesRes, 'Não foi possível carregar os serviços.');

  branches.value = (await branchesRes.json() as { data: Branch[] }).data;
  customers.value = (await customersRes.json() as { data: Customer[] }).data;
  technicians.value = (await techniciansRes.json() as { data: Technician[] }).data;
  products.value = (await productsRes.json() as { data: Product[] }).data;
  services.value = (await servicesRes.json() as { data: Service[] }).data;

  if (checklistsRes.ok) {
    checklistDefs.value = (await checklistsRes.json() as { data: ChecklistDefinition[] }).data;
    if (!editing.value && form.value.checklists.length === 0) {
      initDefaultChecklist();
    }
  }

  if (!editing.value && branches.value.length > 0 && !form.value.branch_id) {
    form.value.branch_id = branches.value[0].id;
  }
}

async function loadOrder(id: string) {
  const response = await fetch(`/api/v1/service-orders/${id}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw await responseError(response, 'Não foi possível carregar a ordem de serviço.');
  const order = await response.json() as ServiceOrderResponse;

  form.value = {
    branch_id: order.branch_id,
    customer_id: order.customer_id,
    technician_id: order.technician_id ?? '',
    number: order.number,
    status: order.status,
    priority: order.priority,
    device_brand: order.device_brand ?? '',
    device_model: order.device_model,
    device_imei_serial: order.device_imei_serial ?? '',
    device_color: order.device_color ?? '',
    device_password: order.device_password ?? '',
    device_condition: order.device_condition ?? '',
    accessories: order.accessories ?? '',
    defect_reported: order.defect_reported,
    defect_diagnosed: order.defect_diagnosed ?? '',
    solution_applied: order.solution_applied ?? '',
    technical_notes: order.technical_notes ?? '',
    opened_at: toIsoDateInput(order.opened_at) || today,
    expected_at: toIsoDateInput(order.expected_at),
    completed_at: toIsoDateInput(order.completed_at),
    delivered_at: toIsoDateInput(order.delivered_at),
    discount: Number(order.discount) || 0,
    notes: order.notes ?? '',
    warranty_terms: order.warranty_terms ?? '',
    items: (order.items && order.items.length > 0)
      ? order.items.map((i) => ({
          id: i.id,
          type: i.type,
          product_id: i.product_id ?? '',
          service_id: i.service_id ?? '',
          description: i.description,
          quantity: Number(i.quantity) || 1,
          unit_price: Number(i.unit_price) || 0,
          discount: Number(i.discount) || 0,
          notes: i.notes ?? '',
        }))
      : [],
    checklists: (order.checklists && order.checklists.length > 0)
      ? order.checklists.map((c) => ({
          id: c.id,
          service_checklist_id: c.service_checklist_id ?? '',
          item_name: c.item_name,
          category: c.category ?? '',
          stage: c.stage,
          status: c.status,
          notes: c.notes ?? '',
          images: c.images ? [...c.images] : [],
          pendingFiles: [],
          uploading: false,
        }))
      : [],
  };
}

const quickCustomerModalOpen = ref(false);
const quickCustomerSaving = ref(false);
const quickCustomerError = ref('');

const quickCustomerForm = ref({
  name: '',
  phone: '',
  document: '',
  email: '',
  source: '',
  notes: '',
});

const customerSourceOptions: ComboboxOption[] = [
  { value: '', label: 'Não informada' },
  { value: 'Balcão', label: 'Balcão' },
  { value: 'WhatsApp', label: 'WhatsApp' },
  { value: 'Indicação', label: 'Indicação' },
  { value: 'Instagram', label: 'Instagram' },
  { value: 'Google', label: 'Google' },
  { value: 'Telefone', label: 'Telefone' },
  { value: 'Outro', label: 'Outro' },
];

function openQuickCustomerModal() {
  quickCustomerForm.value = {
    name: '',
    phone: '',
    document: '',
    email: '',
    source: '',
    notes: '',
  };
  quickCustomerError.value = '';
  quickCustomerModalOpen.value = true;
}

function closeQuickCustomerModal() {
  quickCustomerModalOpen.value = false;
  quickCustomerError.value = '';
}

async function saveQuickCustomer() {
  quickCustomerError.value = '';
  if (!quickCustomerForm.value.name.trim()) {
    quickCustomerError.value = 'O nome do cliente é obrigatório.';
    return;
  }

  quickCustomerSaving.value = true;
  try {
    const payload = {
      name: quickCustomerForm.value.name.trim(),
      phone: quickCustomerForm.value.phone.trim() || null,
      document: quickCustomerForm.value.document.trim() || null,
      email: quickCustomerForm.value.email.trim() || null,
      source: quickCustomerForm.value.source.trim() || null,
      notes: quickCustomerForm.value.notes.trim() || null,
    };

    const response = await fetch('/api/v1/customers', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      throw await responseError(response, 'Não foi possível cadastrar o cliente.');
    }

    const created = (await response.json()) as Customer;
    customers.value.unshift(created);
    form.value.customer_id = created.id;
    closeQuickCustomerModal();
  } catch (exception) {
    quickCustomerError.value = exception instanceof Error ? exception.message : 'Erro ao cadastrar cliente.';
  } finally {
    quickCustomerSaving.value = false;
  }
}

async function saveOrder() {
  formError.value = '';

  if (!form.value.branch_id) {
    formError.value = 'Selecione a filial responsável.';
    return;
  }

  if (!form.value.customer_id) {
    formError.value = 'Selecione o cliente da ordem de serviço.';
    return;
  }

  if (!form.value.device_model.trim()) {
    formError.value = 'Informe o modelo do aparelho/equipamento.';
    return;
  }

  if (!form.value.defect_reported.trim()) {
    formError.value = 'Descreva o defeito relatado pelo cliente.';
    return;
  }

  for (let i = 0; i < form.value.items.length; i++) {
    const item = form.value.items[i];
    if (!item.description.trim()) {
      formError.value = `Informe a descrição do item na linha ${i + 1}.`;
      return;
    }
    if (!item.quantity || item.quantity < 1) {
      formError.value = `A quantidade na linha ${i + 1} deve ser de pelo menos 1.`;
      return;
    }
    if (item.unit_price < 0) {
      formError.value = `O valor unitário na linha ${i + 1} não pode ser negativo.`;
      return;
    }
  }

  if (Number(form.value.discount) > grossTotal.value) {
    formError.value = 'O desconto geral não pode superar o valor total dos itens.';
    return;
  }

  saving.value = true;
  const payload = {
    branch_id: form.value.branch_id,
    customer_id: form.value.customer_id,
    technician_id: form.value.technician_id || null,
    number: form.value.number.trim() || undefined,
    status: form.value.status,
    priority: form.value.priority,
    device_brand: form.value.device_brand.trim() || null,
    device_model: form.value.device_model.trim(),
    device_imei_serial: form.value.device_imei_serial.trim() || null,
    device_color: form.value.device_color.trim() || null,
    device_password: form.value.device_password.trim() || null,
    device_condition: form.value.device_condition.trim() || null,
    accessories: form.value.accessories.trim() || null,
    defect_reported: form.value.defect_reported.trim(),
    defect_diagnosed: form.value.defect_diagnosed.trim() || null,
    solution_applied: form.value.solution_applied.trim() || null,
    technical_notes: form.value.technical_notes.trim() || null,
    opened_at: form.value.opened_at || undefined,
    expected_at: form.value.expected_at || null,
    completed_at: form.value.completed_at || null,
    delivered_at: form.value.delivered_at || null,
    discount: Number(form.value.discount) || 0,
    notes: form.value.notes.trim() || null,
    warranty_terms: form.value.warranty_terms.trim() || null,
    items: form.value.items.map((item) => ({
      type: item.type,
      product_id: item.type === 'product' ? (item.product_id || null) : null,
      service_id: item.type === 'service' ? (item.service_id || null) : null,
      description: item.description.trim(),
      quantity: Number(item.quantity) || 1,
      unit_price: Number(item.unit_price) || 0,
      discount: Number(item.discount) || 0,
      notes: item.notes.trim() || null,
    })),
    checklists: form.value.checklists
      .filter((chk) => chk.item_name.trim().length > 0)
      .map((chk) => ({
        id: chk.id.startsWith('chk-') ? undefined : chk.id,
        service_checklist_id: chk.service_checklist_id || null,
        item_name: chk.item_name.trim(),
        category: chk.category.trim() || null,
        stage: chk.stage,
        status: chk.status,
        notes: chk.notes.trim() || null,
      })),
  };

  try {
    const url = editing.value ? `/api/v1/service-orders/${orderId.value}` : '/api/v1/service-orders';
    const method = editing.value ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar a ordem de serviço.');

    const saved = await response.json() as ServiceOrder;

    // Upload pending staged checklist photos
    if (saved.checklists && saved.checklists.length > 0) {
      const validFormChecklists = form.value.checklists.filter((chk) => chk.item_name.trim().length > 0);
      for (let i = 0; i < validFormChecklists.length; i++) {
        const formChk = validFormChecklists[i];
        if (formChk.pendingFiles && formChk.pendingFiles.length > 0) {
          const targetSaved = saved.checklists.find((c) => c.id === formChk.id)
            ?? saved.checklists.find((c) => c.stage === formChk.stage && c.item_name === formChk.item_name.trim())
            ?? saved.checklists[i];

          if (targetSaved) {
            for (const pending of formChk.pendingFiles) {
              const formData = new FormData();
              formData.append('image', pending.file);
              await fetch(`/api/v1/service-order-checklists/${targetSaved.id}/images`, {
                method: 'POST',
                headers: {
                  Accept: 'application/json',
                  'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: formData,
              });
              URL.revokeObjectURL(pending.url);
            }
          }
        }
      }
    }

    await router.push('/crm/ordens-de-servico');
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar a ordem de serviço.';
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  loading.value = true;
  error.value = '';
  try {
    await loadLookups();
    if (orderId.value) {
      await loadOrder(orderId.value);
    }
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao carregar dados do formulário.';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="w-full">
    <header class="mb-8">
      <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <RouterLink to="/crm" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">CRM</RouterLink>
        <span aria-hidden="true">/</span>
        <RouterLink to="/crm/ordens-de-servico" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">Ordens de serviço</RouterLink>
        <span aria-hidden="true">/</span>
        <span class="font-medium text-slate-900">{{ editing ? 'Editar' : 'Nova' }}</span>
      </nav>
      <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">
        {{ editing ? `Editar Ordem de Serviço ${form.number || ''}` : 'Nova Ordem de Serviço' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        Preencha os dados do cliente, aparelho, laudo técnico e peças/serviços para registro.
      </p>
    </header>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <div v-if="formError" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ formError }}
    </div>

    <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">
      Carregando dados...
    </div>

    <form v-else class="space-y-8" @submit.prevent="saveOrder">
      <!-- Seção 1: Identificação e Atendimento -->
      <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-2 text-base font-semibold text-slate-900">1. Identificação e Atendimento</legend>
        <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div>
            <label for="so-branch" class="block text-sm font-medium text-slate-700">Filial *</label>
            <SearchableCombobox
              id="so-branch"
              v-model="form.branch_id"
              :options="branchOptions"
              placeholder="Selecione a filial..."
              required
              class="mt-1"
            />
          </div>

          <div>
            <div class="flex items-center justify-between">
              <label for="so-customer" class="block text-sm font-medium text-slate-700">Cliente *</label>
              <button
                id="btn-quick-customer"
                type="button"
                class="inline-flex items-center gap-1 rounded text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                @click="openQuickCustomerModal"
              >
                <span aria-hidden="true">+</span>
                <span>Novo cliente</span>
              </button>
            </div>
            <SearchableCombobox
              id="so-customer"
              v-model="form.customer_id"
              :options="customerOptions"
              placeholder="Buscar cliente..."
              required
              class="mt-1"
            />
          </div>

          <div>
            <label for="so-number" class="block text-sm font-medium text-slate-700">Número da OS</label>
            <input
              id="so-number"
              v-model="form.number"
              type="text"
              placeholder="Automático (ex: OS-202611-ABCD)"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-status" class="block text-sm font-medium text-slate-700">Status *</label>
            <SearchableCombobox
              id="so-status"
              v-model="form.status"
              :options="statusOptions"
              class="mt-1"
            />
          </div>

          <div>
            <label for="so-priority" class="block text-sm font-medium text-slate-700">Prioridade *</label>
            <SearchableCombobox
              id="so-priority"
              v-model="form.priority"
              :options="priorityOptions"
              class="mt-1"
            />
          </div>

          <div>
            <label for="so-technician" class="block text-sm font-medium text-slate-700">Técnico Responsável</label>
            <SearchableCombobox
              id="so-technician"
              v-model="form.technician_id"
              :options="technicianOptions"
              placeholder="Selecione o técnico..."
              class="mt-1"
            />
          </div>

          <div>
            <label for="so-opened-at" class="block text-sm font-medium text-slate-700">Data de Abertura</label>
            <input
              id="so-opened-at"
              v-model="form.opened_at"
              type="date"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-expected-at" class="block text-sm font-medium text-slate-700">Previsão de Entrega</label>
            <input
              id="so-expected-at"
              v-model="form.expected_at"
              type="date"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div v-if="form.status === 'completed' || form.status === 'delivered'">
            <label for="so-completed-at" class="block text-sm font-medium text-slate-700">Data de Conclusão</label>
            <input
              id="so-completed-at"
              v-model="form.completed_at"
              type="date"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div v-if="form.status === 'delivered'">
            <label for="so-delivered-at" class="block text-sm font-medium text-slate-700">Data de Entrega</label>
            <input
              id="so-delivered-at"
              v-model="form.delivered_at"
              type="date"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>
      </fieldset>

      <!-- Seção 2: Aparelho e Equipamento -->
      <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-2 text-base font-semibold text-slate-900">2. Aparelho e Equipamento</legend>
        <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div>
            <label for="so-device-brand" class="block text-sm font-medium text-slate-700">Marca</label>
            <input
              id="so-device-brand"
              v-model="form.device_brand"
              type="text"
              placeholder="Ex: Apple, Samsung, Motorola, Xiaomi"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-device-model" class="block text-sm font-medium text-slate-700">Modelo do Aparelho *</label>
            <input
              id="so-device-model"
              v-model="form.device_model"
              type="text"
              required
              placeholder="Ex: iPhone 13 Pro Max 256GB"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-device-imei" class="block text-sm font-medium text-slate-700">IMEI / Número de Série</label>
            <input
              id="so-device-imei"
              v-model="form.device_imei_serial"
              type="text"
              placeholder="Ex: 356789012345678"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-device-color" class="block text-sm font-medium text-slate-700">Cor</label>
            <input
              id="so-device-color"
              v-model="form.device_color"
              type="text"
              placeholder="Ex: Preto espacial, Azul, Prata"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-device-password" class="block text-sm font-medium text-slate-700">Senha / Padrão de Desbloqueio</label>
            <input
              id="so-device-password"
              v-model="form.device_password"
              type="text"
              placeholder="Para testes técnicos"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="so-accessories" class="block text-sm font-medium text-slate-700">Acessórios Deixados</label>
            <input
              id="so-accessories"
              v-model="form.accessories"
              type="text"
              placeholder="Ex: Capa de proteção, cabo USB, sem chip"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div class="sm:col-span-2 lg:col-span-3 xl:col-span-4">
            <label for="so-device-condition" class="block text-sm font-medium text-slate-700">Condições Físicas na Entrada</label>
            <textarea
              id="so-device-condition"
              v-model="form.device_condition"
              rows="2"
              placeholder="Ex: Tela riscada, marcas de impacto na quina superior direita, tampa traseira íntegra"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>
        </div>
      </fieldset>

      <!-- Seção 3: Diagnóstico e Problema -->
      <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-2 text-base font-semibold text-slate-900">3. Problema e Diagnóstico Técnico</legend>
        <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <div class="sm:col-span-2 lg:col-span-3">
            <label for="so-defect-reported" class="block text-sm font-medium text-slate-700">Defeito Relatado pelo Cliente *</label>
            <textarea
              id="so-defect-reported"
              v-model="form.defect_reported"
              rows="3"
              required
              placeholder="Descreva o problema informado pelo cliente..."
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>

          <div>
            <label for="so-defect-diagnosed" class="block text-sm font-medium text-slate-700">Diagnóstico Técnico</label>
            <textarea
              id="so-defect-diagnosed"
              v-model="form.defect_diagnosed"
              rows="3"
              placeholder="Laudo técnico constatado na bancada..."
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>

          <div>
            <label for="so-solution-applied" class="block text-sm font-medium text-slate-700">Solução Aplicada</label>
            <textarea
              id="so-solution-applied"
              v-model="form.solution_applied"
              rows="3"
              placeholder="Procedimentos e reparos realizados..."
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>

          <div class="sm:col-span-2 lg:col-span-1">
            <label for="so-technical-notes" class="block text-sm font-medium text-slate-700">Observações Técnicas Internas</label>
            <textarea
              id="so-technical-notes"
              v-model="form.technical_notes"
              rows="3"
              placeholder="Informações para a equipe interna (não impressas para o cliente)"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>
        </div>
      </fieldset>

      <!-- Seção 4: Serviços e Peças (Itens) -->
      <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <legend class="px-2 text-base font-semibold text-slate-900">4. Peças e Serviços</legend>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
              @click="addItem('service')"
            >
              + Adicionar Serviço
            </button>
            <button
              type="button"
              class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
              @click="addItem('product')"
            >
              + Adicionar Peça / Produto
            </button>
          </div>
        </div>

        <div v-if="form.items.length === 0" class="mt-4 rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">
          Nenhum item ou serviço adicionado até o momento. Clique nos botões acima para incluir mão de obra ou peças.
        </div>

        <div v-else class="mt-4 space-y-4">
          <div
            v-for="(item, idx) in form.items"
            :key="item.id"
            class="rounded-lg border border-slate-200 bg-slate-50/50 p-4"
          >
            <div class="flex items-center justify-between pb-3 border-b border-slate-200">
              <span class="text-xs font-semibold text-slate-700">
                Item #{{ idx + 1 }} — {{ item.type === 'service' ? 'Serviço' : 'Peça / Produto' }}
              </span>
              <button
                type="button"
                class="text-xs text-red-600 hover:text-red-800"
                @click="removeItem(idx)"
              >
                Remover item
              </button>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
              <div class="sm:col-span-1 lg:col-span-2">
                <label :for="`item-type-${idx}`" class="block text-xs font-medium text-slate-700">Tipo</label>
                <SearchableCombobox
                  :id="`item-type-${idx}`"
                  v-model="item.type"
                  :options="itemTypeOptions"
                  class="mt-1"
                />
              </div>

              <div v-if="item.type === 'product'" class="sm:col-span-1 lg:col-span-4">
                <label :for="`item-prod-${idx}`" class="block text-xs font-medium text-slate-700">Catálogo de Produtos</label>
                <SearchableCombobox
                  :id="`item-prod-${idx}`"
                  v-model="item.product_id"
                  :options="productOptions"
                  placeholder="Selecione um produto..."
                  class="mt-1"
                  @update:model-value="(val) => handleProductSelect(item, val)"
                />
              </div>

              <div v-else-if="item.type === 'service'" class="sm:col-span-1 lg:col-span-4">
                <label :for="`item-srv-${idx}`" class="block text-xs font-medium text-slate-700">Catálogo de Serviços</label>
                <SearchableCombobox
                  :id="`item-srv-${idx}`"
                  v-model="item.service_id"
                  :options="serviceOptions"
                  placeholder="Selecione um serviço..."
                  class="mt-1"
                  @update:model-value="(val) => handleServiceSelect(item, val)"
                />
              </div>

              <div class="sm:col-span-2 lg:col-span-6">
                <label :for="`item-desc-${idx}`" class="block text-xs font-medium text-slate-700">Descrição do Item *</label>
                <input
                  :id="`item-desc-${idx}`"
                  v-model="item.description"
                  type="text"
                  required
                  placeholder="Ex: Troca de tela touch screen / Mão de obra"
                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div class="sm:col-span-1 lg:col-span-3">
                <label :for="`item-qty-${idx}`" class="block text-xs font-medium text-slate-700">Quantidade</label>
                <input
                  :id="`item-qty-${idx}`"
                  v-model.number="item.quantity"
                  type="number"
                  min="1"
                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div class="sm:col-span-1 lg:col-span-3">
                <label :for="`item-price-${idx}`" class="block text-xs font-medium text-slate-700">Valor Unitário (R$)</label>
                <input
                  :id="`item-price-${idx}`"
                  v-model.number="item.unit_price"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div class="sm:col-span-1 lg:col-span-3">
                <label :for="`item-disc-${idx}`" class="block text-xs font-medium text-slate-700">Desconto (R$)</label>
                <input
                  :id="`item-disc-${idx}`"
                  v-model.number="item.discount"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div class="sm:col-span-1 lg:col-span-3 flex flex-col justify-end">
                <span class="text-xs text-slate-500">Subtotal</span>
                <span class="text-sm font-bold text-slate-900 py-1.5">
                  {{ formatPrice(getItemSubtotal(item)) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </fieldset>

      <!-- Seção 5: Checklist de Execução Técnica -->
      <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4">
          <div>
            <legend class="px-2 text-base font-semibold text-slate-900">5. Checklist de Execução Técnica (Bancada)</legend>
            <p class="px-2 text-xs text-slate-500">
              Inspeção dos componentes do aparelho para comprovação de estado na entrada e entrega.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
              @click="addChecklistItem('entry')"
            >
              + Item na Entrada
            </button>
            <button
              type="button"
              class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
              @click="addChecklistItem('exit')"
            >
              + Item na Saída
            </button>
            <button
              v-if="form.checklists.length === 0"
              type="button"
              class="inline-flex items-center rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
              @click="initDefaultChecklist"
            >
              Carregar Checklist Padrão
            </button>
          </div>
        </div>

        <!-- Abas de Etapa -->
        <div class="mt-4 flex border-b border-slate-200">
          <button
            type="button"
            :class="[
              activeChecklistTab === 'entry'
                ? 'border-indigo-600 text-indigo-600 font-semibold'
                : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300',
              'border-b-2 px-4 py-2 text-sm transition-colors focus-visible:outline-none'
            ]"
            @click="activeChecklistTab = 'entry'"
          >
            Inspeção de Entrada ({{ entryChecklists.length }})
          </button>
          <button
            type="button"
            :class="[
              activeChecklistTab === 'exit'
                ? 'border-indigo-600 text-indigo-600 font-semibold'
                : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300',
              'border-b-2 px-4 py-2 text-sm transition-colors focus-visible:outline-none'
            ]"
            @click="activeChecklistTab = 'exit'"
          >
            Inspeção de Saída / Entrega ({{ exitChecklists.length }})
          </button>
        </div>

        <!-- Lista de itens da etapa ativa -->
        <div class="mt-4">
          <div
            v-if="(activeChecklistTab === 'entry' ? entryChecklists : exitChecklists).length === 0"
            class="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500"
          >
            Nenhum item verificado nesta etapa.
            <button
              type="button"
              class="font-semibold text-indigo-600 hover:underline"
              @click="addChecklistItem(activeChecklistTab)"
            >
              Adicionar verificação
            </button>
          </div>

          <div v-else class="space-y-3">
            <div
              v-for="chk in (activeChecklistTab === 'entry' ? entryChecklists : exitChecklists)"
              :key="chk.id"
              class="rounded-lg border border-slate-200 bg-slate-50/50 p-3.5 transition-colors"
            >
              <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-12 lg:items-center">
                <div class="lg:col-span-4">
                  <div class="flex items-center gap-2">
                    <span v-if="chk.category" class="rounded bg-slate-200/80 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">
                      {{ chk.category }}
                    </span>
                    <input
                      v-model="chk.item_name"
                      type="text"
                      required
                      placeholder="Nome do componente ou teste..."
                      class="block w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-900 focus:border-indigo-500 focus:outline-none"
                    />
                  </div>
                </div>

                <!-- Botoes de Status -->
                <div class="lg:col-span-4">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <button
                      type="button"
                      :class="[
                        chk.status === 'ok'
                          ? 'bg-emerald-600 text-white font-semibold'
                          : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50',
                        'rounded-md px-2.5 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-indigo-500'
                      ]"
                      @click="setChecklistStatus(chk, 'ok')"
                    >
                      ✓ OK
                    </button>
                    <button
                      type="button"
                      :class="[
                        chk.status === 'defect'
                          ? 'bg-red-600 text-white font-semibold'
                          : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50',
                        'rounded-md px-2.5 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-indigo-500'
                      ]"
                      @click="setChecklistStatus(chk, 'defect')"
                    >
                      ✕ Defeito
                    </button>
                    <button
                      type="button"
                      :class="[
                        chk.status === 'not_tested'
                          ? 'bg-amber-500 text-white font-semibold'
                          : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50',
                        'rounded-md px-2.5 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-indigo-500'
                      ]"
                      @click="setChecklistStatus(chk, 'not_tested')"
                    >
                      Pendente
                    </button>
                    <button
                      type="button"
                      :class="[
                        chk.status === 'not_applicable'
                          ? 'bg-slate-600 text-white font-semibold'
                          : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50',
                        'rounded-md px-2.5 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-indigo-500'
                      ]"
                      @click="setChecklistStatus(chk, 'not_applicable')"
                    >
                      N/A
                    </button>
                  </div>
                </div>

                <!-- Observação do teste -->
                <div class="lg:col-span-3">
                  <input
                    v-model="chk.notes"
                    type="text"
                    placeholder="Observação técnica (opcional)..."
                    class="block w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none"
                  />
                </div>

                <!-- Remover -->
                <div class="flex justify-end lg:col-span-1">
                  <button
                    type="button"
                    title="Remover teste"
                    class="rounded p-1 text-xs text-slate-400 hover:text-red-600 focus-visible:outline-none"
                    @click="removeChecklistItem(chk.id)"
                  >
                    ✕
                  </button>
                </div>
              </div>

              <!-- Anexos de imagem do checklist -->
              <div class="mt-2.5 pt-2 border-t border-slate-200/80">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-[11px] font-medium text-slate-500 mr-1 flex items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Fotos:
                  </span>

                  <!-- Fotos salvas -->
                  <div
                    v-for="img in chk.images"
                    :key="img.id"
                    class="group relative h-10 w-10 rounded-md border border-slate-200 bg-white overflow-hidden shadow-xs cursor-pointer flex-shrink-0"
                    :title="img.original_name ?? 'Ver foto'"
                    @click="openPhotoPreview(img.url ?? '', img.original_name ?? 'Foto')"
                  >
                    <img
                      :src="img.url ?? ''"
                      :alt="img.original_name ?? 'Foto do checklist'"
                      class="h-full w-full object-cover transition-transform group-hover:scale-105"
                    />
                    <button
                      type="button"
                      class="absolute top-0 right-0 flex h-4 w-4 items-center justify-center rounded-bl bg-red-600 text-[10px] text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-700"
                      :aria-label="`Excluir imagem ${img.original_name ?? ''}`"
                      @click.stop="removeChecklistImage(chk, img)"
                    >
                      ✕
                    </button>
                  </div>

                  <!-- Fotos pendentes -->
                  <div
                    v-for="(pending, pIdx) in chk.pendingFiles"
                    :key="pIdx"
                    class="group relative h-10 w-10 rounded-md border border-dashed border-indigo-300 bg-indigo-50/50 overflow-hidden shadow-xs cursor-pointer flex-shrink-0"
                    :title="pending.file.name"
                    @click="openPhotoPreview(pending.url, pending.file.name)"
                  >
                    <img
                      :src="pending.url"
                      :alt="pending.file.name"
                      class="h-full w-full object-cover opacity-80"
                    />
                    <button
                      type="button"
                      class="absolute top-0 right-0 flex h-4 w-4 items-center justify-center rounded-bl bg-red-600 text-[10px] text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-700"
                      :aria-label="`Remover foto pendente ${pending.file.name}`"
                      @click.stop="removePendingChecklistPhoto(chk, pIdx)"
                    >
                      ✕
                    </button>
                  </div>

                  <!-- Botão de upload -->
                  <label
                    :for="`chk-photo-${chk.id}`"
                    :class="[
                      'inline-flex items-center gap-1 rounded border border-dashed border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600 hover:border-indigo-400 hover:text-indigo-600 cursor-pointer transition-colors',
                      chk.uploading ? 'opacity-50 pointer-events-none' : ''
                    ]"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ chk.uploading ? 'Enviando...' : 'Adicionar foto' }}</span>
                    <input
                      :id="`chk-photo-${chk.id}`"
                      type="file"
                      accept="image/*"
                      multiple
                      class="sr-only"
                      @change="handleChecklistPhotoSelect(chk, $event)"
                    />
                  </label>

                  <span v-if="checklistImageError[chk.id]" class="text-[11px] text-red-600 font-medium">
                    {{ checklistImageError[chk.id] }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </fieldset>

      <!-- Seção 6: Totais e Termos de Garantia -->
      <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-2 text-base font-semibold text-slate-900">6. Totais e Termos de Garantia</legend>
        <div class="mt-4 grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
          <div class="space-y-4 xl:col-span-2">
            <div>
              <label for="so-warranty" class="block text-sm font-medium text-slate-700">Termos de Garantia</label>
              <textarea
                id="so-warranty"
                v-model="form.warranty_terms"
                rows="3"
                placeholder="Ex: Garantia de 90 dias conforme CDC sobre os serviços prestados..."
                class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              ></textarea>
            </div>

            <div>
              <label for="so-notes" class="block text-sm font-medium text-slate-700">Observações Gerais / Cliente</label>
              <textarea
                id="so-notes"
                v-model="form.notes"
                rows="2"
                placeholder="Informações adicionais para constar na ordem impressa..."
                class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              ></textarea>
            </div>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3 xl:col-span-1">
            <div class="flex justify-between text-sm text-slate-600">
              <span>Total Serviços:</span>
              <span class="font-medium text-slate-900">{{ formatPrice(servicesSubtotal) }}</span>
            </div>

            <div class="flex justify-between text-sm text-slate-600">
              <span>Total Peças / Produtos:</span>
              <span class="font-medium text-slate-900">{{ formatPrice(productsSubtotal) }}</span>
            </div>

            <div class="flex items-center justify-between text-sm text-slate-600">
              <label for="so-discount" class="font-medium text-slate-700">Desconto Geral (R$):</label>
              <input
                id="so-discount"
                v-model.number="form.discount"
                type="number"
                step="0.01"
                min="0"
                class="w-32 rounded-lg border border-slate-300 px-2.5 py-1 text-right text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              />
            </div>

            <div class="border-t border-slate-200 pt-3 flex justify-between items-baseline">
              <span class="text-base font-bold text-slate-900">Total da OS:</span>
              <span class="text-xl font-bold text-indigo-700">{{ formatPrice(netTotal) }}</span>
            </div>
          </div>
        </div>
      </fieldset>

      <!-- Botões de Ação -->
      <div class="flex flex-col-reverse gap-3 pt-4 border-t border-slate-200 sm:flex-row sm:items-center sm:justify-end">
        <RouterLink
          to="/crm/ordens-de-servico"
          class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 sm:w-auto"
        >
          Cancelar
        </RouterLink>

        <button
          type="submit"
          :disabled="saving"
          class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50 sm:w-auto"
        >
          {{ saving ? 'Salvando...' : (editing ? 'Salvar alterações' : 'Registrar ordem de serviço') }}
        </button>
      </div>
    </form>

    <!-- Modal de Cadastro Rápido de Cliente -->
    <Modal
      v-model="quickCustomerModalOpen"
      title="Cadastro Rápido de Cliente"
      description="Cadastre um novo cliente para vincular imediatamente a esta ordem de serviço."
      max-width-class="max-w-lg"
      @update:model-value="(isOpen) => !isOpen && closeQuickCustomerModal()"
    >
      <form class="space-y-4" @submit.prevent="saveQuickCustomer">
        <div>
          <label for="quick-cust-name" class="block text-sm font-medium text-slate-700">Nome completo *</label>
          <input
            id="quick-cust-name"
            v-model="quickCustomerForm.name"
            type="text"
            required
            maxlength="255"
            placeholder="Ex: Maria Silva"
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="quick-cust-phone" class="block text-sm font-medium text-slate-700">Telefone / WhatsApp</label>
            <input
              id="quick-cust-phone"
              v-model="quickCustomerForm.phone"
              type="tel"
              maxlength="30"
              placeholder="(11) 98765-4321"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="quick-cust-document" class="block text-sm font-medium text-slate-700">CPF / CNPJ</label>
            <input
              id="quick-cust-document"
              v-model="quickCustomerForm.document"
              type="text"
              maxlength="30"
              placeholder="000.000.000-00"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="quick-cust-email" class="block text-sm font-medium text-slate-700">E-mail</label>
            <input
              id="quick-cust-email"
              v-model="quickCustomerForm.email"
              type="email"
              maxlength="255"
              placeholder="cliente@email.com"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="quick-cust-source" class="block text-sm font-medium text-slate-700">Origem</label>
            <SearchableCombobox
              id="quick-cust-source"
              v-model="quickCustomerForm.source"
              :options="customerSourceOptions"
              placeholder="Selecione..."
              class="mt-1"
            />
          </div>
        </div>

        <div>
          <label for="quick-cust-notes" class="block text-sm font-medium text-slate-700">Observações</label>
          <textarea
            id="quick-cust-notes"
            v-model="quickCustomerForm.notes"
            rows="2"
            placeholder="Anotações sobre o cliente..."
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          ></textarea>
        </div>

        <div v-if="quickCustomerError" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
          {{ quickCustomerError }}
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="closeQuickCustomerModal"
          >
            Cancelar
          </button>
          <button
            type="submit"
            :disabled="quickCustomerSaving"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50"
          >
            {{ quickCustomerSaving ? 'Cadastrando...' : 'Cadastrar e selecionar' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Modal de visualização de foto ampliada -->
    <Modal
      v-model="photoModalOpen"
      title="Visualização da Foto"
      max-width-class="max-w-3xl"
    >
      <div class="flex flex-col items-center p-2">
        <img
          v-if="previewingImage"
          :src="previewingImage.url"
          :alt="previewingImage.name || 'Foto do checklist'"
          class="max-h-[70vh] w-auto max-w-full rounded-lg object-contain shadow-sm"
        />
        <p v-if="previewingImage?.name" class="mt-2 text-xs text-slate-500">
          {{ previewingImage.name }}
        </p>
      </div>
    </Modal>
  </section>
</template>

