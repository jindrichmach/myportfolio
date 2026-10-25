<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GridDateRange, GridFilter } from '@/components/ui/grid'

/** Jeden filtr DataGridu s viditelným popiskem nad ovládacím prvkem. `custom` řeší rodič přes slot. */
const props = defineProps<{ filter: Exclude<GridFilter, { type: 'custom' }>; modelValue: unknown }>()
const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>()
const { t } = useI18n()

let uid = 0
const id = `grid-filter-${++uid}`

const inputClass = 'h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm'
const labelClass = 'text-xs font-medium text-neutral-600'
const selected = computed<string[]>(() => (Array.isArray(props.modelValue) ? props.modelValue : []))
const range = computed<GridDateRange>(() => (props.modelValue as GridDateRange) ?? { from: '', to: '' })

/** Více výběrů: rozbalovací panel se zaškrtávacími políčky. */
const open = ref(false)
const root = ref<HTMLElement | null>(null)
const summary = computed(() => {
  const f = props.filter
  if (f.type !== 'multiselect') return ''
  if (selected.value.length === 0) return f.allLabel
  const labels = f.options.filter((o) => selected.value.includes(o.value)).map((o) => o.label)
  return labels.length <= 2 ? labels.join(', ') : t('grid.selected', { n: labels.length })
})
function toggleOption(value: string) {
  const next = selected.value.includes(value) ? selected.value.filter((v) => v !== value) : [...selected.value, value]
  emit('update:modelValue', next)
}
function onPointerDown(event: Event) {
  if (open.value && root.value && !root.value.contains(event.target as Node)) open.value = false
}
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') open.value = false
}
onMounted(() => {
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onPointerDown)
  document.removeEventListener('keydown', onKeydown)
})

function setRange(part: keyof GridDateRange, value: string) {
  emit('update:modelValue', { ...range.value, [part]: value })
}
</script>

<template>
  <div class="flex flex-col gap-1 min-w-0">
    <!-- text -->
    <template v-if="filter.type === 'text'">
      <label :for="id" :class="labelClass">{{ filter.label }}</label>
      <input :id="id" type="search" :value="(modelValue as string) ?? ''" :placeholder="filter.placeholder"
        :class="[inputClass, 'min-w-48']"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" />
    </template>

    <!-- select -->
    <template v-else-if="filter.type === 'select'">
      <label :for="id" :class="labelClass">{{ filter.label }}</label>
      <select :id="id" :value="(modelValue as string) ?? ''" :class="inputClass"
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
        <option value="">{{ filter.allLabel }}</option>
        <option v-for="o in filter.options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </template>

    <!-- multiselect -->
    <div v-else-if="filter.type === 'multiselect'" ref="root" class="relative flex flex-col gap-1">
      <label :for="id" :class="labelClass">{{ filter.label }}</label>
      <button :id="id" type="button" :aria-expanded="open" class="cursor-pointer inline-flex items-center gap-2 text-left" :class="inputClass"
        @click="open = !open">
        <span class="max-w-40 truncate">{{ summary }}</span>
        <span aria-hidden="true" class="text-neutral-400">▾</span>
      </button>
      <div v-if="open" class="absolute top-full z-20 mt-1 min-w-52 max-h-64 overflow-y-auto bg-surface border border-neutral-200 rounded-md shadow-lg p-2 space-y-1">
        <label v-for="o in filter.options" :key="o.value" class="flex items-center gap-2 px-1 py-1 text-sm cursor-pointer hover:bg-primary-50 rounded">
          <input type="checkbox" :checked="selected.includes(o.value)" class="rounded border-neutral-300 text-primary-700" @change="toggleOption(o.value)" />
          {{ o.label }}
        </label>
        <button v-if="selected.length" type="button" class="cursor-pointer w-full text-left px-1 py-1 text-xs text-primary-700 hover:underline"
          @click="emit('update:modelValue', [])">{{ t('grid.clear') }}</button>
      </div>
    </div>

    <!-- daterange -->
    <div v-else-if="filter.type === 'daterange'" class="flex flex-col gap-1">
      <span :id="id" :class="labelClass">{{ filter.label }}</span>
      <div class="inline-flex flex-wrap items-center gap-1.5">
        <input type="date" :value="range.from" :aria-label="`${filter.label} – ${t('grid.from')}`" :class="inputClass"
          @input="setRange('from', ($event.target as HTMLInputElement).value)" />
        <span class="text-neutral-400 text-sm">–</span>
        <input type="date" :value="range.to" :aria-label="`${filter.label} – ${t('grid.to')}`" :class="inputClass"
          @input="setRange('to', ($event.target as HTMLInputElement).value)" />
      </div>
    </div>
  </div>
</template>
