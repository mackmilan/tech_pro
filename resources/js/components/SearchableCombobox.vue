<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export interface ComboboxOption {
  value: string;
  label: string;
  disabled?: boolean;
}

const props = withDefaults(defineProps<{
  modelValue: string | string[] | null;
  options: ComboboxOption[];
  multiple?: boolean;
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  id?: string;
  inputTestId?: string;
  emptyMessage?: string;
}>(), {
  multiple: false,
  placeholder: 'Buscar ou selecionar…',
  disabled: false,
  required: false,
  emptyMessage: 'Nenhuma opção encontrada.',
});
const emit = defineEmits<{
  'update:modelValue': [value: string | string[] | null];
}>();

const query = ref('');
const isOpen = ref(false);
const activeIndex = ref(-1);
const selectedValues = computed(() => props.multiple
  ? (Array.isArray(props.modelValue) ? props.modelValue : [])
  : (typeof props.modelValue === 'string' ? [props.modelValue] : []));
const selectedOptions = computed(() => selectedValues.value
  .map((value) => props.options.find((option) => option.value === value))
  .filter((option): option is ComboboxOption => Boolean(option)));
const singleLabel = computed(() => selectedOptions.value[0]?.label ?? '');
const normalizedQuery = computed(() => query.value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase());
const filteredOptions = computed(() => props.options.filter((option) => {
  const normalizedLabel = option.label.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
  return normalizedLabel.includes(normalizedQuery.value);
}));
const activeDescendant = computed(() => activeIndex.value >= 0
  ? `${props.id ?? 'combobox'}-option-${activeIndex.value}`
  : undefined);
const rootElement = ref<HTMLElement | null>(null);

function handleOutsidePointerDown(event: PointerEvent) {
  if (event.target instanceof Node && !rootElement.value?.contains(event.target)) {
    isOpen.value = false;
    activeIndex.value = -1;
    if (!props.multiple) query.value = singleLabel.value;
  }
}

onMounted(() => document.addEventListener('pointerdown', handleOutsidePointerDown));
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleOutsidePointerDown));

watch(() => props.modelValue, () => {
  if (!props.multiple && !isOpen.value) query.value = singleLabel.value;
});
watch(() => props.options, () => {
  if (!props.multiple && !isOpen.value) query.value = singleLabel.value;
}, { immediate: true });

function open() {
  if (props.disabled) return;
  isOpen.value = true;
  activeIndex.value = -1;
  if (!props.multiple) query.value = '';
}

function select(option: ComboboxOption) {
  if (option.disabled) return;
  (document.activeElement as HTMLElement | null)?.blur();
  if (props.multiple) {
    const values = selectedValues.value.includes(option.value)
      ? selectedValues.value.filter((value) => value !== option.value)
      : [...selectedValues.value, option.value];
    emit('update:modelValue', values);
    query.value = '';
    activeIndex.value = -1;
    isOpen.value = true;
    return;
  }

  emit('update:modelValue', option.value);
  query.value = option.label;
  isOpen.value = false;
  activeIndex.value = -1;
}

function remove(value: string) {
  if (props.disabled) return;
  const ids = selectedValues.value.filter((selected) => selected !== value);
  emit('update:modelValue', ids);
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    if (!isOpen.value) open();
    const enabledOptions = filteredOptions.value.filter((option) => !option.disabled);
    if (enabledOptions.length) {
      const current = activeIndex.value < 0 ? -1 : enabledOptions.findIndex((option) => option.value === filteredOptions.value[activeIndex.value]?.value);
      const next = enabledOptions[(current + 1) % enabledOptions.length];
      activeIndex.value = filteredOptions.value.findIndex((option) => option.value === next.value);
    }
  } else if (event.key === 'ArrowUp' && isOpen.value) {
    event.preventDefault();
    const enabledOptions = filteredOptions.value.filter((option) => !option.disabled);
    if (enabledOptions.length) {
      const current = activeIndex.value < 0 ? 0 : enabledOptions.findIndex((option) => option.value === filteredOptions.value[activeIndex.value]?.value);
      const previous = enabledOptions[(current - 1 + enabledOptions.length) % enabledOptions.length];
      activeIndex.value = filteredOptions.value.findIndex((option) => option.value === previous.value);
    }
  } else if (event.key === 'Enter' && isOpen.value && activeIndex.value >= 0) {
    event.preventDefault();
    const option = filteredOptions.value[activeIndex.value];
    if (option) select(option);
  } else if (event.key === 'Escape') {
    isOpen.value = false;
    activeIndex.value = -1;
    if (!props.multiple) query.value = singleLabel.value;
  } else if (event.key === 'Backspace' && props.multiple && !query.value && selectedValues.value.length) {
    remove(selectedValues.value[selectedValues.value.length - 1]);
  }
}
</script>

<template>
  <div ref="rootElement" class="relative w-full">
    <div class="flex min-h-10 flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 transition-colors focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-100" :class="{ 'cursor-not-allowed bg-slate-50 opacity-60': disabled }">
      <template v-if="multiple">
        <span v-for="option in selectedOptions" :key="option.value" class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-sm text-indigo-700">
          {{ option.label }}
          <button v-if="!disabled" type="button" class="rounded text-indigo-700 hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" :aria-label="`Remover ${option.label}`" @click="remove(option.value)">×</button>
        </span>
      </template>
      <input
        :id="id"
        :data-testid="inputTestId"
        v-model="query"
        type="text"
        role="combobox"
        :placeholder="multiple || !singleLabel ? placeholder : ''"
        :disabled="disabled"
        :required="required && selectedValues.length === 0"
        :aria-required="required || undefined"
        :aria-expanded="isOpen"
        :aria-controls="`${id ?? 'combobox'}-listbox`"
        :aria-activedescendant="activeDescendant"
        aria-autocomplete="list"
        aria-haspopup="listbox"
        class="min-w-24 flex-1 border-0 bg-transparent py-1 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0 disabled:cursor-not-allowed"
        @focus="open"
        @keydown="handleKeydown"
      >
    </div>
    <ul v-if="isOpen" :id="`${id ?? 'combobox'}-listbox`" role="listbox" :aria-multiselectable="multiple || undefined" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg" @mousedown.prevent>
      <li v-for="(option, index) in filteredOptions" :id="`${id ?? 'combobox'}-option-${index}`" :key="option.value" role="option" :aria-selected="selectedValues.includes(option.value)" :aria-disabled="option.disabled || undefined" class="flex cursor-pointer items-center justify-between px-3 py-2 text-sm text-slate-700 hover:bg-indigo-50" :class="{ 'bg-indigo-50': activeIndex === index, 'cursor-not-allowed opacity-50': option.disabled }" @click="select(option)">
        <span>{{ option.label }}</span>
        <span v-if="selectedValues.includes(option.value)" aria-hidden="true" class="text-indigo-600">✓</span>
      </li>
      <li v-if="filteredOptions.length === 0" class="px-3 py-2 text-sm text-slate-500">{{ emptyMessage }}</li>
    </ul>
  </div>
</template>
