<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

export interface Customer {
  id: string;
  name: string;
  document: string | null;
  email: string | null;
  phone: string | null;
  source: string | null;
  active: boolean;
  notes: string | null;
  created_at?: string;
  updated_at?: string;
}

const customers = ref<Customer[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const customerPendingDeletion = ref<Customer | null>(null);
const editingId = ref<string | null>(null);

const search = ref('');
const statusFilter = ref('');
const sourceFilter = ref('');

const statusFilterOptions: ComboboxOption[] = [
  { value: '', label: 'Todos os status' },
  { value: 'active', label: 'Somente ativos' },
  { value: 'inactive', label: 'Somente inativos' },
];

const customerSourceOptions: ComboboxOption[] = [
  { value: '', label: 'Selecione uma origem (opcional)' },
  { value: 'Balcão / Loja física', label: 'Balcão / Loja física' },
  { value: 'WhatsApp', label: 'WhatsApp' },
  { value: 'Indicação', label: 'Indicação' },
  { value: 'Instagram', label: 'Instagram' },
  { value: 'Google', label: 'Google' },
  { value: 'Telefone', label: 'Telefone' },
  { value: 'Outro', label: 'Outro' },
];

const sourceFilterOptions: ComboboxOption[] = [
  { value: '', label: 'Todas as origens' },
  { value: 'Balcão / Loja física', label: 'Balcão / Loja física' },
  { value: 'WhatsApp', label: 'WhatsApp' },
  { value: 'Indicação', label: 'Indicação' },
  { value: 'Instagram', label: 'Instagram' },
  { value: 'Google', label: 'Google' },
  { value: 'Telefone', label: 'Telefone' },
  { value: 'Outro', label: 'Outro' },
];

const form = ref({
  name: '',
  document: '',
  email: '',
  phone: '',
  source: '',
  active: true,
  notes: '',
});

const columns: DataGridColumn[] = [
  { key: 'name', label: 'Cliente' },
  { key: 'phone', label: 'Telefone' },
  { key: 'email', label: 'E-mail' },
  { key: 'source', label: 'Origem' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];

const filteredCustomers = computed(() => {
  return customers.value.filter((cust) => {
    if (statusFilter.value === 'active' && !cust.active) return false;
    if (statusFilter.value === 'inactive' && cust.active) return false;
    if (sourceFilter.value && cust.source !== sourceFilter.value) return false;

    if (search.value.trim()) {
      const q = search.value.trim().toLowerCase();
      const matchName = cust.name.toLowerCase().includes(q);
      const matchDoc = cust.document?.toLowerCase().includes(q) ?? false;
      const matchEmail = cust.email?.toLowerCase().includes(q) ?? false;
      const matchPhone = cust.phone?.toLowerCase().includes(q) ?? false;
      const matchSource = cust.source?.toLowerCase().includes(q) ?? false;
      if (!matchName && !matchDoc && !matchEmail && !matchPhone && !matchSource) return false;
    }

    return true;
  });
});

const rows = computed(() =>
  filteredCustomers.value.map((cust) => ({
    id: cust.id,
    name: cust.name,
    phone: cust.phone || '—',
    email: cust.email || '—',
    source: cust.source || '—',
    status: cust.active ? 'Ativo' : 'Inativo',
    customer: cust,
  }))
);

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = (await response.json()) as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadCustomers() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/customers?per_page=150', {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar os clientes.');
    const payload = (await response.json()) as { data: Customer[] };
    customers.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao listar clientes.';
  } finally {
    loading.value = false;
  }
}

function openCreateModal() {
  editingId.value = null;
  form.value = {
    name: '',
    document: '',
    email: '',
    phone: '',
    source: '',
    active: true,
    notes: '',
  };
  formError.value = '';
  modalOpen.value = true;
}

function openEditModal(cust: Customer) {
  editingId.value = cust.id;
  form.value = {
    name: cust.name,
    document: cust.document ?? '',
    email: cust.email ?? '',
    phone: cust.phone ?? '',
    source: cust.source ?? '',
    active: cust.active,
    notes: cust.notes ?? '',
  };
  formError.value = '';
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  formError.value = '';
}

async function saveCustomer() {
  formError.value = '';

  if (!form.value.name.trim()) {
    formError.value = 'O nome do cliente é obrigatório.';
    return;
  }

  saving.value = true;
  try {
    const url = editingId.value ? `/api/v1/customers/${editingId.value}` : '/api/v1/customers';
    const method = editingId.value ? 'PUT' : 'POST';

    const payload = {
      name: form.value.name.trim(),
      document: form.value.document.trim() || null,
      email: form.value.email.trim() || null,
      phone: form.value.phone.trim() || null,
      source: form.value.source.trim() || null,
      active: form.value.active,
      notes: form.value.notes.trim() || null,
    };

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

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o cliente.');
    closeModal();
    await loadCustomers();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro ao salvar cliente.';
  } finally {
    saving.value = false;
  }
}

function confirmDelete(cust: Customer) {
  customerPendingDeletion.value = cust;
  deleteConfirmationOpen.value = true;
}

async function deleteCustomer() {
  if (!customerPendingDeletion.value) return;

  deletingId.value = customerPendingDeletion.value.id;
  try {
    const response = await fetch(`/api/v1/customers/${customerPendingDeletion.value.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível excluir o cliente.');
    deleteConfirmationOpen.value = false;
    customerPendingDeletion.value = null;
    await loadCustomers();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao excluir cliente.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => {
  void loadCustomers();
});
</script>

<template>
  <section class="w-full">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <nav class="flex items-center gap-2 text-sm text-slate-500">
          <RouterLink
            to="/crm"
            class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
          >
            ← Voltar ao CRM
          </RouterLink>
          <span aria-hidden="true">·</span>
          <span class="font-medium text-slate-900">Cadastros</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Clientes</h1>
        <p class="mt-1 text-sm text-slate-500">
          Cadastre e gerencie os clientes para atendimento, vendas e ordens de serviço.
        </p>
      </div>

      <div>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
          @click="openCreateModal"
        >
          <span aria-hidden="true">+</span>
          <span>Novo cliente</span>
        </button>
      </div>
    </header>

    <div
      v-if="error"
      role="alert"
      class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
    >
      {{ error }}
    </div>

    <!-- Filtros de Busca -->
    <div
      class="mb-6 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center"
    >
      <div class="flex-1">
        <label for="search-customer" class="sr-only">Buscar cliente</label>
        <input
          id="search-customer"
          v-model="search"
          type="search"
          placeholder="Buscar por nome, CPF/CNPJ, telefone ou e-mail..."
          class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        />
      </div>

      <div class="w-full sm:w-56">
        <label for="filter-status" class="sr-only">Filtrar por status</label>
        <SearchableCombobox
          id="filter-status"
          v-model="statusFilter"
          :options="statusFilterOptions"
          placeholder="Status..."
        />
      </div>

      <div class="w-full sm:w-56">
        <label for="filter-source" class="sr-only">Filtrar por origem</label>
        <SearchableCombobox
          id="filter-source"
          v-model="sourceFilter"
          :options="sourceFilterOptions"
          placeholder="Origem..."
        />
      </div>
    </div>

    <!-- DataGrid -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Carregando clientes...
      </div>

      <DataGrid
        v-else
        :columns="columns"
        :rows="rows"
        empty-message="Nenhum cliente cadastrado."
      >
        <template #cell-name="{ row }">
          <div class="font-medium text-slate-900">{{ row.name }}</div>
          <div v-if="row.customer.document" class="text-xs text-slate-500">
            {{ row.customer.document }}
          </div>
        </template>

        <template #cell-phone="{ row }">
          <span class="text-slate-700">{{ row.phone }}</span>
        </template>

        <template #cell-email="{ row }">
          <span class="text-slate-700">{{ row.email }}</span>
        </template>

        <template #cell-source="{ row }">
          <span
            v-if="row.customer.source"
            class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700"
          >
            {{ row.source }}
          </span>
          <span v-else class="text-slate-400">—</span>
        </template>

        <template #cell-status="{ row }">
          <span
            :class="[
              'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium border',
              row.customer.active
                ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                : 'bg-slate-100 text-slate-600 border-slate-200'
            ]"
          >
            {{ row.status }}
          </span>
        </template>

        <template #actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="rounded px-2 py-1 text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
              :aria-label="`Editar ${row.customer.name}`"
              @click="openEditModal(row.customer as Customer)"
            >
              Editar
            </button>
            <button
              type="button"
              class="rounded px-2 py-1 text-xs font-semibold text-red-600 transition-colors hover:text-red-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-40"
              :disabled="deletingId === row.customer.id"
              :aria-label="`Excluir ${row.customer.name}`"
              @click="confirmDelete(row.customer as Customer)"
            >
              Excluir
            </button>
          </div>
        </template>
      </DataGrid>
    </div>

    <!-- Modal Form (Cadastrar / Editar) -->
    <Modal
      v-model="modalOpen"
      :title="editingId ? 'Editar cliente' : 'Novo cliente'"
      :description="editingId ? 'Atualize as informações cadastrais do cliente.' : 'Preencha os dados do cliente para cadastro.'"
      max-width-class="max-w-lg"
      @update:model-value="(isOpen) => !isOpen && closeModal()"
    >
      <form class="space-y-4" @submit.prevent="saveCustomer">
        <div>
          <label for="customer-name" class="block text-sm font-medium text-slate-700">Nome completo *</label>
          <input
            id="customer-name"
            v-model="form.name"
            type="text"
            required
            maxlength="255"
            placeholder="Ex: João da Silva"
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="customer-document" class="block text-sm font-medium text-slate-700">CPF / CNPJ</label>
            <input
              id="customer-document"
              v-model="form.document"
              type="text"
              maxlength="30"
              placeholder="000.000.000-00"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="customer-phone" class="block text-sm font-medium text-slate-700">Telefone / WhatsApp</label>
            <input
              id="customer-phone"
              v-model="form.phone"
              type="tel"
              maxlength="30"
              placeholder="(11) 98765-4321"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>

        <div>
          <label for="customer-email" class="block text-sm font-medium text-slate-700">E-mail</label>
          <input
            id="customer-email"
            v-model="form.email"
            type="email"
            maxlength="255"
            placeholder="cliente@exemplo.com"
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label for="customer-source" class="block text-sm font-medium text-slate-700">Origem do cliente</label>
          <div class="mt-1">
            <SearchableCombobox
              id="customer-source"
              v-model="form.source"
              :options="customerSourceOptions"
              placeholder="Como conheceu a loja..."
            />
          </div>
        </div>

        <div>
          <label for="customer-notes" class="block text-sm font-medium text-slate-700">Observações</label>
          <textarea
            id="customer-notes"
            v-model="form.notes"
            rows="2"
            placeholder="Anotações internas, preferências ou histórico do cliente..."
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          ></textarea>
        </div>

        <div class="pt-1">
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input
              v-model="form.active"
              type="checkbox"
              class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            />
            Cliente ativo no cadastro
          </label>
        </div>

        <div v-if="formError" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
          {{ formError }}
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="closeModal"
          >
            Cancelar
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50"
          >
            {{ saving ? 'Salvando...' : 'Salvar cliente' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Modal de Confirmação de Exclusão -->
    <ConfirmationDialog
      v-model="deleteConfirmationOpen"
      title="Excluir cliente"
      :message="`Tem certeza que deseja excluir o cliente “${customerPendingDeletion?.name ?? ''}”? Esta ação não pode ser desfeita caso não haja registros vinculados.`"
      confirm-label="Excluir cliente"
      :busy="deletingId !== null"
      @confirm="deleteCustomer"
    />
  </section>
</template>

