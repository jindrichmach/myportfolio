<script setup lang="ts">
/**
 * Rozbalovací skupina pozic na Přehledu (portfolio, krypto napříč účty): hlavička se šipkou, názvem
 * a součty; rozbalená ukáže pozice skupiny stejnou tabulkou — účet, menu ⋯, investováno, výnos.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import Icon from '@/components/ui/Icon.vue'
import PositionActions from '@/components/portfolio/PositionActions.vue'
import { warningKey, type PositionValuation } from '@/api/valuation'
import { formatDec, formatMoney, formatPercent, formatSigned } from '@/composables/useFormat'
import { gainClass } from '@/utils/tone'

const props = defineProps<{
  /** Unikátní id pro aria-controls. */
  id: string
  open: boolean
  rows: PositionValuation[]
  currency: string
  /** Součty hlavičky (number jen pro zobrazení); `basis` = pořizovací cena oceněných pozic pro %. */
  value: number
  gain: number
  basis: number
  /** Hotové % výnosu ze serveru (krypto napříč účty); jinak se dopočítá z gain / basis. */
  returnPct?: string | null
}>()
const emit = defineEmits<{ toggle: []; changed: [] }>()

const { t } = useI18n()

const money = (v: string | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(Number(v), props.currency, 0))
const signed = (v: string | null | undefined) => formatSigned(v, props.currency, 0)
const pct = (v: string | null | undefined) => (v === null || v === undefined ? '' : formatPercent(v, 2, true))

const columns = computed<DataColumn[]>(() => [
  { key: 'position', label: t('positions.position'), primary: true },
  { key: 'quantity', label: t('positions.holding'), align: 'right', width: '15%' },
  { key: 'value', label: t('valuation.value'), align: 'right', width: '15%' },
  { key: 'invested', label: t('valuation.invested'), align: 'right', hideBelow: 'lg', width: '15%' },
  { key: 'total_return', label: t('valuation.total_return'), align: 'right', width: '16%' },
])
/** Tržní pozice bez vlastní ikony dostane barevné iniciály, ne ikonu instituce (všechna ETF u jednoho brokera by vypadala stejně). */
const positionIcon = (r: PositionValuation) => (r.valuation_mode === 'market' ? r.instrument_icon_url : (r.instrument_icon_url ?? r.institution_icon_url))
const positionKey = (r: PositionValuation) => r.position_id
const positionTo = (r: PositionValuation) => `/positions/${r.position_id}`
</script>

<template>
  <div>
    <button type="button" class="cursor-pointer w-full flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-neutral-50"
            :aria-expanded="open" :aria-controls="id" @click="emit('toggle')">
      <Icon name="chevron-down" class="w-4 h-4 shrink-0 text-neutral-500 transition-transform" :class="{ '-rotate-90': !open }" />
      <span class="min-w-0 flex-1 flex items-center gap-2.5">
        <slot name="icon" />
        <span class="min-w-0">
          <slot name="title" />
          <span class="block text-xs text-neutral-500"><slot name="subtitle" /></span>
        </span>
      </span>
      <span class="text-right tabular-nums">
        <span class="block font-semibold">{{ formatMoney(value, currency, 0) }}</span>
        <span class="block text-xs" :class="gainClass(String(gain))">{{ formatSigned(String(gain), currency, 0) }}<template v-if="returnPct"> · {{ pct(returnPct) }}</template><template v-else-if="returnPct === undefined && basis > 0"> · {{ formatPercent((gain / basis) * 100, 2, true) }}</template></span>
      </span>
    </button>
    <div v-if="open" :id="id" class="border-t border-neutral-200">
      <DataTable :columns="columns" :rows="rows" :row-key="positionKey" :row-to="positionTo" sticky-first fixed>
        <template #cell-position="{ row: r }">
          <div class="flex items-center gap-2.5 min-w-0">
            <EntityIcon :src="positionIcon(r)" :name="r.display_name" :size="28" />
            <div class="min-w-0">
              <RouterLink :to="`/positions/${r.position_id}`" class="font-medium hover:text-primary-700" :class="{ 'font-mono': r.valuation_mode === 'market' }">{{ r.display_name }}</RouterLink>
              <span v-if="r.warnings.length" class="ml-1 text-warning-600" :title="r.warnings.map(w => t(warningKey(w).key, warningKey(w).params)).join('\n')"><Icon name="warning" class="w-4 h-4 align-[-3px]" /></span>
              <!-- U ETF a akcií i název: stejný ticker může být jiný fond podle správce, burzy a měny -->
              <span v-if="r.category !== 'crypto' && r.instrument_name" class="block text-xs text-neutral-600 truncate">{{ r.instrument_name }}</span>
              <span class="flex items-center gap-1 text-xs text-neutral-500 truncate">
                <EntityIcon :src="r.institution_icon_url" :name="r.institution_name" :size="14" />
                {{ r.institution_name }} / {{ r.account_name }}
              </span>
            </div>
          </div>
        </template>
        <template #cell-quantity="{ row: r }"><span :class="{ 'font-mono': r.category === 'crypto' }">{{ r.quantity !== null ? formatDec(r.quantity, 8) : '—' }}</span></template>
        <template #cell-value="{ row: r }">{{ money(r.value) }}</template>
        <template #cell-invested="{ row: r }"><span class="text-neutral-600">{{ money(r.invested) }}</span></template>
        <template #cell-total_return="{ row: r }">
          <span :class="gainClass(r.total_return)">{{ signed(r.total_return) }}<span class="block text-xs">{{ pct(r.return_pct) }}</span></span>
        </template>
        <template #actions="{ row: r }"><PositionActions :position-id="r.position_id" :name="r.display_name" :market="r.valuation_mode === 'market'" :cash="r.is_cash" @changed="emit('changed')" /></template>
      </DataTable>
    </div>
  </div>
</template>
