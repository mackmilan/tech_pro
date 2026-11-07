<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

const props = withDefaults(defineProps<{
  modelValue: boolean;
  title: string;
  description?: string;
  maxWidthClass?: string;
}>(), {
  maxWidthClass: 'max-w-lg',
});

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
}>();

const descriptionId = computed(() => props.description ? 'modal-description' : undefined);

function close() {
  emit('update:modelValue', false);
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.modelValue) close();
}

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));

watch(() => props.modelValue, (isOpen) => {
  document.body.classList.toggle('overflow-hidden', isOpen);
}, { immediate: true });

onBeforeUnmount(() => document.body.classList.remove('overflow-hidden'));
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        :aria-describedby="descriptionId"
        :class="['my-auto flex max-h-[calc(100dvh-2rem)] w-full flex-col rounded-xl border border-slate-200 bg-white shadow-xl', maxWidthClass]"
      >
        <header class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
          <div>
            <h2 id="modal-title" class="text-lg font-semibold text-slate-900">{{ title }}</h2>
            <p v-if="description" id="modal-description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
          </div>
          <button
            type="button"
            aria-label="Fechar modal"
            class="rounded-lg p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @click="close"
          >
            <span aria-hidden="true">×</span>
          </button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="shrink-0 border-t border-slate-200 px-5 py-4">
          <slot name="footer" />
        </footer>
      </section>
    </div>
  </Teleport>
</template>
