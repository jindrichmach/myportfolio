<script setup lang="ts" generic="T extends string | number">
import { computed, nextTick, ref, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ComboboxRoot, ComboboxAnchor, ComboboxInput, ComboboxTrigger, ComboboxContent, ComboboxViewport,
  ComboboxItem, ComboboxGroup, ComboboxLabel,
} from 'reka-ui'
import Icon from '@/components/ui/Icon.vue'

type Option = { value: T; label: string; secondary?: string; group?: string }

/**
 * Select s vyhledáváním (Reka UI Combobox, bez vlastních stylů) — náhrada nativního `<select>` ve formulářích.
 * Hledá v `label` i `secondary`; `group` nahrazuje `<optgroup>` (položky bez skupiny jsou nahoře).
 * Hodnota, která není mezi options (např. '' / null jako „nevybráno“), zobrazí placeholder.
 * `inputId` patří viditelnému inputu (`<label for>`); `required` hlídá skrytý input, aby fungovala validace formuláře.
 * Seznam se renderuje na místě (bez portálu) s fixed pozicí — v Modalu ho neořízne scroll těla a drží se Tab trap.
 */
const props = withDefaults(defineProps<{
  modelValue: T | null
  options: Option[]
  placeholder?: string
  noResultsLabel?: string
  clearable?: boolean
  disabled?: boolean
  required?: boolean
  size?: 'md' | 'sm'
  /** Server-side režim: options dodává rodič podle @search (žádný client-side filtr). */
  remote?: boolean
  /** Indikátor načítání ve výsledcích (jen remote). */
  loading?: boolean
  loadingLabel?: string
  /** Vybraná položka pro zobrazení labelu, i když není v options (edit / po hledání). */
  selectedOption?: Option | null
  /** id vnitřního inputu — pro <label for>. */
  inputId?: string
  /** Chyba validace (červený rámeček) a id hlášky pro aria-describedby — viz useFieldErrors. */
  invalid?: boolean
  describedby?: string
}>(), {
  placeholder: '',
  clearable: false,
  disabled: false,
  required: false,
  size: 'md',
  remote: false,
  loading: false,
  selectedOption: null,
})

const emit = defineEmits<{
  'update:modelValue': [value: T | null]
  'search': [query: string]
}>()

const { t } = useI18n()

const open = ref(false)
const query = ref('')
const anchor = ref<{ $el: HTMLElement } | null>(null)

// Vybraná položka: v remote režimu může být mimo aktuální options (edit / po hledání),
// proto preferujeme selectedOption dodaný rodičem.
const selected = computed(() => {
  if (props.modelValue === null || props.modelValue === undefined) return null
  if (props.selectedOption && props.selectedOption.value === props.modelValue) return props.selectedOption
  return props.options.find(o => o.value === props.modelValue) ?? null
})

// remote: options už přišly z backendu (rodič filtruje přes @search) → nefiltrovat client-side.
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (props.remote || !q) return props.options
  return props.options.filter(o => o.label.toLowerCase().includes(q) || (o.secondary?.toLowerCase().includes(q) ?? false))
})

// Reka nepovoluje '' jako hodnotu položky ('' = nevybráno), formuláře ho ale používají („Přeskočit“, „Automaticky“).
// Proto do Combobox jdou klíče s typem: 's:' / 'n:5'.
const keyOf = (v: T) => `${typeof v === 'number' ? 'n' : 's'}:${v}`
const selectedKey = computed(() => (selected.value ? keyOf(selected.value.value) : ''))

const groups = computed(() => {
  const out: { label: string; options: Option[] }[] = []
  for (const o of filtered.value) {
    const label = o.group ?? ''
    let g = out.find(x => x.label === label)
    if (!g) out.push(g = { label, options: [] })
    g.options.push(o)
  }
  return out.sort((a, b) => Number(!!a.label) - Number(!!b.label))
})

let searchTimer: ReturnType<typeof setTimeout> | null = null
onUnmounted(() => { if (searchTimer) clearTimeout(searchTimer) })

// Po otevření celý seznam a text inputu označený — psaní ho rovnou přepíše.
watch(open, async (o) => {
  if (!o) return
  query.value = ''
  if (props.remote) emit('search', '')
  await nextTick()
  anchor.value?.$el.querySelector('input')?.select()
})

function onInput(e: Event) {
  query.value = (e.target as HTMLInputElement).value
  open.value = true
  if (!props.remote) return
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => emit('search', query.value.trim()), 250)
}

function onSelect(key: unknown) {
  const o = props.options.find(x => keyOf(x.value) === key)
  if (o) emit('update:modelValue', o.value)
}

function clear() {
  emit('update:modelValue', null)
  anchor.value?.$el.querySelector('input')?.focus()
}

// Escape zavře jen seznam — jinak by ho na document zachytil i Modal a zavřel celý dialog.
function onEscape(e: KeyboardEvent) {
  if (!open.value) return
  e.stopPropagation()
  open.value = false
}

const iconButton = 'cursor-pointer w-6 h-6 inline-flex items-center justify-center rounded text-neutral-400 hover:text-neutral-700'
</script>

<template>
  <ComboboxRoot v-model:open="open" :model-value="selectedKey" :disabled="disabled" ignore-filter open-on-click
    class="relative" @update:model-value="onSelect">
    <ComboboxAnchor ref="anchor" class="relative" @keydown.esc="onEscape">
      <ComboboxInput :id="inputId" :display-value="() => selected?.label ?? ''" :placeholder="placeholder" autocomplete="off" spellcheck="false"
        :aria-invalid="invalid || undefined" :aria-describedby="invalid ? describedby : undefined"
        class="w-full pl-3 border border-field rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none disabled:bg-neutral-50 disabled:text-neutral-400 aria-invalid:border-danger-500 aria-invalid:focus:border-danger-500 aria-invalid:focus:ring-danger-500/20"
        :class="[size === 'sm' ? 'h-9' : 'h-10', clearable ? 'pr-14' : 'pr-8']" @input="onInput" />
      <div class="absolute right-1.5 top-1/2 -translate-y-1/2 flex items-center">
        <button v-if="clearable && selected && !disabled" type="button" :class="iconButton" :aria-label="t('select.clear')" @click="clear">
          <Icon name="x" class="w-3.5 h-3.5" />
        </button>
        <ComboboxTrigger :class="iconButton" :aria-label="t('select.open')">
          <Icon name="chevron-down" class="w-4 h-4" />
        </ComboboxTrigger>
      </div>
      <input v-if="required" tabindex="-1" aria-hidden="true" required :value="selected ? '1' : ''"
        class="sr-only bottom-0 left-1/2" @focus="anchor?.$el.querySelector('input')?.focus()" />
    </ComboboxAnchor>

    <ComboboxContent position="popper" :side-offset="4" @keydown.esc="onEscape"
      class="z-50 w-(--reka-combobox-trigger-width) bg-surface border border-neutral-200 rounded-md shadow-lg overflow-hidden">
      <ComboboxViewport class="max-h-[min(18rem,var(--reka-combobox-content-available-height))] overflow-y-auto p-1">
        <div v-if="remote && loading" class="px-3 py-2 text-sm text-neutral-400">{{ loadingLabel ?? t('common.loading') }}</div>
        <div v-else-if="filtered.length === 0" class="px-3 py-2 text-sm text-neutral-400">{{ noResultsLabel ?? t('select.no_results') }}</div>
        <ComboboxGroup v-for="g in groups" :key="g.label">
          <ComboboxLabel v-if="g.label" class="px-3 pt-2 pb-1 text-xs font-semibold text-neutral-500">{{ g.label }}</ComboboxLabel>
          <ComboboxItem v-for="o in g.options" :key="keyOf(o.value)" :value="keyOf(o.value)" :text-value="o.label"
            class="cursor-pointer px-3 py-2 rounded text-sm text-neutral-900 outline-none data-highlighted:bg-primary-50 data-[state=checked]:font-medium data-[state=checked]:text-primary-700">
            <div class="truncate">{{ o.label }}</div>
            <div v-if="o.secondary" class="text-xs font-normal text-neutral-500 truncate">{{ o.secondary }}</div>
          </ComboboxItem>
        </ComboboxGroup>
      </ComboboxViewport>
    </ComboboxContent>
  </ComboboxRoot>
</template>
