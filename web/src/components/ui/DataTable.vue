<script setup lang="ts" generic="T">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/ui/Icon.vue'

/**
 * Datová tabulka: od `md` (nebo `cardsBelow`) výš <table>, pod ním karty se stejnými daty (primární sloupec jako titulek,
 * ostatní jako popisek/hodnota). Buňky přes sloty `cell-<key>` ({ row }), akce řádku přes slot `actions`.
 * `expandable(row)` přidá rozbalovací řádek (slot `expanded`) — např. rozpad pozice po účtech.
 * Čísla: `align: 'right'` (vždy tabular-nums), `mono` pro množství a kurzy kryptoměn.
 */
export interface DataColumn {
  key: string
  label: string
  align?: 'left' | 'right'
  mono?: boolean
  /** Na desktopu skrýt pod danou šířkou (v kartě na mobilu zůstává). */
  hideBelow?: 'sm' | 'lg' | 'xl' | '2xl'
  /** Na mobilu jako titulek karty (typicky název pozice). */
  primary?: boolean
  /** V kartě na mobilu vynechat (údaj už je v titulku karty). */
  hideInCard?: boolean
  /** Šířka sloupce při `fixed` (CSS hodnota) — stejné sloupce napříč sekcemi stránky. */
  width?: string
  class?: string
}

const props = defineProps<{
  columns: DataColumn[]
  rows: T[]
  rowKey: (row: T) => string | number
  expandable?: (row: T) => boolean
  /** Sticky první sloupec při vodorovném scrollu tabulky. */
  stickyFirst?: boolean
  /** Doplňkové třídy řádku / karty (např. ztlumení archivované položky). */
  rowClass?: (row: T) => string
  /** Pevné rozvržení sloupců (table-fixed + `width`) — tabulky pod sebou mají sloupce zarovnané. */
  fixed?: boolean
  /** Pod jakou šířkou karty místo tabulky (široké tabulky už pod `lg`). */
  cardsBelow?: 'md' | 'lg'
}>()
const { t } = useI18n()

const open = ref(new Set<string | number>())
function toggle(row: T) {
  const k = props.rowKey(row)
  const next = new Set(open.value)
  if (next.has(k)) next.delete(k)
  else next.add(k)
  open.value = next
}
const isOpen = (row: T) => open.value.has(props.rowKey(row))
const canExpand = (row: T) => props.expandable?.(row) ?? false

const HIDE: Record<NonNullable<DataColumn['hideBelow']>, string> = {
  sm: 'hidden sm:table-cell',
  lg: 'hidden lg:table-cell',
  xl: 'hidden xl:table-cell',
  '2xl': 'hidden 2xl:table-cell',
}
function cellClass(c: DataColumn): string[] {
  return [
    c.align === 'right' ? 'text-right tabular-nums whitespace-nowrap' : 'text-left',
    c.mono ? 'font-mono' : '',
    c.hideBelow ? HIDE[c.hideBelow] : '',
    c.class ?? '',
  ]
}
const primaryCol = () => props.columns.find(c => c.primary) ?? props.columns[0]
const detailCols = () => props.columns.filter(c => c !== primaryCol() && !c.hideInCard)
const value = (row: T, key: string) => String((row as Record<string, unknown>)[key] ?? '—')
</script>

<template>
  <div>
    <!-- desktop -->
    <div class="hidden overflow-x-auto" :class="cardsBelow === 'lg' ? 'lg:block' : 'md:block'">
      <table class="w-full text-sm" :class="{ 'table-sticky-first': stickyFirst, 'table-fixed min-w-[720px]': fixed }">
        <thead class="text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th v-if="expandable" class="w-10 px-2"><span class="sr-only">{{ t('common.expand') }}</span></th>
            <th v-for="c in columns" :key="c.key" class="px-4 py-2 font-medium" :class="cellClass({ ...c, mono: false })" :style="c.width ? { width: c.width } : undefined">{{ c.label }}</th>
            <th v-if="$slots.actions" class="px-4 py-2" :class="fixed ? 'w-28' : 'w-px'"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <template v-for="row in rows" :key="rowKey(row)">
            <tr class="hover:bg-neutral-50" :class="rowClass?.(row)">
              <td v-if="expandable" class="px-2 py-2 align-middle">
                <button v-if="canExpand(row)" type="button" :aria-expanded="isOpen(row)" :aria-label="isOpen(row) ? t('common.collapse') : t('common.expand')"
                        class="cursor-pointer w-6 h-6 inline-flex items-center justify-center rounded text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        @click="toggle(row)">
                  <Icon name="chevron-down" class="w-4 h-4 transition-transform" :class="isOpen(row) ? '' : '-rotate-90'" />
                </button>
              </td>
              <td v-for="c in columns" :key="c.key" class="px-4 py-2" :class="cellClass(c)">
                <slot :name="`cell-${c.key}`" :row="row">{{ value(row, c.key) }}</slot>
              </td>
              <td v-if="$slots.actions" class="px-4 py-2 text-right whitespace-nowrap"><slot name="actions" :row="row" /></td>
            </tr>
            <tr v-if="canExpand(row) && isOpen(row)" class="bg-neutral-50">
              <td :colspan="columns.length + 1 + ($slots.actions ? 1 : 0)" class="px-4 py-2 pl-12"><slot name="expanded" :row="row" /></td>
            </tr>
          </template>
        </tbody>
        <tfoot v-if="$slots.foot" class="border-t border-neutral-200 font-semibold"><slot name="foot" /></tfoot>
      </table>
    </div>

    <!-- mobil: karty -->
    <ul class="divide-y divide-neutral-100" :class="cardsBelow === 'lg' ? 'lg:hidden' : 'md:hidden'">
      <li v-for="row in rows" :key="rowKey(row)" class="px-4 py-3" :class="rowClass?.(row)">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1" :class="{ 'font-mono': primaryCol().mono }">
            <slot :name="`cell-${primaryCol().key}`" :row="row">{{ value(row, primaryCol().key) }}</slot>
          </div>
          <div v-if="$slots.actions" class="shrink-0 flex gap-1.5"><slot name="actions" :row="row" /></div>
        </div>
        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
          <div v-for="c in detailCols()" :key="c.key" class="min-w-0">
            <dt class="text-xs text-neutral-500">{{ c.label }}</dt>
            <dd class="tabular-nums break-words" :class="{ 'font-mono': c.mono }"><slot :name="`cell-${c.key}`" :row="row">{{ value(row, c.key) }}</slot></dd>
          </div>
        </dl>
        <template v-if="canExpand(row)">
          <button type="button" :aria-expanded="isOpen(row)" class="cursor-pointer mt-2 inline-flex items-center gap-1 text-xs text-primary-700 hover:underline" @click="toggle(row)">
            <Icon name="chevron-down" class="w-3.5 h-3.5 transition-transform" :class="isOpen(row) ? '' : '-rotate-90'" />
            {{ isOpen(row) ? t('common.collapse') : t('common.expand') }}
          </button>
          <div v-if="isOpen(row)" class="mt-2 rounded-md bg-neutral-50 px-3 py-2 text-sm"><slot name="expanded" :row="row" /></div>
        </template>
      </li>
    </ul>
  </div>
</template>
