<script setup lang="ts">
/**
 * Ocenění pozice v měně zobrazení: hodnota, nákupní cena, zisk; u tržní pozice FIFO loty
 * a prodeje, u ruční pozice seznam ručních ocenění s formulářem.
 */
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { valuationApi, warningKey, type ManualValuation, type PositionValuationDetail } from '@/api/valuation'
import type { Position } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime, formatDec, formatMoney, formatPercent, formatSigned } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import IconButton from '@/components/ui/IconButton.vue'
import KpiTile from '@/components/ui/KpiTile.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import Badge from '@/components/ui/Badge.vue'
import PriceBadge from '@/components/portfolio/PriceBadge.vue'
import { gainClass } from '@/utils/tone'

const props = defineProps<{ position: Position; transactionCount?: number }>()
const { t } = useI18n()
const { canWrite } = useOwnerContext()
const toast = useToast()

const v = ref<PositionValuationDetail | null>(null)
const valuations = ref<ManualValuation[]>([])
const isManual = computed(() => props.position.valuation_mode === 'manual')

async function load() {
  try {
    const [detail, list] = await Promise.all([
      valuationApi.position(props.position.id),
      isManual.value ? valuationApi.valuations(props.position.id) : Promise.resolve([]),
    ])
    v.value = detail
    valuations.value = list
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
onMounted(load)
defineExpose({ load })

const cur = computed(() => v.value?.currency ?? 'CZK')
const money = (x: string | null | undefined, dec = 2) => (x === null || x === undefined ? '—' : formatMoney(Number(x), cur.value, dec))
const signed = (x: string | null | undefined) => formatSigned(x, cur.value, 2)
const decimals = computed(() => props.position.instrument_decimals ?? 8)
const openLots = computed(() => (v.value?.lots ?? []).filter(l => Number(l.remaining_quantity) > 0))

const isCrypto = computed(() => props.position.asset_class === 'crypto')
const holdingLabel = computed(() => `${formatDec(props.position.holding, props.position.instrument_decimals ?? 8)} ${props.position.instrument_symbol ?? ''}`)
const totalSub = computed(() => {
  const x = v.value
  if (!x) return ''
  return `${signed(x.total_return)}${x.return_pct !== null ? ` · ${formatPercent(x.return_pct, 2, true)}` : ''} ${t('valuation.total_return').toLowerCase()}`
})
const kpis = computed(() => {
  const x = v.value
  if (!x) return []
  const out = [
    { key: 'invested', label: isManual.value ? t('positions.net_deposits') : t('valuation.invested'), text: money(x.invested), cls: '' },
  ]
  if (!isManual.value) {
    out.push(
      { key: 'avg', label: t('valuation.avg_cost'), text: money(x.avg_cost), cls: '' },
      { key: 'realized', label: t('valuation.realized'), text: signed(x.realized), cls: gainClass(x.realized) },
    )
  }
  out.push({ key: 'income', label: t('valuation.income'), text: money(x.income), cls: '' })
  return out
})

const lotColumns = computed<DataColumn[]>(() => [
  { key: 'acquired_at', label: t('valuation.acquired_at'), primary: true },
  { key: 'source', label: t('valuation.source') },
  { key: 'remaining', label: t('valuation.remaining'), align: 'right', mono: isCrypto.value },
  { key: 'cost', label: t('valuation.cost'), align: 'right' },
  { key: 'cost_display', label: t('valuation.cost_display', { currency: cur.value }), align: 'right', hideBelow: 'sm' },
])
const disposalColumns = computed<DataColumn[]>(() => [
  { key: 'disposed_at', label: t('valuation.disposed_at'), primary: true },
  { key: 'acquired_at', label: t('valuation.acquired_at'), hideBelow: 'sm' },
  { key: 'quantity', label: t('tx.quantity'), align: 'right', mono: isCrypto.value },
  { key: 'proceeds', label: t('valuation.proceeds'), align: 'right', hideBelow: 'lg' },
  { key: 'cost', label: t('valuation.cost'), align: 'right', hideBelow: 'lg' },
  { key: 'gain', label: t('valuation.gain'), align: 'right' },
])
const valuationColumns = computed<DataColumn[]>(() => [
  { key: 'date', label: t('valuation.date'), primary: true },
  { key: 'value', label: t('valuation.balance', { currency: props.position.currency }), align: 'right' },
  { key: 'note', label: t('common.note') },
])
const byId = (r: { id: number }) => r.id

// ── Ruční ocenění ────────────────────────────────────────────────────
const today = new Date().toISOString().slice(0, 10)
const form = reactive({ date: today, value: '', note: '' })
const busy = ref(false)
const error = ref('')

async function saveValuation() {
  busy.value = true
  error.value = ''
  try {
    valuations.value = await valuationApi.saveValuation(props.position.id, {
      date: form.date, value: form.value.trim(), currency: props.position.currency, note: form.note.trim() || null,
    })
    form.value = ''
    form.note = ''
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

async function removeValuation(row: ManualValuation) {
  if (!confirm(t('valuation.delete_confirm', { date: formatDate(row.date) }))) return
  try {
    await valuationApi.deleteValuation(row.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const inputClass = 'w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm'
</script>

<template>
  <div v-if="v" class="mb-4 space-y-4">
    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:p-5">
      <KpiTile bare hero :label="t('valuation.value')" :value="money(v.value)" :sub="totalSub" :sub-class="gainClass(v.total_return)" />
      <div class="mt-4 pt-4 border-t border-neutral-200 grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3">
        <KpiTile v-if="position.valuation_mode === 'market'" bare :label="t('positions.holding')" :value="holdingLabel" :value-class="isCrypto ? 'font-mono' : ''" />
        <div v-if="position.valuation_mode === 'market'" class="min-w-0 col-span-2 sm:col-span-1">
          <div class="text-xs text-neutral-500 leading-4">{{ t('prices.last') }}</div>
          <div class="mt-1 text-lg leading-7 font-semibold"><PriceBadge :price="position.last_price" show-source :mono="isCrypto" class="!items-start" /></div>
        </div>
        <KpiTile v-else bare :label="t('common.currency')" :value="position.currency" value-class="font-mono" />
        <KpiTile v-for="k in kpis" :key="k.key" bare :label="k.label" :value="k.text" :value-class="k.cls" />
      </div>
      <p class="mt-3 text-xs text-neutral-500">
        {{ t(`positions.modes.${position.is_cash ? 'cash' : position.valuation_mode}`) }}<template v-if="transactionCount !== undefined"> · {{ t('tx.title') }}: {{ transactionCount }}</template>
      </p>
    </section>

    <div v-if="v.warnings.length" class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-xs text-warning-600 space-y-0.5">
      <p v-for="w in v.warnings" :key="w">{{ t(warningKey(w).key, warningKey(w).params) }}</p>
    </div>

    <!-- Tržní pozice: loty a prodeje -->
    <template v-if="!isManual">
      <SectionCard v-if="openLots.length" :title="t('valuation.lots')" :subtitle="t('valuation.lots_hint')" flush>
        <DataTable :columns="lotColumns" :rows="openLots" :row-key="byId">
          <template #cell-acquired_at="{ row: l }"><span class="whitespace-nowrap">{{ formatDateTime(l.acquired_at) }}</span></template>
          <template #cell-source="{ row: l }"><span class="text-neutral-600">{{ l.origin_lot_id ? t('valuation.from_transfer') : t(`tx.types.${l.source_type ?? 'buy'}`) }}</span></template>
          <template #cell-remaining="{ row: l }">
            {{ formatDec(l.remaining_quantity, decimals) }}
            <span v-if="l.remaining_quantity !== l.quantity" class="block text-xs text-neutral-500">/ {{ formatDec(l.quantity, decimals) }}</span>
          </template>
          <template #cell-cost="{ row: l }">{{ formatDec(l.remaining_cost, 2, l.cost_currency) }}</template>
          <template #cell-cost_display="{ row: l }">{{ money(l.remaining_cost_display) }}</template>
        </DataTable>
      </SectionCard>

      <SectionCard v-if="v.disposals.length" :title="t('valuation.disposals')" flush>
        <DataTable :columns="disposalColumns" :rows="v.disposals" :row-key="byId">
          <template #cell-disposed_at="{ row: d }">
            <span class="whitespace-nowrap">{{ formatDate(d.disposed_at) }}</span>
            <Badge v-if="d.kind === 'fee'" class="ml-1">{{ t('tx.fee') }}</Badge>
          </template>
          <template #cell-acquired_at="{ row: d }">
            <span class="whitespace-nowrap">{{ formatDate(d.acquired_at) }}</span>
            <span class="block text-xs text-neutral-500">{{ t('valuation.holding_days', { n: d.holding_days }) }}</span>
          </template>
          <template #cell-quantity="{ row: d }">{{ formatDec(d.quantity, decimals) }}</template>
          <template #cell-proceeds="{ row: d }">{{ money(d.proceeds_display) }}</template>
          <template #cell-cost="{ row: d }">{{ money(d.cost_display) }}</template>
          <template #cell-gain="{ row: d }"><span :class="gainClass(d.gain_display)">{{ signed(d.gain_display) }}</span></template>
        </DataTable>
      </SectionCard>
    </template>

    <!-- Ruční pozice: ocenění (hotovost má místo ocenění zůstatek) -->
    <SectionCard v-else-if="!position.is_cash" :title="t('valuation.manual_title')" flush>
      <form v-if="canWrite" class="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3 items-end border-b border-neutral-100" @submit.prevent="saveValuation">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('valuation.date') }}</label>
          <input v-model="form.date" type="date" :max="today" required :class="inputClass" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('valuation.balance', { currency: position.currency }) }}</label>
          <input v-model="form.value" inputmode="decimal" required autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('common.note') }}</label>
          <input v-model="form.note" maxlength="500" :class="inputClass" />
        </div>
        <button type="submit" :disabled="busy" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action text-sm font-medium rounded-md">
          {{ t('valuation.save') }}
        </button>
        <p v-if="error" role="alert" class="col-span-full rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
        <p class="col-span-full text-xs text-neutral-500">{{ t('valuation.manual_hint') }}</p>
      </form>
      <p v-if="!valuations.length" class="p-4 text-sm text-neutral-500">{{ t('valuation.manual_empty') }}</p>
      <DataTable v-else :columns="valuationColumns" :rows="valuations" :row-key="byId">
        <template #cell-date="{ row }"><span class="whitespace-nowrap">{{ formatDate(row.date) }}</span></template>
        <template #cell-value="{ row }">{{ formatDec(row.value, 2, row.currency) }}</template>
        <template #cell-note="{ row }"><span class="text-xs text-neutral-500">{{ row.note ?? '' }}</span></template>
        <template v-if="canWrite" #actions="{ row }">
          <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="removeValuation(row)" />
        </template>
      </DataTable>
    </SectionCard>
  </div>
</template>
