<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'
import AllocationDonut from '@/components/portfolio/AllocationDonut.vue'
import HistoryChart from '@/components/portfolio/HistoryChart.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import KpiTile from '@/components/ui/KpiTile.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import AllocationBar from '@/components/ui/AllocationBar.vue'
import Badge from '@/components/ui/Badge.vue'
import type { HistoryScope } from '@/api/history'
import { allocationApi, type Allocation } from '@/api/allocation'
import { netWorthApi, type NetWorthOverview } from '@/api/netWorth'
import { valuationApi, warningKey, type CryptoAcross, type Dashboard, type PositionValuation } from '@/api/valuation'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDec, formatMoney, formatPercent, formatSigned } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { gainClass } from '@/utils/tone'

const { t } = useI18n()
const toast = useToast()

const data = ref<Dashboard | null>(null)
const loading = ref(true)
const allocationBy = ref<'category' | 'portfolio' | 'institution'>('category')
const allocation = ref<Allocation | null>(null)
const netWorth = ref<NetWorthOverview | null>(null)

onMounted(async () => {
  try {
    data.value = await valuationApi.dashboard()
    netWorthApi.overview().then(n => { netWorth.value = n.items.length ? n : null }).catch(() => {})
    allocationApi.get(null).then(a => { allocation.value = a.has_targets ? a : null }).catch(() => {})
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
})

const cur = computed(() => data.value?.currency ?? 'CZK')
const money = (v: string | null | undefined, dec = 0) => (v === null || v === undefined ? '—' : formatMoney(Number(v), cur.value, dec))
const signed = (v: string | null | undefined) => formatSigned(v, cur.value, 0)
const pct = (v: string | null | undefined) => (v === null || v === undefined ? '' : formatPercent(v, 2, true))
/** Výnos v % jen pro zobrazení (zisk / investováno) — u krypta API procenta nevrací. */
const ratioPct = (gain: string | null, base: string) => (gain === null || Number(base) <= 0 ? '' : formatPercent((Number(gain) / Number(base)) * 100, 2, true))

const totalValue = computed(() => Number(data.value?.totals.value ?? 0))
const groups = computed(() => {
  const out: { id: number; name: string; rows: PositionValuation[]; value: number }[] = []
  for (const row of data.value?.positions ?? []) {
    let g = out.find(x => x.id === row.portfolio_id)
    if (!g) out.push((g = { id: row.portfolio_id, name: row.portfolio_name, rows: [], value: 0 }))
    g.rows.push(row)
    g.value += Number(row.value ?? 0)
  }
  return out
})
const groupMeta = (g: { value: number }) =>
  `${formatMoney(g.value, cur.value, 0)}${totalValue.value > 0 ? ` · ${t('dashboard.share', { pct: formatPercent((g.value / totalValue.value) * 100, 1) })}` : ''}`

const categoryLabel = (l: string) => t(`instruments.classes.${l}`)

// Graf historie: celkem / portfolio / třída aktiv
const historyKey = ref('total')
const historyScope = computed(() => historyKey.value.split(':')[0] as HistoryScope)
const historyId = computed(() => historyKey.value.split(':')[1] ?? null)
const historyCategories = computed(() => [...new Set((data.value?.positions ?? []).map(p => p.category))])
const hasData = computed(() => (data.value?.positions.length ?? 0) > 0)

const secondary = computed(() => {
  const x = data.value?.totals
  if (!x) return []
  return [
    { key: 'invested', label: t('valuation.invested'), value: money(x.invested), cls: '' },
    { key: 'unrealized', label: t('valuation.unrealized'), value: signed(x.unrealized), cls: gainClass(x.unrealized) },
    { key: 'realized', label: t('valuation.realized'), value: signed(x.realized), cls: gainClass(x.realized) },
    { key: 'income', label: t('valuation.income'), value: money(x.income), cls: '' },
  ]
})

const targetClasses = computed(() => (allocation.value?.classes ?? []).filter(c => c.target_pct !== null))

// Tabulky
const cryptoColumns = computed<DataColumn[]>(() => [
  { key: 'symbol', label: t('instruments.symbol'), primary: true },
  { key: 'quantity', label: t('positions.holding'), align: 'right', mono: true },
  { key: 'value', label: t('valuation.value'), align: 'right' },
  { key: 'profit', label: t('valuation.profit'), align: 'right' },
])
const positionColumns = computed<DataColumn[]>(() => [
  { key: 'position', label: t('positions.position'), primary: true },
  { key: 'quantity', label: t('positions.holding'), align: 'right', width: '15%' },
  { key: 'value', label: t('valuation.value'), align: 'right', width: '15%' },
  { key: 'invested', label: t('valuation.invested'), align: 'right', hideBelow: 'lg', width: '15%' },
  { key: 'total_return', label: t('valuation.total_return'), align: 'right', width: '16%' },
])
/** Tržní pozice bez vlastní ikony dostane barevné iniciály, ne ikonu instituce (všechna ETF u jednoho brokera by vypadala stejně). */
const positionIcon = (r: PositionValuation) => (r.valuation_mode === 'market' ? r.instrument_icon_url : (r.instrument_icon_url ?? r.institution_icon_url))
const cryptoKey = (c: CryptoAcross) => c.instrument_id
const positionKey = (r: PositionValuation) => r.position_id
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('dashboard.title') }}</h1>
        <p v-if="data" class="text-sm text-neutral-500 mt-0.5">{{ t('dashboard.subtitle', { currency: data.currency, date: formatDate(data.as_of) }) }}</p>
      </div>
      <RouterLink to="/profile" class="text-xs text-primary-700 hover:underline">{{ t('dashboard.change_currency') }}</RouterLink>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="!hasData" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('dashboard.empty')" :cta="t('positions.new')" to="/positions" />
    </div>

    <template v-else-if="data">
      <!-- Souhrn: hlavní číslo + sekundární metriky, čisté jmění zvlášť (jiná základna, bez výnosu) -->
      <div class="grid gap-3 mb-4" :class="netWorth ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : ''">
        <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:p-5">
          <KpiTile bare hero :label="t('dashboard.portfolio_value')" :value="money(data.totals.value)"
                   :sub="`${signed(data.totals.total_return)}${data.totals.return_pct !== null ? ` · ${pct(data.totals.return_pct)}` : ''} ${t('valuation.total_return').toLowerCase()}`"
                   :sub-class="gainClass(data.totals.total_return)" />
          <div class="mt-4 pt-4 border-t border-neutral-200 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-3">
            <KpiTile v-for="k in secondary" :key="k.key" bare :label="k.label" :value="k.value" :value-class="k.cls" />
          </div>
        </section>
        <KpiTile v-if="netWorth" :label="t('net_worth.net_worth')" :value="money(netWorth.net_worth)" to="/net-worth" :hint="t('dashboard.net_worth_hint')" class="sm:p-5">
          <dl class="mt-3 pt-3 border-t border-neutral-200 space-y-1 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('net_worth.investments') }}</dt><dd class="tabular-nums">{{ money(netWorth.investments) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('net_worth.assets') }}</dt><dd class="tabular-nums">{{ money(netWorth.assets) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('net_worth.liabilities') }}</dt><dd class="tabular-nums">{{ formatSigned(String(-Number(netWorth.liabilities)), cur, 0) }}</dd></div>
          </dl>
        </KpiTile>
      </div>

      <div v-if="data.warnings.length || data.totals.incomplete" class="mb-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
        <p v-if="data.totals.incomplete" class="font-medium"><Icon name="warning" class="w-4 h-4 align-[-3px] mr-1" />{{ t('dashboard.incomplete', { n: data.totals.incomplete }) }}</p>
        <ul class="mt-1 space-y-0.5 text-xs">
          <li v-for="(w, i) in data.warnings.slice(0, 8)" :key="i">
            <RouterLink :to="`/positions/${w.position_id}`" class="font-medium hover:underline">{{ w.display_name }}</RouterLink>:
            {{ t(warningKey(w.code).key, warningKey(w.code).params) }}
          </li>
        </ul>
      </div>

      <HistoryChart class="mb-4" :scope="historyScope" :id="historyId">
        <template #actions>
          <select v-model="historyKey" :aria-label="t('history.title')" class="h-7 px-2 border border-field rounded-md bg-surface text-xs">
            <option value="total">{{ t('history.scope_total') }}</option>
            <optgroup :label="t('nav.portfolios')">
              <option v-for="g in groups" :key="g.id" :value="`portfolio:${g.id}`">{{ g.name }}</option>
            </optgroup>
            <optgroup :label="t('instruments.asset_class')">
              <option v-for="c in historyCategories" :key="c" :value="`class:${c}`">{{ categoryLabel(c) }}</option>
            </optgroup>
          </select>
        </template>
      </HistoryChart>

      <SectionCard :title="t('dashboard.allocation')" class="mb-4">
          <template #actions>
            <select v-model="allocationBy" :aria-label="t('dashboard.allocation')" class="h-7 px-2 border border-field rounded-md bg-surface text-xs">
              <option value="category">{{ t('dashboard.by_category') }}</option>
              <option value="portfolio">{{ t('dashboard.by_portfolio') }}</option>
              <option value="institution">{{ t('dashboard.by_institution') }}</option>
            </select>
          </template>
          <div class="grid gap-x-8 gap-y-4" :class="allocation && targetClasses.length ? 'lg:grid-cols-2' : ''">
          <AllocationDonut :slices="data.allocation[allocationBy]" :currency="data.currency"
            :label-for="allocationBy === 'category' ? categoryLabel : undefined" />
          <div v-if="allocation && targetClasses.length" class="space-y-3 pt-4 border-t border-neutral-200 lg:pt-0 lg:border-t-0 lg:pl-8 lg:border-l">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ t('dashboard.targets') }}</h3>
              <span class="flex items-center gap-2">
                <Badge v-if="allocation.out_of_band" tone="warning">{{ t('dashboard.out_of_band', { n: allocation.out_of_band }) }}</Badge>
                <RouterLink to="/allocation" class="text-xs text-primary-700 hover:underline">{{ t('dashboard.targets_link') }}</RouterLink>
              </span>
            </div>
            <AllocationBar v-for="c in targetClasses" :key="c.category" :label="t(`allocation.categories.${c.category}`)"
              :pct="c.pct" :target="c.target_pct" :out-of-band="c.out_of_band"
              :detail="c.diff_amount !== null ? t('dashboard.vs_target', { amount: signed(c.diff_amount) }) : undefined" />
          </div>
          </div>
        </SectionCard>

        <SectionCard :title="t('dashboard.crypto_across')" flush class="mb-4">
          <p v-if="!data.crypto.length" class="p-4 text-sm text-neutral-500">{{ t('dashboard.crypto_empty') }}</p>
          <DataTable v-else :columns="cryptoColumns" :rows="data.crypto" :row-key="cryptoKey" :expandable="c => c.accounts.length > 1">
            <template #cell-symbol="{ row: c }">
              <span class="flex items-center gap-2 min-w-0">
                <EntityIcon :src="c.icon_url" :name="c.symbol" :size="28" />
                <span class="min-w-0">
                  <span class="font-mono font-medium">{{ c.symbol }}</span> <span class="text-xs text-neutral-500">{{ c.name }}</span>
                  <span class="block text-xs text-neutral-500 truncate">
                    <RouterLink v-if="c.accounts.length === 1" :to="`/positions/${c.accounts[0].position_id}`" class="hover:text-primary-700">{{ c.accounts[0].account }}</RouterLink>
                    <template v-else>{{ t('dashboard.accounts_n', { n: c.accounts.length }) }}</template>
                  </span>
                </span>
              </span>
            </template>
            <template #cell-quantity="{ row: c }">{{ formatDec(c.quantity, 8) }}</template>
            <template #cell-value="{ row: c }">{{ money(c.value) }}</template>
            <template #cell-profit="{ row: c }">
              <span :class="gainClass(c.profit)">{{ signed(c.profit) }}<span class="block text-xs">{{ ratioPct(c.profit, c.invested) }}</span></span>
            </template>
            <template #expanded="{ row: c }">
              <ul class="space-y-1">
                <li v-for="a in c.accounts" :key="a.position_id" class="flex flex-wrap justify-between gap-x-4">
                  <RouterLink :to="`/positions/${a.position_id}`" class="text-primary-700 hover:underline">{{ a.account }}</RouterLink>
                  <span class="tabular-nums"><span class="font-mono">{{ formatDec(a.quantity, 8) }}</span> · {{ money(a.value) }}</span>
                </li>
              </ul>
            </template>
          </DataTable>
        </SectionCard>

      <SectionCard v-for="g in groups" :key="g.id" :title="g.name" :meta="groupMeta(g)" flush class="mb-4">
        <DataTable :columns="positionColumns" :rows="g.rows" :row-key="positionKey" sticky-first fixed>
          <template #cell-position="{ row: r }">
            <div class="flex items-center gap-2.5 min-w-0">
              <EntityIcon :src="positionIcon(r)" :name="r.display_name" :size="28" />
              <div class="min-w-0">
                <RouterLink :to="`/positions/${r.position_id}`" class="font-medium hover:text-primary-700" :class="{ 'font-mono': r.valuation_mode === 'market' }">{{ r.display_name }}</RouterLink>
                <span v-if="r.warnings.length" class="ml-1 text-warning-600" :title="r.warnings.map(w => t(warningKey(w).key, warningKey(w).params)).join('\n')"><Icon name="warning" class="w-4 h-4 align-[-3px]" /></span>
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
          <template #actions="{ row: r }"><IconButton icon="detail" :label="t('common.detail')" :to="`/positions/${r.position_id}`" /></template>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
