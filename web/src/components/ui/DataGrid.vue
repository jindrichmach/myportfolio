<script setup lang="ts" generic="T">
import { computed, onMounted, reactive, ref, useSlots, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Icon, { type IconName } from '@/components/ui/Icon.vue'
import GridFilterField from '@/components/ui/GridFilterField.vue'
import {
  clampPage,
  defaultFilterValue,
  isFilterActive,
  matchesFilter,
  pageCount,
  pageItems,
  type GridFilter,
  type GridFilterValues,
  type GridQuery,
} from '@/components/ui/grid'

/**
 * Znovupoužitelný grid: filtry nad tabulkou, tabulka (karty na mobilu), stránkování a volba velikosti stránky.
 *
 * Režimy:
 * - klientský (bez `total`): grid filtruje a stránkuje `rows` sám. Složené podmínky přes více polí dává `filterFn`.
 * - serverový (`total` zadán): `rows` jsou jen aktuální stránka, grid nefiltruje. Při každé změně stránky,
 *   velikosti nebo filtru emituje `change`; rodič si data načte a předá zpět spolu s `total`.
 *
 * `change` se emituje i po připojení, takže serverový rodič načte první stránku bez vlastního volání.
 * Sloty tabulky (`cell-<key>`, `actions`, `expanded`, `foot`) se předávají do DataTable.
 * Vlastní filtr (`type: 'custom'`) vykreslí rodič ve slotu `filter-<key>` s props { value, set }.
 * Tlačítko Zrušit filtr se objeví v liště jen tehdy, když je nějaký filtr nastavený.
 */
const props = withDefaults(defineProps<{
  columns: DataColumn[]
  rows: T[]
  rowKey: (row: T) => string | number
  filters?: GridFilter[]
  /** Složený predikát nad řádkem a všemi hodnotami filtrů (jen klientský režim). */
  filterFn?: (row: T, values: GridFilterValues) => boolean
  /** Počet všech záznamů; zadán = serverový režim. */
  total?: number
  loading?: boolean
  pageSizes?: number[]
  initialPageSize?: number
  emptyText?: string
  /** Ikona prázdného stavu (výchozí `empty`). */
  emptyIcon?: IconName
  /** Podnadpis prázdného stavu (pokyn, co udělat dál). */
  emptyHint?: string
  rowClass?: (row: T) => string
  expandable?: (row: T) => boolean
  fixed?: boolean
  stickyFirst?: boolean
  cardsBelow?: 'md' | 'lg'
}>(), {
  filters: () => [],
  pageSizes: () => [10, 25, 50, 100],
  initialPageSize: 25,
})

const emit = defineEmits<{ change: [query: GridQuery] }>()
const slots = useSlots()
const { t } = useI18n()

const page = ref(1)
const pageSize = ref(props.pageSizes.includes(props.initialPageSize) ? props.initialPageSize : props.pageSizes[0] ?? 25)
const values = reactive<GridFilterValues>({})
for (const f of props.filters) values[f.key] = defaultFilterValue(f)

const primaryFilter = computed(() => props.filters.find((f) => f.type === 'text'))
const otherFilters = computed(() => props.filters.filter((f) => f !== primaryFilter.value))
const activeCount = computed(() => props.filters.filter((f) => isFilterActive(f, values[f.key])).length)

const serverMode = computed(() => props.total !== undefined)

function columnOf(f: GridFilter): string | undefined {
  return f.type === 'custom' ? undefined : f.column
}
function passes(row: T): boolean {
  if (props.filterFn && !props.filterFn(row, values)) return false
  return props.filters.every((f) => {
    const column = columnOf(f)
    return matchesFilter(f, values[f.key], column ? (row as Record<string, unknown>)[column] : undefined)
  })
}

const filtered = computed(() => (serverMode.value ? props.rows : props.rows.filter(passes)))
const totalCount = computed(() => (serverMode.value ? (props.total ?? 0) : filtered.value.length))
const pages = computed(() => pageCount(totalCount.value, pageSize.value))
const current = computed(() => clampPage(page.value, pages.value))
const visibleRows = computed(() =>
  serverMode.value ? props.rows : filtered.value.slice((current.value - 1) * pageSize.value, current.value * pageSize.value),
)
const rangeFrom = computed(() => (totalCount.value === 0 ? 0 : (current.value - 1) * pageSize.value + 1))
const rangeTo = computed(() => Math.min(current.value * pageSize.value, totalCount.value))

/** Bez záznamů a bez aktivního filtru se nevykresluje lišta ani rámeček, jen prázdný stav. */
const hasRecords = computed(() => (serverMode.value ? totalCount.value > 0 : props.rows.length > 0))
const showEmpty = computed(() => !hasRecords.value && activeCount.value === 0)

const emptyMessage = computed(() => {
  if (props.loading) return t('common.loading')
  if (activeCount.value) return t('grid.no_match')
  return props.emptyText ?? t('grid.empty')
})

function emitQuery() {
  emit('change', { page: current.value, pageSize: pageSize.value, filterValues: { ...values } })
}
watch([current, pageSize], emitQuery)
watch(values, () => {
  if (page.value !== 1) page.value = 1
  else emitQuery()
}, { deep: true })
onMounted(emitQuery)

const pageNumbers = computed(() => pageItems(current.value, pages.value))
function goPage(target: number) {
  page.value = clampPage(target, pages.value)
}
function setPageSize(size: number) {
  pageSize.value = size
  page.value = 1
}
function resetFilters() {
  for (const f of props.filters) values[f.key] = defaultFilterValue(f)
}

/** Sloty tabulky se předají dál; filtrové a toolbar sloty řeší grid sám. */
function tableSlotNames(): string[] {
  return Object.keys(slots).filter((name) => name !== 'default' && name !== 'toolbar' && !name.startsWith('filter-'))
}
</script>

<template>
  <div v-if="showEmpty" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <EmptyState :title="emptyMessage" :message="emptyHint" :icon="emptyIcon" />
  </div>

  <div v-else>
    <FilterBar :active-count="activeCount">
      <template v-if="primaryFilter" #primary>
        <GridFilterField v-model="values[primaryFilter.key]" :filter="primaryFilter" />
      </template>

      <template v-if="otherFilters.length" #default>
        <template v-for="f in otherFilters" :key="f.key">
          <slot v-if="f.type === 'custom'" :name="`filter-${f.key}`" :value="values[f.key]"
            :set="(v: unknown) => (values[f.key] = v)" :label="f.label" />
          <GridFilterField v-else v-model="values[f.key]" :filter="f" />
        </template>
      </template>

      <template #actions>
        <button v-if="activeCount" type="button"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm text-neutral-700 hover:bg-neutral-50 whitespace-nowrap"
          @click="resetFilters">
          <Icon name="x" class="w-4 h-4" />
          {{ t('grid.clear_filter') }}
        </button>
      </template>
    </FilterBar>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden transition-opacity" :class="{ 'opacity-60': loading && visibleRows.length }">
      <DataTable v-if="visibleRows.length" :columns="columns" :rows="visibleRows" :row-key="rowKey" :row-class="rowClass"
        :expandable="expandable" :sticky-first="stickyFirst" :fixed="fixed" :cards-below="cardsBelow">
        <template v-for="name in tableSlotNames()" :key="name" #[name]="scope">
          <slot :name="name" v-bind="scope" />
        </template>
      </DataTable>
      <p v-else class="px-4 py-10 text-center text-sm text-neutral-500">{{ emptyMessage }}</p>

      <div v-if="totalCount > 0" class="border-t border-neutral-200 px-3 py-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs text-neutral-500">
        <span class="tabular-nums">{{ t('grid.range', { from: rangeFrom, to: rangeTo, total: totalCount }) }}</span>
        <div class="flex items-center gap-2">
          <nav :aria-label="t('grid.pages')" class="flex flex-wrap items-center justify-center gap-1">
            <button type="button" :disabled="current <= 1" class="cursor-pointer h-8 px-3 border border-neutral-300 rounded text-sm disabled:opacity-30 hover:bg-neutral-50"
              @click="goPage(current - 1)">{{ t('common.previous') }}</button>
            <template v-for="(item, index) in pageNumbers" :key="`${index}-${item}`">
              <span v-if="item === 'gap'" aria-hidden="true" class="px-1 text-neutral-400">…</span>
              <button v-else type="button" :aria-label="t('grid.page_n', { n: item })" :aria-current="item === current ? 'page' : undefined"
                class="cursor-pointer min-w-8 h-8 px-2 border rounded text-sm tabular-nums"
                :class="item === current ? 'bg-action border-action text-on-action font-medium' : 'border-neutral-300 hover:bg-neutral-50'"
                @click="goPage(item)">{{ item }}</button>
            </template>
            <button type="button" :disabled="current >= pages" class="cursor-pointer h-8 px-3 border border-neutral-300 rounded text-sm disabled:opacity-30 hover:bg-neutral-50"
              @click="goPage(current + 1)">{{ t('common.next') }}</button>
          </nav>
        </div>
        <label class="inline-flex items-center gap-2">
          {{ t('grid.page_size') }}
          <select :value="pageSize" class="h-8 px-2 border border-neutral-300 rounded bg-surface text-sm text-neutral-700"
            @change="setPageSize(Number(($event.target as HTMLSelectElement).value))">
            <option v-for="n in pageSizes" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
      </div>
    </div>
  </div>
</template>
