<script setup lang="ts">
import FilingGuide from '@/components/tax/FilingGuide.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import Badge, { type BadgeTone } from '@/components/ui/Badge.vue'
import { taxApi, type Exemption, type RateMethod, type TaxReport, type TaxSection } from '@/api/tax'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDec } from '@/composables/useFormat'
import ManualLink from '@/components/ui/ManualLink.vue'

const { t } = useI18n()

const years = ref<number[]>([])
const year = ref<number>(new Date().getFullYear() - 1)
const rate = ref<RateMethod>('daily')
const report = ref<TaxReport | null>(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    report.value = await taxApi.report(year.value, rate.value)
  } catch (e) {
    report.value = null
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  try {
    years.value = await taxApi.years()
  } catch {
    years.value = []
  }
  const last = new Date().getFullYear() - 1
  if (!years.value.includes(last)) years.value = [last, ...years.value].sort((a, b) => b - a)
  if (!years.value.includes(year.value)) year.value = years.value[0]
  await load()
})
watch([year, rate], load)

const czk = (v: string) => formatDec(v, 2, 'CZK')
const sections = computed(() => report.value
  ? ([['securities', report.value.securities], ['crypto', report.value.crypto]] as [string, TaxSection][])
  : [])

const EXEMPTION_TONES: Record<Exemption, BadgeTone> = {
  limit: 'success',
  time_test: 'success',
  time_test_capped: 'warning',
  before_crypto_exemption: 'neutral',
  none: 'neutral',
}
const detailColumns = computed<DataColumn[]>(() => [
  { key: 'disposed_at', label: t('tax.col.sold') },
  { key: 'instrument', label: t('tax.col.instrument'), primary: true },
  { key: 'acquired', label: t('tax.col.acquired') },
  { key: 'income', label: t('tax.col.income'), align: 'right' },
  { key: 'expense', label: t('tax.col.expense'), align: 'right' },
  { key: 'gain', label: t('tax.col.gain'), align: 'right' },
])
const dividendColumns = computed<DataColumn[]>(() => [
  { key: 'country', label: t('tax.dividends.country'), primary: true },
  { key: 'gross', label: t('tax.dividends.gross'), align: 'right' },
  { key: 'withheld', label: t('tax.dividends.withheld'), align: 'right' },
  { key: 'creditable', label: t('tax.dividends.creditable'), align: 'right', hideBelow: 'lg' },
  { key: 'cz_tax', label: t('tax.dividends.cz_tax'), align: 'right', hideBelow: 'lg' },
  { key: 'to_pay', label: t('tax.dividends.to_pay'), align: 'right' },
])
const indexIn = <T,>(list: T[]) => (row: T) => list.indexOf(row)
const hasIncome = computed(() => !!report.value && (report.value.dividends.countries.length > 0
  || report.value.dividends.domestic.rows.length > 0 || report.value.interest.positions.length > 0))

function printPage() {
  window.print()
}

const selectClass = 'h-9 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('tax.title', { year }) }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('tax.subtitle') }}</p>
        <ManualLink class="mt-1" to="12_Danovy_report" />
      </div>
      <div class="flex flex-wrap items-center gap-2 print:hidden">
        <select v-model="year" :class="selectClass" :aria-label="t('tax.year')">
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="rate" :class="selectClass" :aria-label="t('tax.rate')">
          <option value="daily">{{ t('tax.rates.daily') }}</option>
          <option value="unified">{{ t('tax.rates.unified') }}</option>
        </select>
        <a v-if="report" :href="taxApi.csvUrl(year, rate)" class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">CSV</a>
        <button v-if="report" type="button" @click="printPage" class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
          {{ t('tax.print') }}
        </button>
      </div>
    </div>

    <p class="text-xs text-neutral-500 bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-2.5 mb-4">
      {{ t('tax.disclaimer') }}
      <span class="hidden print:inline"> {{ t(`tax.rates.${rate}`) }}.</span>
    </p>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-4 py-3 text-sm text-danger-500">{{ error }}</div>
    <template v-else-if="report">
      <p v-if="report.cap_exceeded" class="rounded-md bg-warning-50 border border-warning-500/40 text-warning-600 px-4 py-3 text-sm mb-4">{{ t('tax.cap_exceeded') }}</p>

      <!-- Prodeje: cenné papíry a kryptoaktiva -->
      <div class="grid gap-4 lg:grid-cols-2 mb-4">
        <SectionCard v-for="[key, s] in sections" :key="key" :title="t(`tax.sections.${key}`)" flush>
          <EmptyState v-if="!s.rows.length" :title="t('tax.no_sales')" />
          <div v-else class="p-4">
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
              <dt class="text-neutral-600">{{ t('tax.income') }}</dt><dd class="text-right tabular-nums">{{ czk(s.income) }}</dd>
              <dt class="text-neutral-600">{{ t('tax.exempt_income') }}</dt><dd class="text-right tabular-nums text-success-600">{{ czk(s.exempt_income) }}</dd>
              <dt class="text-neutral-600">{{ t('tax.taxable_income') }}</dt><dd class="text-right tabular-nums">{{ czk(s.taxable_income) }}</dd>
              <dt class="text-neutral-600">{{ t('tax.taxable_expense') }}</dt><dd class="text-right tabular-nums">{{ czk(s.taxable_expense) }}</dd>
              <dt class="text-neutral-600">{{ t('tax.result') }}</dt><dd class="text-right tabular-nums" :class="s.result.startsWith('-') ? 'text-danger-500' : ''">{{ czk(s.result) }}</dd>
              <dt class="font-semibold pt-1.5 border-t border-neutral-200">{{ t('tax.tax_base') }}</dt>
              <dd class="text-right tabular-nums font-semibold pt-1.5 border-t border-neutral-200">{{ czk(s.tax_base) }}</dd>
            </dl>
            <ul class="mt-3 space-y-1 text-xs text-neutral-500">
              <li v-if="!s.exemption_year">{{ t('tax.notes.no_crypto_exemption') }}</li>
              <li v-else-if="s.under_limit">{{ t('tax.notes.under_limit', { amount: czk(s.eligible_income) }) }}</li>
              <li v-else>{{ t('tax.notes.over_limit', { amount: czk(s.eligible_income) }) }}</li>
              <li v-if="s.rows.some(r => r.exemption === 'before_crypto_exemption')">{{ t('tax.notes.before_crypto_exemption') }}</li>
              <li v-if="s.rows.some(r => r.fee_skipped)" class="text-warning-600">{{ t('tax.notes.fee_skipped') }}</li>
              <li v-if="s.result.startsWith('-')">{{ t('tax.notes.loss') }}</li>
              <li>{{ t(`tax.notes.${key}`) }}</li>
            </ul>
          </div>
        </SectionCard>
      </div>

      <FilingGuide v-if="hasIncome || sections.some(([, s]) => s.rows.length)" :year="year" :rate="rate"
                   :has-dividends="report.dividends.countries.length > 0" />

      <!-- Detail prodejů po lotech -->
      <template v-for="[key, s] in sections" :key="`d-${key}`">
        <SectionCard v-if="s.rows.length" :title="t('tax.detail', { section: t(`tax.sections.${key}`) })" flush class="mb-4">
          <DataTable :columns="detailColumns" :rows="s.rows" :row-key="indexIn(s.rows)">
            <template #cell-disposed_at="{ row: r }"><span class="whitespace-nowrap tabular-nums">{{ formatDate(r.disposed_at) }}</span></template>
            <template #cell-instrument="{ row: r }">
              <span class="font-medium" :class="{ 'font-mono': key === 'crypto' }">{{ r.symbol }}</span> · <span :class="{ 'font-mono': key === 'crypto' }">{{ formatDec(r.quantity) }}</span> {{ t('tax.pcs') }}
              <span class="block text-xs text-neutral-500">{{ r.account }}</span>
            </template>
            <template #cell-acquired="{ row: r }">
              <span class="whitespace-nowrap tabular-nums">{{ r.acquired_at ? formatDate(r.acquired_at) : t('tax.unknown_lot') }}</span>
              <span class="block mt-0.5"><Badge :tone="EXEMPTION_TONES[r.exemption]">{{ t(`tax.exemptions.${r.exemption}`) }}</Badge></span>
              <span v-if="r.fee_lot" class="block mt-0.5 text-xs text-neutral-500">{{ t('tax.fee_lot') }}</span>
              <span v-if="r.fee_skipped" class="block mt-0.5 text-xs text-warning-600">{{ t('tax.fee_skipped', { currency: r.fee_skipped }) }}</span>
            </template>
            <template #cell-income="{ row: r }">{{ czk(r.income) }}</template>
            <template #cell-expense="{ row: r }">{{ czk(r.expense) }}</template>
            <template #cell-gain="{ row: r }"><span :class="r.gain.startsWith('-') ? 'text-danger-500' : ''">{{ czk(r.gain) }}</span></template>
          </DataTable>
        </SectionCard>
      </template>

      <!-- Dividendy -->
      <SectionCard :title="t('tax.dividends.title')" flush class="mb-4">
        <p v-if="!report.dividends.countries.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('tax.dividends.empty') }}</p>
        <DataTable v-else :columns="dividendColumns" :rows="report.dividends.countries" :row-key="indexIn(report.dividends.countries)">
          <template #cell-country="{ row: c }">
            <span class="font-medium">{{ c.country ?? t('tax.dividends.unknown_country') }}</span>
            <span class="block text-xs text-neutral-500">{{ [...new Set(c.rows.map(r => r.symbol))].join(', ') }}</span>
          </template>
          <template #cell-gross="{ row: c }">{{ czk(c.gross) }}</template>
          <template #cell-withheld="{ row: c }">{{ czk(c.withheld) }}</template>
          <template #cell-creditable="{ row: c }">{{ czk(c.creditable) }}</template>
          <template #cell-cz_tax="{ row: c }">{{ czk(c.cz_tax) }}</template>
          <template #cell-to_pay="{ row: c }">{{ czk(c.to_pay) }}</template>
          <template #foot>
            <tr>
              <td class="px-4 py-2">{{ t('tax.total') }}</td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ czk(report.dividends.total.gross) }}</td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ czk(report.dividends.total.withheld) }}</td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap hidden lg:table-cell">{{ czk(report.dividends.total.creditable) }}</td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap hidden lg:table-cell">{{ czk(report.dividends.total.cz_tax) }}</td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ czk(report.dividends.total.to_pay) }}</td>
            </tr>
          </template>
        </DataTable>
        <dl v-if="report.dividends.countries.length" class="md:hidden px-4 py-3 border-t border-neutral-200 grid grid-cols-2 gap-x-4 gap-y-1 text-sm font-semibold">
          <dt>{{ t('tax.total') }} · {{ t('tax.dividends.gross') }}</dt><dd class="text-right tabular-nums">{{ czk(report.dividends.total.gross) }}</dd>
          <dt>{{ t('tax.dividends.to_pay') }}</dt><dd class="text-right tabular-nums">{{ czk(report.dividends.total.to_pay) }}</dd>
        </dl>
        <template #footer>
          <p class="text-xs text-neutral-500">
            {{ t('tax.dividends.note') }}
            <template v-if="report.dividends.domestic.rows.length"> {{ t('tax.dividends.domestic', { amount: czk(report.dividends.domestic.gross) }) }}</template>
          </p>
        </template>
      </SectionCard>

      <!-- Úroky -->
      <SectionCard v-if="report.interest.positions.length" :title="t('tax.interest.title')" flush class="mb-4">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(p, i) in report.interest.positions" :key="i">
              <td class="px-4 py-2">
                <span class="font-medium">{{ p.name }}</span>
                <span class="block text-xs text-neutral-500">{{ p.account }} · {{ t('tax.interest.count', { n: p.count }) }}</span>
              </td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ czk(p.total) }}</td>
            </tr>
            <tr class="font-semibold">
              <td class="px-4 py-2">{{ t('tax.total') }}</td>
              <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ czk(report.interest.total) }}</td>
            </tr>
          </tbody>
        </table>
        <template #footer><p class="text-xs text-neutral-500">{{ t('tax.interest.note') }}</p></template>
      </SectionCard>

      <p v-if="!hasIncome && !sections.some(([, s]) => s.rows.length)" class="text-sm text-neutral-500 text-center py-6">{{ t('tax.empty_year', { year }) }}</p>

      <p v-if="report.unified_rates?.length" class="text-xs text-neutral-500">
        {{ t('tax.unified_used') }}:
        {{ report.unified_rates.map(u => `${u.currency} ${u.year} = ${formatDec(u.rate, 4)} Kč`).join(' · ') }}
      </p>
    </template>
  </div>
</template>
