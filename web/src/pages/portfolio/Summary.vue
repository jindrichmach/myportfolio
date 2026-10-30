<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bar } from 'vue-chartjs'
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip, type ChartOptions } from 'chart.js'
import '@/utils/chartSetup'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import SegmentControl from '@/components/ui/SegmentControl.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import Badge from '@/components/ui/Badge.vue'
import { portfolioApi, type Account, type Portfolio } from '@/api/portfolio'
import { summaryApi, type PeriodRow, type PeriodSummary } from '@/api/summary'
import { apiErrorMessage } from '@/api/errors'
import { useWindowSize } from '@vueuse/core'
import { formatMoney, formatMoneyCompact, formatPercent, formatSigned } from '@/composables/useFormat'
import { gainClass } from '@/utils/tone'
import { useChartColors } from '@/composables/useTheme'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip)

const { t, locale } = useI18n()
const colors = useChartColors()

const portfolios = ref<Portfolio[]>([])
const accounts = ref<Account[]>([])
const filter = reactive({ portfolio_id: '' as number | '', account_id: '' as number | '' })
const mode = ref<'months' | 'years'>('months')
const data = ref<PeriodSummary | null>(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await summaryApi.summary({ portfolio_id: filter.portfolio_id || undefined, account_id: filter.account_id || undefined })
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  ;[portfolios.value, accounts.value] = await Promise.all([portfolioApi.portfolios(), portfolioApi.accounts()])
  await load()
})
watch(() => [filter.portfolio_id, filter.account_id], load)

const cur = computed(() => data.value?.currency ?? 'CZK')
const rows = computed<PeriodRow[]>(() => [...(mode.value === 'months' ? data.value?.months ?? [] : data.value?.years ?? [])].reverse())
const chartRows = computed(() => [...rows.value].slice(0, mode.value === 'months' ? 24 : 50).reverse())

function label(period: string): string {
  if (period.length === 4) return period
  const d = new Date(`${period}-01T00:00:00`)
  return d.toLocaleDateString(locale.value === 'en' ? 'en-GB' : 'cs-CZ', { month: 'short', year: 'numeric' })
}
const money = (v: string) => formatMoney(Number(v), cur.value, 0)
const signed = (v: string | null) => formatSigned(v, cur.value, 0)
const pct = (v: string | null) => formatPercent(v, 2, true)
const { width } = useWindowSize()
const narrow = computed(() => width.value < 640)
const modeOptions = computed(() => (['months', 'years'] as const).map(m => ({ value: m, label: t(`summary.${m}`) })))
const columns = computed<DataColumn[]>(() => [
  { key: 'period', label: t('summary.period'), primary: true },
  { key: 'value_to', label: t('summary.value_end'), align: 'right' },
  { key: 'flow', label: t('summary.flow'), align: 'right', hideBelow: 'lg' },
  { key: 'gain', label: t('summary.gain'), align: 'right' },
  { key: 'inflation', label: t('summary.inflation'), align: 'right', hideBelow: 'sm' },
  { key: 'income', label: t('summary.income'), align: 'right', hideBelow: 'lg' },
  { key: 'fees', label: t('summary.fees'), align: 'right', hideBelow: 'xl' },
])
const periodKey = (r: PeriodRow) => r.period

const chartData = computed(() => ({
  labels: chartRows.value.map(r => label(r.period)),
  datasets: [{
    label: t('summary.gain'),
    data: chartRows.value.map(r => (r.gain === null ? null : Number(r.gain))),
    backgroundColor: chartRows.value.map(r => (Number(r.gain) >= 0 ? colors.value.gain : colors.value.loss)),
    borderRadius: 3,
  }],
}))
const options = computed<ChartOptions<'bar'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: { backgroundColor: colors.value.tooltipBg, callbacks: { label: ctx => `${t('summary.gain')}: ${signed(String(ctx.parsed.y))}` } },
  },
  scales: {
    x: { grid: { display: false }, ticks: { color: colors.value.tick, maxRotation: 0, autoSkip: true, maxTicksLimit: narrow.value ? 6 : 12 } },
    y: { grid: { color: colors.value.grid }, border: { display: false },
      ticks: { color: colors.value.tick, callback: v => (narrow.value ? formatMoneyCompact(Number(v), cur.value) : formatMoney(Number(v), cur.value, 0)) } },
  },
}))

const selectClass = 'w-full min-w-0 h-9 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('summary.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('summary.subtitle') }}</p>
      </div>
      <SegmentControl v-model="mode" :options="modeOptions" :label="t('summary.title')" />
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 grid gap-2 grid-cols-1 sm:grid-cols-2 lg:max-w-2xl">
      <select v-model="filter.portfolio_id" :class="selectClass">
        <option value="">{{ t('positions.all_portfolios') }}</option>
        <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <select v-model="filter.account_id" :class="selectClass">
        <option value="">{{ t('tx.all_accounts') }}</option>
        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.institution_name }} / {{ a.name }}</option>
      </select>
    </div>

    <div v-if="loading && !data" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-4 py-3 text-sm text-danger-500">{{ error }}</div>
    <div v-else-if="!rows.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm"><EmptyState :title="t('summary.empty')" /></div>
    <template v-else>
      <SectionCard :title="t('summary.chart', { n: chartRows.length })" class="mb-4">
        <div class="h-56 sm:h-64"><Bar :data="chartData" :options="options" :aria-label="t('summary.chart', { n: chartRows.length })" /></div>
      </SectionCard>

      <SectionCard :title="t(`summary.${mode}`)" flush>
        <DataTable :columns="columns" :rows="rows" :row-key="periodKey">
          <template #cell-period="{ row: r }">
            <span class="whitespace-nowrap font-medium">{{ label(r.period) }}</span>
            <Badge v-if="r.partial" class="ml-1.5">{{ t('summary.partial') }}</Badge>
          </template>
          <template #cell-value_to="{ row: r }">
            {{ money(r.value_to) }}<span v-if="r.incomplete" class="text-neutral-500" :title="t('summary.incomplete')"> *</span>
            <span class="block text-xs text-neutral-500">{{ t('summary.from', { value: money(r.value_from) }) }}</span>
          </template>
          <template #cell-flow="{ row: r }">{{ signed(r.flow) }}</template>
          <template #cell-gain="{ row: r }">
            <span :class="gainClass(r.gain)">{{ signed(r.gain) }}<span class="block text-xs">{{ pct(r.gain_pct) }}</span></span>
          </template>
          <template #cell-inflation="{ row: r }">
            <span class="text-neutral-600" :title="r.inflation_to ? t('summary.inflation_to', { month: r.inflation_to }) : ''">{{ r.inflation_pct !== null ? pct(r.inflation_pct) : '—' }}</span>
            <span v-if="r.real_pct !== null" class="block text-xs" :class="gainClass(r.real_pct)">{{ t('summary.real', { pct: pct(r.real_pct) }) }}</span>
          </template>
          <template #cell-income="{ row: r }">
            {{ money(String(Number(r.dividends) + Number(r.interest))) }}
            <span v-if="Number(r.withheld)" class="block text-xs text-neutral-500">{{ t('summary.withheld', { value: money(r.withheld) }) }}</span>
          </template>
          <template #cell-fees="{ row: r }">{{ money(r.fees) }}</template>
        </DataTable>
        <template #footer>
          <p class="text-xs text-neutral-500">
            {{ t('summary.note') }}
            <template v-if="data?.missing_fx.length"> {{ t('summary.missing_fx', { list: data.missing_fx.slice(0, 5).join(', ') }) }}</template>
          </p>
        </template>
      </SectionCard>
    </template>
  </div>
</template>
