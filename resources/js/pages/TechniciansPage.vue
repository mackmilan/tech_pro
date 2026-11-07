<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

interface Technician {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  document: string | null;
  specialty: string | null;
  commission_rate: string | number;
  active: boolean;
  notes: string | null;
  user_id: string | null;
}

const technicians = ref<Technician[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const technicianPendingDeletion = ref<Technician | null>(null);
const editingId = ref<string | null>(null);

const search = ref('');
const statusFilter = ref('');

const statusFilterOptions: ComboboxOption[] = [
  { value: '', label: 'Todos os status' },
  { value: 'active', label: 'Somente ativos' },
  { value: 'inactive', label: 'Somente inativos' },
];

const form = ref({
  name: '',
  email: '',
  phone: '',
  document: '',
  specialty: '',
  commission_rate: 0,
  active: true,
  notes: '',
});

const columns: DataGridColumn[] = [
  { key: 'name', label: 'Nome' },
  { key: 'specialty', label: 'Especialidade' },
  { key: 'phone', label: 'Telefone' },
  { key: 'email', label: 'E-mail' },
  { key: 'commission_rate', label: 'Comissão' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];

function formatCommission(rate: string | number): string {
  const num = Number(rate) || 0;
  return `${num.toFixed(1).replace('.', ',')}%`;
}

const filteredTechnicians = computed(() => {
  return technicians.value.filter((tech) => {
    if (statusFilter.value === 'active' && !tech.active) return false;
    if (statusFilter.value === 'inactive' && tech.active) return false;
    if (search.value.trim()) {
      const q = search.value.trim().toLowerCase();
      const matchName = tech.name.toLowerCase().includes(q);
      const matchSpecialty = tech.specialty?.toLowerCase().includes(q) ?? false;
      const matchEmail = tech.email?.toLowerCase().includes(q) ?? false;
      const matchPhone = tech.phone?.toLowerCase().includes(q) ?? false;
      const matchDoc = tech.document?.toLowerCase().includes(q) ?? false;
      if (!matchName && !matchSpecialty && !matchEmail && !matchPhone && !matchDoc) return false;
    }
    return true;
  });
});

const rows = computed(() => filteredTechnicians.value.map((tech) => ({
  id: tech.id,
  name: tech.name,
  specialty: tech.specialty || 'Geral',
  phone: tech.phone || '—',
  email: tech.email || '—',
  commission_rate: formatCommission(tech.commission_rate),
  status: tech.active ? 'Ativo' : 'Inativo',
  technician: tech,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadTechnicians() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/technicians?per_page=150', {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar os técnicos.');
    const payload = await response.json() as { data: Technician[] };
    technicians.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao listar técnicos.';
  } finally {
    loading.value = false;
  }
}

function openCreateModal() {
  editingId.value = null;
  form.value = {
    name: '',
    email: '',
    phone: '',
    document: '',
    specialty: '',
    commission_rate: 0,
    active: true,
    notes: '',
  };
  formError.value = '';
  modalOpen.value = true;
}

function openEditModal(tech: Technician) {
  editingId.value = tech.id;
  form.value = {
    name: tech.name,
    email: tech.email ?? '',
    phone: tech.phone ?? '',
    document: tech.document ?? '',
    specialty: tech.specialty ?? '',
    commission_rate: Number(tech.commission_rate) || 0,
    active: tech.active,
    notes: tech.notes ?? '',
  };
  formError.value = '';
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  formError.value = '';
}

async function saveTechnician() {
  formError.value = '';

  if (!form.value.name.trim()) {
    formError.value = 'O nome do técnico é obrigatório.';
    return;
  }

  saving.value = true;
  try {
    const url = editingId.value ? `/api/v1/technicians/${editingId.value}` : '/api/v1/technicians';
    const method = editingId.value ? 'PUT' : 'POST';

    const payload = {
      name: form.value.name.trim(),
      email: form.value.email.trim() || null,
      phone: form.value.phone.trim() || null,
      document: form.value.document.trim() || null,
      specialty: form.value.specialty.trim() || null,
      commission_rate: Number(form.value.commission_rate) || 0,
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

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o técnico.');
    closeModal();
    await loadTechnicians();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro ao salvar técnico.';
  } finally {
    saving.value = false;
  }
}

function confirmDelete(tech: Technician) {
  technicianPendingDeletion.value = tech;
  deleteConfirmationOpen.value = true;
}

async function deleteTechnician() {
  if (!technicianPendingDeletion.value) return;

  deletingId.value = technicianPendingDeletion.value.id;
  try {
    const response = await fetch(`/api/v1/technicians/${technicianPendingDeletion.value.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível excluir o técnico.');
    deleteConfirmationOpen.value = false;
    technicianPendingDeletion.value = null;
    await loadTechnicians();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao excluir técnico.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => {
  void loadTechnicians();
});
</script>

<template>
  <section class="w-full">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <nav class="flex items-center gap-2 text-sm text-slate-500">
          <RouterLink to="/crm" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">CRM</RouterLink>
          <span aria-hidden="true">/</span>
          <span class="font-medium text-slate-900">Técnicos</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Técnicos</h1>
        <p class="mt-1 text-sm text-slate-500">
          Cadastre e gerencie a equipe técnica responsável pelas ordens de serviço.
        </p>
      </div>

      <div>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
          @click="openCreateModal"
        >
          <span aria-hidden="true">+</span>
          <span>Novo técnico</span>
        </button>
      </div>
    </header>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Filtros de Busca -->
    <div class="mb-6 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
      <div class="flex-1">
        <label for="search-tech" class="sr-only">Buscar técnico</label>
        <input
          id="search-tech"
          v-model="search"
          type="search"
          placeholder="Buscar por nome, especialidade, telefone ou e-mail..."
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
    </div>

    <!-- DataGrid -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Carregando técnicos...
      </div>

      <DataGrid
        v-else
        :columns="columns"
        :rows="rows"
        empty-message="Nenhum técnico encontrado."
      >
        <template #cell-name="{ row }">
          <div class="font-medium text-slate-900">{{ row.name }}</div>
          <div v-if="row.technician.document" class="text-xs text-slate-500">CPF: {{ row.technician.document }}</div>
        </template>

        <template #cell-specialty="{ row }">
          <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
            {{ row.specialty }}
          </span>
        </template>

        <template #cell-commission_rate="{ row }">
          <span class="font-medium text-slate-700">{{ row.commission_rate }}</span>
        </template>

        <template #cell-status="{ row }">
          <span
            :class="[
              'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium border',
              row.technician.active
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
              @click="openEditModal(row.technician as Technician)"
            >
              Editar
            </button>
            <button
              type="button"
              class="rounded px-2 py-1 text-xs font-semibold text-red-600 transition-colors hover:text-red-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500"
              @click="confirmDelete(row.technician as Technician)"
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
      :title="editingId ? 'Editar Técnico' : 'Novo Técnico'"
      :description="editingId ? 'Atualize as informações do técnico.' : 'Preencha os dados do novo técnico para cadastro.'"
      max-width-class="max-w-lg"
      @update:model-value="(isOpen) => !isOpen && closeModal()"
    >
      <form class="space-y-4" @submit.prevent="saveTechnician">
        <div>
          <label for="tech-name" class="block text-sm font-medium text-slate-700">Nome completo *</label>
          <input
            id="tech-name"
            v-model="form.name"
            type="text"
            required
            maxlength="255"
            placeholder="Ex: Carlos Eduardo"
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="tech-phone" class="block text-sm font-medium text-slate-700">Telefone / WhatsApp</label>
            <input
              id="tech-phone"
              v-model="form.phone"
              type="tel"
              maxlength="30"
              placeholder="(11) 98765-4321"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="tech-document" class="block text-sm font-medium text-slate-700">CPF / Documento</label>
            <input
              id="tech-document"
              v-model="form.document"
              type="text"
              maxlength="30"
              placeholder="000.000.000-00"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="tech-email" class="block text-sm font-medium text-slate-700">E-mail</label>
            <input
              id="tech-email"
              v-model="form.email"
              type="email"
              maxlength="255"
              placeholder="tecnico@exemplo.com"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="tech-commission" class="block text-sm font-medium text-slate-700">Comissão (%)</label>
            <input
              id="tech-commission"
              v-model.number="form.commission_rate"
              type="number"
              step="0.1"
              min="0"
              max="100"
              placeholder="0.0"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>

        <div>
          <label for="tech-specialty" class="block text-sm font-medium text-slate-700">Especialidade</label>
          <input
            id="tech-specialty"
            v-model="form.specialty"
            type="text"
            maxlength="255"
            placeholder="Ex: Apple, Microssoldagem, Troca de Telas"
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label for="tech-notes" class="block text-sm font-medium text-slate-700">Observações internas</label>
          <textarea
            id="tech-notes"
            v-model="form.notes"
            rows="2"
            placeholder="Horários, preferências de bancada ou outras anotações..."
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
            Técnico ativo para atribuição em ordens de serviço
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
            {{ saving ? 'Salvando...' : 'Salvar técnico' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Modal de Confirmação de Exclusão -->
    <ConfirmationDialog
      v-model="deleteConfirmationOpen"
      title="Excluir técnico"
      :message="`Tem certeza que deseja excluir o técnico “${technicianPendingDeletion?.name ?? ''}”? Esta ação não pode ser desfeita caso não haja registros vinculados.`"
      confirm-label="Excluir técnico"
      :busy="deletingId !== null"
      @confirm="deleteTechnician"
    />
  </section>
</template>

