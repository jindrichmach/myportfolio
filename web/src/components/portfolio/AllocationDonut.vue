<script setup lang="ts">
/**
 * Donut alokace (hodnota podle kategorie / portfolia / instituce) + legenda s částkou a podílem.
 * Uprostřed součet; najetí na výseč i na řádek legendy zvýrazní stejnou část a ukáže ji uprostřed.
 * Čísla jen pro zobrazení — součty počítá backend.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, type ActiveElement, type ChartEvent } from 'chart.js'
import '@/utils/chartSetup'
import type { AllocationSlice } from '@/api/valuation'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { useChartColors } from '@/composables/useTheme'

ChartJS.register(ArcElement, Tooltip)

const props = defineProps<{ slices: AllocationSlice[]; currency: string; labelFor?: (label: string) => string }>()
const { t } = useI18n()
const colors = useChartColors()

const total = computed(() => props.slices.reduce((s, x) => s + Number(x.value), 0))
const label = (l: string) => props.labelFor?.(l) ?? l
const pct = (v: string) => (total.value > 0 ? (Number(v) / total.value) * 100 : 0)
const color = (i: number) => colors.value.palette[i % colors.value.palette.length]

const active = ref<number | null>(null)
const chartRef = ref<{ chart?: ChartJS } | null>(null)

/** Zvýraznění z legendy — výseč se zvýrazní stejně jako při najetí myší. */
function highlight(i: number | null) {
  active.value = i
  const chart = chartRef.value?.chart
  if (!chart) return
  chart.setActiveElements(i === null ? [] : [{ datasetIndex: 0, index: i }])
  chart.render()
}

const data = computed(() => ({
  labels: props.slices.map(s => label(s.label)),
  datasets: [{
    data: props.slices.map(s => Number(s.value)),
    backgroundColor: props.slices.map((_, i) => color(i)),
    borderColor: colors.value.border,
    borderWidth: 2,
    hoverOffset: 6,
  }],
}))

const options = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '68%',
  layout: { padding: 6 },
  onHover: (_e: ChartEvent, els: ActiveElement[]) => { active.value = els[0]?.index ?? null },
  plugins: { legend: { display: false }, tooltip: { enabled: false } },
}))

const center = computed(() => {
  const s = active.value === null ? null : props.slices[active.value]
  return s
    ? { title: label(s.label), value: formatMoney(Number(s.value), props.currency, 0), sub: formatPercent(pct(s.value), 1) }
    : { title: t('common.total'), value: formatMoney(total.value, props.currency, 0), sub: '' }
})
</script>

<template>
  <div class="@container">
  <div class="flex flex-col @md:flex-row @md:items-center gap-4">
    <div class="relative w-44 h-44 shrink-0 self-center" @mouseleave="active = null">
      <Doughnut ref="chartRef" :data="data" :options="options" :aria-label="t('dashboard.allocation')" />
      <div class="absolute inset-0 flex flex-col items-center justify-center text-center pointer-events-none px-8">
        <span class="text-xs text-neutral-500 truncate max-w-full">{{ center.title }}</span>
        <span class="text-sm font-semibold tabular-nums leading-5">{{ center.value }}</span>
        <span v-if="center.sub" class="text-xs tabular-nums text-neutral-600">{{ center.sub }}</span>
      </div>
    </div>
    <ul class="flex-1 min-w-0 text-sm">
      <li v-for="(s, i) in slices" :key="s.label">
        <button type="button" class="w-full flex items-center gap-2 px-2 py-1 rounded-md text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="active === i ? 'bg-neutral-100' : ''"
                @mouseenter="highlight(i)" @mouseleave="highlight(null)" @focus="highlight(i)" @blur="highlight(null)">
          <span class="w-2.5 h-2.5 rounded-sm shrink-0" :style="{ background: color(i) }"></span>
          <span class="truncate flex-1">{{ label(s.label) }}</span>
          <span class="tabular-nums text-neutral-600 whitespace-nowrap">{{ formatMoney(Number(s.value), currency, 0) }}</span>
          <span class="tabular-nums font-medium w-14 text-right whitespace-nowrap">{{ formatPercent(pct(s.value), 1) }}</span>
        </button>
      </li>
    </ul>
  </div>
  </div>
</template>
