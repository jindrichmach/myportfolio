<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import PriceBadge from '@/components/portfolio/PriceBadge.vue'
import type { DataColumn } from '@/components/ui/DataTable.vue'
import DataGrid from '@/components/ui/DataGrid.vue'
import type { GridFilter, GridFilterValues } from '@/components/ui/grid'
import { marketApi, type FxRate, type MarketStatusRow } from '@/api/market'
import { adminApi } from '@/api/admin'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDec } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const rows = ref<MarketStatusRow[]>([])
const fxDate = ref<string | null>(null)
const cpiMonth = ref<string | null>(null)
const fxRates = ref<FxRate[]>([])
const loading = ref(true)
const running = ref<string | null>(null)

const MAIN_CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'PLN']
const mainRates = computed(() => fxRates.value.filter(r => MAIN_CURRENCIES.includes(r.currency)))
/** Stav ceny instrumentu: chybí (mimo ruční), zastaralá, aktuální. */
function priceStatus(r: MarketStatusRow): 'missing' | 'stale' | 'ok' {
  if (!r.last_price) return r.price_source === 'manual' ? 'ok' : 'missing'
  return r.last_price.stale ? 'stale' : 'ok'
}
const staleCount = computed(() => rows.value.filter(r => priceStatus(r) !== 'ok').length)

const filters = computed<GridFilter[]>(() => [
  { key: 'q', type: 'text', label: t('grid.search'), placeholder: t('prices.search_placeholder') },
  { key: 'status', type: 'select', label: t('prices.status'), allLabel: t('prices.all_statuses'),
    options: (['stale', 'missing', 'ok'] as const).map(st => ({ value: st, label: t(`prices.status_${st}`) })) },
])
function matchesRow(r: MarketStatusRow, v: GridFilterValues): boolean {
  const q = String(v.q ?? '').trim().toLowerCase()
  if (q && ![r.symbol, r.name].some(x => x.toLowerCase().includes(q))) return false
  return !v.status || priceStatus(r) === v.status
}

async function load() {
  loading.value = true
  try {
    const status = await marketApi.status()
    rows.value = status.instruments
    fxDate.value = status.fx.date
    cpiMonth.value = status.cpi.latest_month
    fxRates.value = status.fx.rates
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function run(script: 'cron-fx' | 'cron-prices') {
  running.value = script
  try {
    await adminApi.runCronJob(script)
    toast.success(t('prices.cron_started', { script }))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    running.value = null
  }
}
const columns = computed<DataColumn[]>(() => [
  { key: 'symbol', label: t('instruments.symbol'), primary: true },
  { key: 'chain', label: t('prices.chain'), hideBelow: 'lg' },
  { key: 'price', label: t('prices.last'), align: 'right' },
  { key: 'history', label: t('prices.history'), align: 'right' },
])
const rowKey = (r: MarketStatusRow) => r.id
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('prices.admin_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('prices.admin_subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button @click="run('cron-fx')" :disabled="running !== null" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">{{ t('prices.run_fx') }}</button>
        <button @click="run('cron-prices')" :disabled="running !== null" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">{{ t('prices.run_prices') }}</button>
        <button @click="load" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('activity_log.refresh') }}</button>
      </div>
    </div>
    <p class="text-xs text-neutral-500 mb-4">
      {{ t('prices.cron_hint') }}
      <RouterLink to="/admin/cron-jobs" class="text-primary-700 hover:underline">{{ t('nav.cron_jobs') }} →</RouterLink>
    </p>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <div v-if="staleCount" class="mb-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
        {{ t('prices.stale_banner', { n: staleCount }) }}
      </div>

      <section class="mb-4">
        <h2 class="text-lg font-semibold mb-2">{{ t('nav.instruments') }}</h2>
        <DataGrid :columns="columns" :rows="rows" :row-key="rowKey" :filters="filters" :filter-fn="matchesRow" sticky-first>
          <template #cell-symbol="{ row: r }">
            <span class="font-mono font-medium">{{ r.symbol }}</span>
            <span class="block text-xs text-neutral-500">{{ r.name }}</span>
          </template>
          <template #cell-chain="{ row: r }">
            <span class="text-neutral-600">
              <template v-if="r.chain.length">{{ r.chain.map((s: string) => t(`instruments.sources.${s}`)).join(' → ') }}</template>
              <template v-else>{{ t('instruments.sources.manual') }}</template>
            </span>
          </template>
          <template #cell-price="{ row: r }"><PriceBadge :price="r.last_price" show-source /></template>
          <template #cell-history="{ row: r }">
            <span class="text-neutral-600">
              <template v-if="r.history_days">{{ t('prices.history_since', { date: formatDate(r.history_from), n: r.history_days }) }}</template>
              <template v-else>—</template>
            </span>
          </template>
        </DataGrid>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">
          {{ t('prices.fx_title') }} <span v-if="fxDate" class="font-normal text-neutral-500">· {{ formatDate(fxDate) }}</span>
        </h2>
        <p v-if="!fxRates.length" class="p-4 text-sm text-neutral-500">{{ t('prices.fx_empty') }}</p>
        <div v-else class="p-4 flex flex-wrap gap-3">
          <div v-for="r in mainRates" :key="r.currency" class="border border-neutral-200 rounded-md px-3 py-2 min-w-28">
            <div class="text-xs text-neutral-500">{{ r.amount }} {{ r.currency }}</div>
            <div class="font-semibold tabular-nums">{{ formatDec(r.rate, 3, 'CZK') }}</div>
          </div>
          <span class="self-center text-xs text-neutral-500">{{ t('prices.fx_total', { n: fxRates.length }) }}</span>
        </div>
        <p class="px-4 py-2.5 text-xs text-neutral-500 border-t border-neutral-200">
          {{ cpiMonth ? t('prices.cpi_latest', { month: cpiMonth }) : t('prices.cpi_empty') }}
        </p>
      </section>
    </template>
  </div>
</template>
