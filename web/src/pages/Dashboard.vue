<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AllocationDonut from '@/components/portfolio/AllocationDonut.vue'
import HistoryChart from '@/components/portfolio/HistoryChart.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import Icon from '@/components/ui/Icon.vue'
import PositionActions from '@/components/portfolio/PositionActions.vue'
import NewInvestmentModal from '@/components/portfolio/NewInvestmentModal.vue'
import TransactionFormModal from '@/components/portfolio/TransactionFormModal.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { portfolioApi, type Position } from '@/api/portfolio'
import { useOwnerContext } from '@/composables/useOwnerContext'
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
import ManualLink from '@/components/ui/ManualLink.vue'

const { t } = useI18n()
const toast = useToast()

const data = ref<Dashboard | null>(null)
const loading = ref(true)
const allocationBy = ref<'category' | 'portfolio' | 'institution'>('category')
const allocation = ref<Allocation | null>(null)
const netWorth = ref<NetWorthOverview | null>(null)

// Přidat: nová investice (průvodce → první nákup), nová transakce, import
const { canWrite } = useOwnerContext()
const addModal = ref<'invest' | 'tx' | null>(null)
const txPosition = ref<Position | null>(null)
const txPositions = ref<Position[]>([])
async function openTx() {
  try {
    txPositions.value = await portfolioApi.positions()
    txPosition.value = null
    addModal.value = 'tx'
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
function investReady(p: Position) {
  txPosition.value = p
  addModal.value = 'tx'
}
function txSaved() {
  addModal.value = null
  reload()
}
const addItems = computed<RowAction[]>(() => [
  { key: 'invest', label: t('invest.title'), run: () => { addModal.value = 'invest' } },
  { key: 'tx', label: t('tx.new'), run: openTx },
  { key: 'import', label: t('nav.import'), to: '/transactions/import' },
])

// Prázdný přehled: kolik účtů a portfolií už existuje (odškrtnuté kroky)
const counts = ref<{ accounts: number; portfolios: number } | null>(null)
async function loadCounts() {
  try {
    const [a, p] = await Promise.all([portfolioApi.accounts(), portfolioApi.portfolios()])
    counts.value = { accounts: a.length, portfolios: p.length }
  } catch { /* checklist se ukáže bez odškrtnutí */ }
}
const startSteps = computed(() => [
  { key: 'account', title: t('dashboard.step_account'), hint: t('dashboard.step_account_hint'), to: '/accounts', done: (counts.value?.accounts ?? 0) > 0 },
  { key: 'portfolio', title: t('dashboard.step_portfolio'), hint: t('dashboard.step_portfolio_hint'), to: '/portfolios', done: (counts.value?.portfolios ?? 0) > 0 },
  { key: 'import', title: t('dashboard.step_import'), hint: t('dashboard.step_import_hint'), to: '/transactions/import', done: false },
  { key: 'invest', title: t('dashboard.step_invest'), hint: t('dashboard.step_invest_hint'), to: null, run: () => { addModal.value = 'invest' }, done: false },
])

async function reload() {
  try {
    data.value = await valuationApi.dashboard()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

onMounted(async () => {
  try {
    data.value = await valuationApi.dashboard()
    if (!data.value.positions.length) loadCounts()
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

const totalValue = computed(() => Number(data.value?.totals.value ?? 0))
// Portfolia: součty jen pro zobrazení (number), výnos a % z oceněných pozic jako v totals.
const groups = computed(() => {
  const out: { id: number; name: string; rows: PositionValuation[]; value: number; gain: number; basis: number }[] = []
  for (const row of data.value?.positions ?? []) {
    let g = out.find(x => x.id === row.portfolio_id)
    if (!g) out.push((g = { id: row.portfolio_id, name: row.portfolio_name, rows: [], value: 0, gain: 0, basis: 0 }))
    g.rows.push(row)
    g.value += Number(row.value ?? 0)
    if (row.total_return !== null) {
      g.gain += Number(row.total_return)
      g.basis += Number(row.cost_basis)
    }
  }
  return out
})
const groupShare = (g: { value: number }) =>
  totalValue.value > 0 ? t('dashboard.share', { pct: formatPercent((g.value / totalValue.value) * 100, 1) }) : ''

// Rozbalená portfolia (výchozí sbalené); pamatuje se v prohlížeči.
const OPEN_KEY = 'myportfolio-dashboard-open'
function readOpen(): number[] {
  try { return JSON.parse(localStorage.getItem(OPEN_KEY) ?? '[]') } catch { return [] }
}
const openGroups = ref<number[]>(readOpen())
function toggleGroup(id: number) {
  openGroups.value = openGroups.value.includes(id) ? openGroups.value.filter(x => x !== id) : [...openGroups.value, id]
  try { localStorage.setItem(OPEN_KEY, JSON.stringify(openGroups.value)) } catch { /* bez úložiště jen do obnovení stránky */ }
}

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
    { key: 'invested', label: t('valuation.invested'), value: money(x.invested), cls: '', hint: undefined },
    { key: 'unrealized', label: t('valuation.unrealized'), value: signed(x.unrealized), cls: gainClass(x.unrealized), hint: t('valuation.unrealized_hint') },
    { key: 'realized', label: t('valuation.realized'), value: signed(x.realized), cls: gainClass(x.realized), hint: t('valuation.realized_hint') },
    { key: 'income', label: t('valuation.income'), value: money(x.income), cls: '', hint: t('valuation.income_hint') },
    { key: 'fees', label: t('valuation.fees'), value: signed(Number(x.fees) ? `-${x.fees}` : x.fees), cls: '', hint: t('valuation.fees_hint') },
  ]
})

const targetClasses = computed(() => (allocation.value?.classes ?? []).filter(c => c.target_pct !== null))

// Tabulky
const cryptoColumns = computed<DataColumn[]>(() => [
  { key: 'symbol', label: t('instruments.symbol'), primary: true },
  { key: 'quantity', label: t('positions.holding'), align: 'right', mono: true },
  { key: 'value', label: t('valuation.value'), align: 'right' },
  { key: 'total_return', label: t('valuation.total_return'), align: 'right' },
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
// Krypto na jednom účtu vede rovnou do detailu pozice; na více účtech se řádek rozbalí.
const cryptoTo = (c: CryptoAcross) => (c.accounts.length === 1 ? `/positions/${c.accounts[0].position_id}` : null)
const positionKey = (r: PositionValuation) => r.position_id
const positionTo = (r: PositionValuation) => `/positions/${r.position_id}`
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('dashboard.title') }}</h1>
        <p v-if="data" class="text-sm text-neutral-500 mt-0.5">{{ t('dashboard.subtitle', { currency: data.currency, date: formatDate(data.as_of) }) }}</p>
      </div>
      <div class="flex items-center gap-3">
        <RouterLink to="/profile" class="text-xs text-primary-700 hover:underline">{{ t('dashboard.change_currency') }}</RouterLink>
        <RowActionsMenu v-if="canWrite" :items="addItems" :button-label="t('dashboard.add')" />
      </div>
    </div>
    <NewInvestmentModal v-if="addModal === 'invest'" @close="addModal = null" @ready="investReady" />
    <TransactionFormModal v-if="addModal === 'tx'" :position="txPosition ?? undefined" :positions="txPosition ? undefined : txPositions"
      @close="addModal = null" @saved="txSaved" />

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <!-- Prázdný přehled: první kroky (stejná cesta jako manuál, kap. 0) -->
    <section v-else-if="!hasData" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 sm:p-6">
      <h2 class="text-lg font-semibold">{{ t('dashboard.start_title') }}</h2>
      <p class="text-sm text-neutral-500 mt-1">{{ t('dashboard.start_intro') }}</p>
      <ol class="mt-4 space-y-3">
        <li v-for="(s, i) in startSteps" :key="s.key" class="flex items-start gap-3">
          <span class="mt-0.5 w-6 h-6 shrink-0 rounded-full inline-flex items-center justify-center text-xs font-semibold"
                :class="s.done ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">
            <Icon v-if="s.done" name="check" class="w-4 h-4" /><template v-else>{{ i + 1 }}</template>
            <span class="sr-only">{{ s.done ? t('dashboard.step_done') : '' }}</span>
          </span>
          <span class="min-w-0 text-sm">
            <RouterLink v-if="s.to" :to="s.to" class="font-medium text-primary-700 hover:underline">{{ s.title }}</RouterLink>
            <button v-else type="button" class="cursor-pointer font-medium text-primary-700 hover:underline" @click="s.run?.()">{{ s.title }}</button>
            <span class="block text-xs text-neutral-500">{{ s.hint }}</span>
          </span>
        </li>
      </ol>
      <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1">
        <ManualLink to="00_Prvni_kroky" :label="t('invest.first_steps')" />
        <ManualLink to="00_Prvni_kroky#01-pojmy-na-jednom-místě" :label="t('manual.terms')" />
      </div>
    </section>

    <template v-else-if="data">
      <!-- Souhrn: hlavní číslo + sekundární metriky, čisté jmění zvlášť (jiná základna, bez výnosu) -->
      <div class="grid gap-3 mb-4" :class="netWorth ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' : ''">
        <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:p-5">
          <KpiTile bare hero :label="t('dashboard.portfolio_value')" :value="money(data.totals.value)"
                   :sub="`${signed(data.totals.total_return)}${data.totals.return_pct !== null ? ` · ${pct(data.totals.return_pct)}` : ''} ${t('valuation.total_return').toLowerCase()}`"
                   :sub-class="gainClass(data.totals.total_return)" />
          <div class="mt-4 pt-4 border-t border-neutral-200 grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-x-4 gap-y-3">
            <KpiTile v-for="k in secondary" :key="k.key" bare :label="k.label" :value="k.value" :value-class="k.cls" :hint="k.hint" />
          </div>
          <ManualLink class="mt-3" to="07_Prehled#71-jak-se-počítá-hodnota-a-zisk" />
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

      <SectionCard class="mb-4">
          <template #title><RouterLink to="/allocation" class="hover:text-primary-700 hover:underline">{{ t('dashboard.allocation') }}</RouterLink></template>
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
          <DataTable v-else :columns="cryptoColumns" :rows="data.crypto" :row-key="cryptoKey" :expandable="c => c.accounts.length > 1" :row-to="cryptoTo">
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
            <template #cell-total_return="{ row: c }">
              <span :class="gainClass(c.total_return)">{{ signed(c.total_return) }}<span class="block text-xs">{{ pct(c.return_pct) }}</span></span>
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

      <SectionCard :title="t('dashboard.portfolios')" flush class="mb-4">
        <template #actions><RouterLink to="/positions" class="text-xs text-primary-700 hover:underline">{{ t('dashboard.all_positions') }}</RouterLink></template>
        <div class="divide-y divide-neutral-200">
          <div v-for="g in groups" :key="g.id">
            <button type="button" class="cursor-pointer w-full flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-neutral-50"
                    :aria-expanded="openGroups.includes(g.id)" :aria-controls="`portfolio-${g.id}`" @click="toggleGroup(g.id)">
              <Icon name="chevron-down" class="w-4 h-4 shrink-0 text-neutral-500 transition-transform" :class="{ '-rotate-90': !openGroups.includes(g.id) }" />
              <span class="min-w-0 flex-1">
                <span class="font-medium">{{ g.name }}</span>
                <span class="block text-xs text-neutral-500">{{ t('dashboard.positions_n', { n: g.rows.length }) }}<template v-if="groupShare(g)"> · {{ groupShare(g) }}</template></span>
              </span>
              <span class="text-right tabular-nums">
                <span class="block font-semibold">{{ formatMoney(g.value, cur, 0) }}</span>
                <span class="block text-xs" :class="gainClass(String(g.gain))">{{ formatSigned(String(g.gain), cur, 0) }}<template v-if="g.basis > 0"> · {{ formatPercent((g.gain / g.basis) * 100, 2, true) }}</template></span>
              </span>
            </button>
            <div v-if="openGroups.includes(g.id)" :id="`portfolio-${g.id}`" class="border-t border-neutral-200">
              <DataTable :columns="positionColumns" :rows="g.rows" :row-key="positionKey" :row-to="positionTo" sticky-first fixed>
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
                <template #actions="{ row: r }"><PositionActions :position-id="r.position_id" :name="r.display_name" :market="r.valuation_mode === 'market'" :cash="r.is_cash" @changed="reload" /></template>
              </DataTable>
            </div>
          </div>
        </div>
      </SectionCard>
    </template>
  </div>
</template>
