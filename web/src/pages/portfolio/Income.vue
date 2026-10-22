<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bar } from 'vue-chartjs'
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip, type ChartOptions } from 'chart.js'
import '@/utils/chartSetup'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/components/ui/KpiTile.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import Badge from '@/components/ui/Badge.vue'
import { summaryApi, type IncomeOverview } from '@/api/summary'
import { apiErrorMessage } from '@/api/errors'
import { useWindowSize } from '@vueuse/core'
import { formatDate, formatDec, formatMoney, formatMoneyCompact } from '@/composables/useFormat'
import { useChartColors } from '@/composables/useTheme'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

const { t, locale } = useI18n()
const colors = useChartColors()

const year = ref<number | null>(new Date().getFullYear())
const data = ref<IncomeOverview | null>(null)
const years = ref<number[]>([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await summaryApi.income(year.value)
    const known = new Set([new Date().getFullYear(), ...data.value.years.map(y => y.year)])
    years.value = [...known].sort((a, b) => b - a)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(year, load)

const cur = computed(() => data.value?.currency ?? 'CZK')
const money = (v: string) => formatMoney(Number(v), cur.value, 2)

/** Graf: měsíce vybraného roku, bez roku po letech */
const bars = computed(() => year.value === null
  ? [...(data.value?.years ?? [])].reverse().map(y => ({ label: String(y.year), dividends: y.dividends, interest: y.interest }))
  : (data.value?.months ?? []).map(m => ({
      label: new Date(`${m.month}-01T00:00:00`).toLocaleDateString(locale.value === 'en' ? 'en-GB' : 'cs-CZ', { month: 'short' }),
      dividends: m.dividends, interest: m.interest,
    })))
const chartData = computed(() => ({
  labels: bars.value.map(b => b.label),
  datasets: [
    { label: t('income.dividends'), data: bars.value.map(b => Number(b.dividends)), backgroundColor: colors.value.palette[0], borderRadius: 3, stack: 's' },
    { label: t('income.interest'), data: bars.value.map(b => Number(b.interest)), backgroundColor: colors.value.palette[7], borderRadius: 3, stack: 's' },
  ],
}))
const options = computed<ChartOptions<'bar'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom', labels: { color: colors.value.tick, boxWidth: 10, boxHeight: 10, useBorderRadius: true, borderRadius: 2 } },
    tooltip: { backgroundColor: colors.value.tooltipBg, padding: 10, boxPadding: 4, callbacks: { label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y, cur.value, 2)}` } },
  },
  scales: {
    x: { stacked: true, grid: { display: false }, ticks: { color: colors.value.tick, maxRotation: 0 } },
    y: { stacked: true, grid: { color: colors.value.grid }, border: { display: false },
      ticks: { color: colors.value.tick, callback: v => (narrow.value ? formatMoneyCompact(Number(v), cur.value) : formatMoney(Number(v), cur.value, 0)) } },
  },
}))

const { width } = useWindowSize()
const narrow = computed(() => width.value < 640)
const cards = computed(() => data.value ? [
  { key: 'dividends', value: data.value.total.dividends },
  { key: 'interest', value: data.value.total.interest },
  { key: 'withheld', value: data.value.total.withheld },
] : [])
const positionColumns = computed<DataColumn[]>(() => [
  { key: 'position', label: t('income.position'), primary: true },
  { key: 'net', label: t('income.net'), align: 'right' },
])
const yearColumns = computed<DataColumn[]>(() => [
  { key: 'year', label: t('tax.year'), primary: true },
  { key: 'dividends', label: t('income.dividends'), align: 'right' },
  { key: 'interest', label: t('income.interest'), align: 'right' },
  { key: 'net', label: t('income.net'), align: 'right' },
])
const paymentColumns = computed<DataColumn[]>(() => [
  { key: 'date', label: t('income.date') },
  { key: 'position', label: t('income.position'), primary: true },
  { key: 'amount', label: t('income.amount'), align: 'right', hideBelow: 'lg' },
  { key: 'net', label: t('income.net'), align: 'right' },
])
const byIndex = <T,>(list: T[]) => (row: T) => list.indexOf(row)
const empty = computed(() => !!data.value && data.value.payments.length === 0)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('income.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('income.subtitle') }}</p>
      </div>
      <select v-model="year" class="h-9 w-full sm:w-auto px-3 border border-neutral-300 rounded-md bg-surface text-sm" :aria-label="t('tax.year')">
        <option :value="null">{{ t('income.all_years') }}</option>
        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
      </select>
    </div>

    <div v-if="loading && !data" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-4 py-3 text-sm text-danger-500">{{ error }}</div>
    <template v-else-if="data">
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:p-5 mb-4">
        <KpiTile bare hero :label="t('income.net_total')" :value="money(data.total.net)" />
        <div class="mt-4 pt-4 border-t border-neutral-200 grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3">
          <KpiTile v-for="c in cards" :key="c.key" bare :label="t(`income.${c.key}`)" :value="c.key === 'withheld' && Number(c.value) ? `−${money(c.value)}` : money(c.value)" />
        </div>
      </section>

      <div v-if="empty" class="bg-surface border border-neutral-200 rounded-lg shadow-sm"><EmptyState :title="t('income.empty')" :message="t('income.empty_hint')" /></div>
      <template v-else>
        <SectionCard :title="year === null ? t('income.by_year') : String(year)" class="mb-4">
          <div class="h-56 sm:h-64"><Bar :data="chartData" :options="options" :aria-label="t('income.title')" /></div>
        </SectionCard>

        <div class="grid gap-4 lg:grid-cols-2 mb-4">
          <SectionCard :title="t('income.by_position')" flush>
            <DataTable :columns="positionColumns" :rows="data.positions" :row-key="byIndex(data.positions)">
              <template #cell-position="{ row: p }">
                <span class="font-medium">{{ p.position }}</span>
                <span class="block text-xs text-neutral-500">{{ p.account }} · {{ t('income.payments_count', { n: p.count }) }}</span>
              </template>
              <template #cell-net="{ row: p }">
                {{ money(p.net) }}
                <span v-if="Number(p.withheld)" class="block text-xs text-neutral-500">{{ t('income.gross_withheld', { gross: money(String(Number(p.dividends) + Number(p.interest))), withheld: money(p.withheld) }) }}</span>
              </template>
            </DataTable>
          </SectionCard>
          <SectionCard :title="t('income.by_year')" flush>
            <DataTable :columns="yearColumns" :rows="data.years" :row-key="byIndex(data.years)">
              <template #cell-year="{ row: y }"><span class="font-medium">{{ y.year }}</span></template>
              <template #cell-dividends="{ row: y }">{{ money(y.dividends) }}</template>
              <template #cell-interest="{ row: y }">{{ money(y.interest) }}</template>
              <template #cell-net="{ row: y }"><span class="font-medium">{{ money(y.net) }}</span></template>
            </DataTable>
          </SectionCard>
        </div>

        <SectionCard :title="t('income.payments')" flush>
          <DataTable :columns="paymentColumns" :rows="data.payments" :row-key="byIndex(data.payments)">
            <template #cell-date="{ row: p }"><span class="whitespace-nowrap tabular-nums">{{ formatDate(p.date) }}</span></template>
            <template #cell-position="{ row: p }">
              <span class="font-medium">{{ p.position }}</span>
              <Badge class="ml-1.5" tone="primary">{{ t(`tx.types.${p.type}`) }}</Badge>
              <span class="block text-xs text-neutral-500">{{ p.account }}</span>
            </template>
            <template #cell-amount="{ row: p }">{{ formatDec(p.amount, 4, p.amount_currency) }}</template>
            <template #cell-net="{ row: p }">
              {{ money(p.net) }}
              <span v-if="Number(p.withheld)" class="block text-xs text-neutral-500">{{ t('income.gross_withheld', { gross: money(p.gross), withheld: money(p.withheld) }) }}</span>
            </template>
          </DataTable>
          <template v-if="data.missing_fx.length" #footer>
            <p class="text-xs text-warning-600">{{ t('summary.missing_fx', { list: data.missing_fx.slice(0, 5).join(', ') }) }}</p>
          </template>
        </SectionCard>
      </template>
    </template>
  </div>
</template>
