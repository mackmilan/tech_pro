<script setup lang="ts">
import Modal from './Modal.vue';

const props = withDefaults(defineProps<{
  modelValue: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  busy?: boolean;
}>(), {
  confirmLabel: 'Confirmar',
  cancelLabel: 'Cancelar',
  busy: false,
});

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  confirm: [];
}>();

function cancel() {
  if (!props.busy) emit('update:modelValue', false);
}

function confirm() {
  if (props.busy) return;
  emit('confirm');
  emit('update:modelValue', false);
}
</script>

<template>
  <Modal
    :model-value="modelValue"
    :title="title"
    :description="message"
    @update:model-value="(isOpen) => !isOpen && cancel()"
  >
    <div class="flex justify-end gap-2">
      <button type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50" @click="cancel">{{ cancelLabel }}</button>
      <button type="button" :disabled="busy" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50" @click="confirm">{{ confirmLabel }}</button>
    </div>
  </Modal>
</template>
