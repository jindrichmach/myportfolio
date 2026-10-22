<script setup lang="ts">
/**
 * Graf hodnoty v čase (+ investováno, + benchmark) s výběrem období a výnosovými metrikami (i po inflaci).
 * Plocha mezi hodnotou a investovanými penězi je zisk (success) / ztráta (danger); čára hodnoty v barvě značky.
 * Data počítá backend (denní snapshoty); čísla tu jsou jen pro vykreslení.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, Filler, LinearScale, LineElement, PointElement, Tooltip, type ChartOptions } from 'chart.js'
import { crosshair } from '@/utils/chartSetup'
import { historyApi, HISTORY_RANGES, type History, type HistoryRange, type HistoryScope } from '@/api/history'
import { catalogApi, type Instrument } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'
import { useWindowSize } from '@vueuse/core'
import { formatDate, formatMoney, formatMoneyCompact, formatPercent, formatSigned } from '@/composables/useFormat'
import { gainClass } from '@/utils/tone'
import InfoTip from '@/components/ui/InfoTip.vue'
import SegmentControl from '@/components/ui/SegmentControl.vue'
import { useChartColors } from '@/composables/useTheme'

ChartJS.register(CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip)

const props = defineProps<{ scope: HistoryScope; id?: number | string | null }>()
const { t } = useI18n()
const colors = useChartColors()

const range = ref<HistoryRange>('1Y')
const data = ref<History | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await historyApi.get({ scope: props.scope, id: props.id ?? undefined, range: range.value })
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
watch(() => [props.scope, props.id, range.value], load, { immediate: true })

// Benchmark (per uživatel): libovolný instrument z katalogu, Bitcoin nabídnutý první
const instruments = ref<Instrument[]>([])
const benchmarkBusy = ref(false)
onMounted(async () => {
  try {
    const all = await catalogApi.instruments()
    instruments.value = [...all].sort((a, b) => Number(b.symbol === 'BTC') - Number(a.symbol === 'BTC') || a.symbol.localeCompare(b.symbol))
  } catch {
    // výběr benchmarku je volitelný
  }
})
async function setBenchmark(e: Event) {
  const v = (e.target as HTMLSelectElement).value
  benchmarkBusy.value = true
  try {
    const r = await historyApi.setBenchmark(v === '' ? null : Number(v))
    if (r.errors.length && !r.inserted) error.value = r.errors[0]
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    benchmarkBusy.value = false
  }
}
defineExpose({ load })

const cur = computed(() => data.value?.currency ?? 'CZK')
const hasIncomplete = computed(() => data.value?.points.some(p => p.incomplete) ?? false)

const chartData = computed(() => {
  const pts = data.value?.points ?? []
  const c = colors.value
  return {
    labels: pts.map(p => p.date),
    datasets: [
      {
        label: t('valuation.value'),
        data: pts.map(p => (p.value === null ? null : Number(p.value))),
        borderColor: c.primary,
        // plocha k investovaným penězům (dataset 1): nad = zisk, pod = ztráta
        fill: { target: 1, above: `${c.gain}2e`, below: `${c.loss}2e` },
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: c.primary,
        borderWidth: 2,
        tension: 0.15,
        spanGaps: true,
      },
      {
        label: t('valuation.invested'),
        data: pts.map(p => (p.invested === null ? null : Number(p.invested))),
        borderColor: c.tick,
        borderDash: [4, 4],
        pointRadius: 0,
        pointHoverRadius: 3,
        pointHoverBackgroundColor: c.tick,
        borderWidth: 1.5,
        fill: false,
        stepped: true,
        spanGaps: true,
      },
      ...(data.value?.benchmark?.available ? [{
        label: data.value.benchmark.symbol,
        data: pts.map(p => (p.benchmark === null || p.benchmark === undefined ? null : Number(p.benchmark))),
        borderColor: c.palette[7],
        pointRadius: 0,
        pointHoverRadius: 3,
        pointHoverBackgroundColor: c.palette[7],
        borderWidth: 1.5,
        fill: false,
        tension: 0.15,
        spanGaps: true,
      }] : []),
    ],
  }
})

const { width } = useWindowSize()
const narrow = computed(() => width.value < 640)

const options = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: colors.value.tooltipBg,
      padding: 10,
      boxPadding: 4,
      usePointStyle: true,
      callbacks: {
        title: items => formatDate(items[0]?.label),
        label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y, cur.value, 0)}`,
      },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: colors.value.tick, maxTicksLimit: narrow.value ? 3 : 6, maxRotation: 0, callback: (_v, i) => formatDate(chartData.value.labels[i]) },
    },
    y: {
      grid: { color: colors.value.grid },
      border: { display: false },
      ticks: { color: colors.value.tick, maxTicksLimit: 6, callback: v => (narrow.value ? formatMoneyCompact(Number(v), cur.value) : formatMoney(Number(v), cur.value, 0)) },
    },
  },
}))

const pct = (v: string | null | undefined) => formatPercent(v, 2, true)
const rangeOptions = computed(() => HISTORY_RANGES.map(r => ({ value: r, label: t(`history.ranges.${r}`) })))

interface Metric { key: string; label: string; text: string; cls: string; hint: string; sub?: string }
const metricGroups = computed<{ key: string; title: string; items: Metric[] }[]>(() => {
  const m = data.value?.metrics
  const b = data.value?.benchmark
  if (!m) return []
  return [
    { key: 'result', title: t('history.group_result'), items: [
      { key: 'gain', label: t('history.gain'), text: formatSigned(m.gain, cur.value, 0), cls: gainClass(m.gain), hint: t('history.gain_hint') },
      { key: 'simple', label: t('history.simple'), text: pct(m.simple_pct), cls: gainClass(m.simple_pct), hint: t('history.simple_hint') },
      { key: 'flow', label: t('history.net_flow'), text: formatMoney(Number(m.net_flow), cur.value, 0), cls: '', hint: t('history.net_flow_hint') },
    ] },
    { key: 'performance', title: t('history.group_performance'), items: [
      { key: 'twr', label: 'TWR', text: pct(m.twr_pct), cls: gainClass(m.twr_pct), hint: t('history.twr_hint'),
        sub: m.twr_annual_pct !== null ? t('history.per_year', { pct: pct(m.twr_annual_pct) }) : '' },
      { key: 'xirr', label: 'XIRR', text: pct(m.xirr_pct), cls: gainClass(m.xirr_pct), hint: t('history.xirr_hint'), sub: m.xirr_pct !== null ? t('history.annual') : '' },
      { key: 'inflation', label: t('history.inflation'), text: pct(m.inflation_pct), cls: '',
        hint: m.inflation_from ? t('history.inflation_hint', { from: m.inflation_from, to: m.inflation_to }) : t('history.inflation_missing'),
        sub: m.twr_real_pct !== null ? t('history.real_twr', { pct: pct(m.twr_real_pct) }) : '' },
      ...(b ? [{ key: 'benchmark', label: t('history.benchmark_twr', { symbol: b.symbol }), text: b.available ? pct(b.twr_pct) : '—', cls: gainClass(b.twr_pct),
        hint: t('history.benchmark_hint'), sub: b.available && b.gain !== null ? t('history.benchmark_gain', { gain: formatSigned(b.gain, cur.value, 0) }) : '' }] : []),
    ] },
  ]
})
const realTitle = computed(() => {
  const m = data.value?.metrics
  if (!m || m.twr_real_pct === null) return ''
  return [m.twr_annual_real_pct !== null ? `TWR ${pct(m.twr_annual_real_pct)} ${t('history.annual')}` : '', m.xirr_real_pct !== null ? `XIRR ${pct(m.xirr_real_pct)}` : '']
    .filter(Boolean).join(' · ')
})
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 border-b border-neutral-200">
      <h2 class="text-sm font-semibold"><slot name="title">{{ t('history.title') }}</slot></h2>
      <div class="flex flex-wrap items-center gap-2">
        <slot name="actions" />
        <select v-if="instruments.length" :value="data?.benchmark?.instrument_id ?? ''" :disabled="benchmarkBusy" :aria-label="t('history.benchmark_hint')"
                class="h-7 px-2 border border-neutral-300 rounded-md bg-surface text-xs max-w-40" @change="setBenchmark">
          <option value="">{{ t('history.benchmark_none') }}</option>
          <option v-for="i in instruments" :key="i.id" :value="i.id">{{ t('history.benchmark_vs', { symbol: i.symbol }) }}</option>
        </select>
        <SegmentControl v-model="range" :options="rangeOptions" :label="t('history.title')" />
      </div>
    </div>

    <p v-if="error" class="p-4 text-sm text-danger-500">{{ error }}</p>
    <div v-else-if="!data && loading" class="p-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <p v-else-if="data && !data.points.length" class="p-8 text-center text-sm text-neutral-500">{{ t('history.empty') }}</p>
    <template v-else-if="data">
      <div class="flex flex-wrap gap-x-6 gap-y-3 px-4 pt-3">
        <div v-for="g in metricGroups" :key="g.key" class="min-w-0">
          <h3 class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500 mb-1.5">{{ g.title }}</h3>
          <div class="flex flex-wrap gap-x-6 gap-y-2">
            <div v-for="m in g.items" :key="m.key" class="min-w-[7.5rem]">
              <div class="flex items-center gap-1 text-xs text-neutral-500 whitespace-nowrap"><span>{{ m.label }}</span><InfoTip :text="m.hint" :name="m.label" /></div>
              <div class="font-semibold tabular-nums whitespace-nowrap" :class="m.cls">{{ m.text }}</div>
              <div v-if="m.sub" class="text-xs text-neutral-500 tabular-nums">{{ m.sub }}</div>
            </div>
          </div>
        </div>
      </div>
      <div class="relative h-64 sm:h-72 px-2 pt-3" :class="{ 'opacity-60': loading }">
        <Line :data="chartData" :options="options" :plugins="[crosshair]" :aria-label="t('history.title')" />
      </div>
      <ul class="flex flex-wrap gap-x-4 gap-y-1 px-4 pt-1 pb-3 text-xs text-neutral-600">
        <li class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded bg-current" :style="{ color: colors.primary }"></span>{{ t('history.legend_value') }}</li>
        <li class="inline-flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" :style="{ borderColor: colors.tick }"></span>{{ t('history.legend_invested') }}</li>
        <li class="inline-flex items-center gap-1.5">
          <span class="inline-flex"><span class="w-2 h-3" :style="{ background: `${colors.gain}55` }"></span><span class="w-2 h-3" :style="{ background: `${colors.loss}55` }"></span></span>{{ t('history.legend_gain') }}
        </li>
        <li v-if="data.benchmark?.available" class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded" :style="{ background: colors.palette[7] }"></span>{{ data.benchmark.symbol }}</li>
      </ul>
      <p v-if="hasIncomplete" class="px-4 pb-3 text-xs text-warning-600">{{ t('history.incomplete') }}</p>
      <p v-if="data.benchmark && !data.benchmark.available" class="px-4 pb-3 text-xs text-warning-600">{{ t('history.benchmark_unavailable', { symbol: data.benchmark.symbol }) }}</p>
      <p v-if="realTitle" class="px-4 pb-3 text-xs text-neutral-500">{{ t('history.real_more', { list: realTitle }) }}</p>
    </template>
  </section>
</template>
