<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import PriceBadge from '@/components/portfolio/PriceBadge.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import CashBalanceModal from '@/components/portfolio/CashBalanceModal.vue'
import { catalogApi, type Instrument } from '@/api/catalog'
import { portfolioApi, type Account, type Portfolio, type Position, type ValuationMode } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDec } from '@/composables/useFormat'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const { canWrite } = useOwnerContext()
const route = useRoute()
const router = useRouter()

const rows = ref<Position[]>([])
const portfolios = ref<Portfolio[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const portfolioFilter = ref<number | ''>(Number(route.query.portfolio_id) || '')
const showArchived = ref(false)

async function load() {
  loading.value = true
  try {
    ;[rows.value, portfolios.value, accounts.value] = await Promise.all([
      portfolioApi.positions({ portfolio_id: portfolioFilter.value || undefined, archived: showArchived.value }),
      portfolioApi.portfolios(),
      portfolioApi.accounts(),
    ])
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch([portfolioFilter, showArchived], () => {
  router.replace({ query: { ...route.query, portfolio_id: portfolioFilter.value || undefined } })
  load()
})

const groups = computed(() => {
  const map = new Map<number, { id: number; name: string; items: Position[] }>()
  for (const p of rows.value) {
    if (!map.has(p.portfolio_id)) map.set(p.portfolio_id, { id: p.portfolio_id, name: p.portfolio_name, items: [] })
    map.get(p.portfolio_id)!.items.push(p)
  }
  return [...map.values()]
})

const columns = computed<DataColumn[]>(() => [
  { key: 'position', label: t('positions.position'), primary: true },
  { key: 'account', label: t('accounts.institution'), hideBelow: 'lg', hideInCard: true, width: '26%' },
  { key: 'holding', label: t('positions.holding'), align: 'right', width: '17%' },
  { key: 'price', label: t('prices.last'), align: 'right', width: '17%' },
])
const positionKey = (p: Position) => p.id
const archivedClass = (p: Position) => (p.archived_at ? 'opacity-50' : '')
/** Tržní pozice bez vlastní ikony dostane barevné iniciály, ne ikonu instituce. */
const positionIcon = (p: Position) => (p.valuation_mode === 'market' ? p.instrument_icon_url : (p.instrument_icon_url ?? p.institution_icon_url))
const isCrypto = (p: Position) => p.asset_class === 'crypto'

function holdingLabel(p: Position): string {
  return p.valuation_mode === 'market'
    ? formatDec(p.holding, p.instrument_decimals ?? 8)
    : formatDec(p.holding, 2, p.currency)
}

const cashModal = ref<Position | null>(null)

// ── Nová pozice ─────────────────────────────────────────────────────────
const showForm = ref(false)
const busy = ref(false)
const error = ref('')
const instrumentOptions = ref<Instrument[]>([])
const searching = ref(false)
// Druh nové pozice: tržní, ruční, nebo hotovost (ruční se zadávaným zůstatkem)
type PositionKind = ValuationMode | 'cash'
const form = reactive({
  valuation_mode: 'market' as PositionKind,
  portfolio_id: null as number | null,
  account_id: null as number | null,
  instrument_id: null as number | null,
  name: '',
  currency: '',
  interest_rate_pa: '',
})
const canCreate = computed(() => portfolios.value.length > 0 && accounts.value.length > 0)

function openCreate() {
  Object.assign(form, {
    valuation_mode: 'market',
    portfolio_id: portfolioFilter.value || portfolios.value[0]?.id || null,
    account_id: accounts.value[0]?.id ?? null,
    instrument_id: null,
    name: '',
    currency: '',
    interest_rate_pa: '',
  })
  error.value = ''
  showForm.value = true
  searchInstruments('')
}

async function searchInstruments(q: string) {
  searching.value = true
  try { instrumentOptions.value = await catalogApi.instruments({ q }) }
  finally { searching.value = false }
}

async function save() {
  if (form.portfolio_id === null || form.account_id === null) return
  busy.value = true
  error.value = ''
  try {
    const kind = form.valuation_mode
    const created = await portfolioApi.createPosition({
      valuation_mode: kind === 'market' ? 'market' : 'manual',
      is_cash: kind === 'cash',
      portfolio_id: form.portfolio_id,
      account_id: form.account_id,
      instrument_id: kind === 'market' ? form.instrument_id : null,
      name: form.name.trim() || null,
      currency: kind !== 'market' ? (form.currency.trim() || null) : null,
      interest_rate_pa: kind === 'manual' ? (form.interest_rate_pa.trim() || null) : null,
    })
    showForm.value = false
    router.push(`/positions/${created.id}`)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('positions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('positions.subtitle') }}</p>
      </div>
      <button v-if="canWrite" @click="openCreate" :disabled="!canCreate" :title="canCreate ? undefined : t('positions.no_prereq')"
        class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('positions.new') }}
      </button>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 flex flex-wrap items-center gap-3">
      <select v-model="portfolioFilter" class="h-9 px-3 border border-field rounded-md bg-surface text-sm">
        <option value="">{{ t('positions.all_portfolios') }}</option>
        <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-600">
        <input v-model="showArchived" type="checkbox" class="rounded border-field text-primary-700" />
        {{ t('common.show_archived') }}
      </label>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="!rows.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('positions.empty')" :message="canCreate ? t('positions.empty_hint') : t('positions.no_prereq')" />
      <p v-if="!canCreate" class="text-center pb-8 -mt-6 space-x-4 text-sm">
        <RouterLink to="/accounts" class="text-primary-700 hover:underline">{{ t('nav.accounts') }} →</RouterLink>
        <RouterLink to="/portfolios" class="text-primary-700 hover:underline">{{ t('nav.portfolios') }} →</RouterLink>
      </p>
    </div>

    <div v-else class="space-y-4">
      <SectionCard v-for="g in groups" :key="g.id" :title="g.name" flush>
        <DataTable :columns="columns" :rows="g.items" :row-key="positionKey" :row-class="archivedClass" sticky-first fixed>
          <template #cell-position="{ row: p }">
            <div class="flex items-center gap-2.5 min-w-0">
              <EntityIcon :src="positionIcon(p)" :name="p.display_name" :size="28" />
              <div class="min-w-0">
                <RouterLink :to="`/positions/${p.id}`" class="font-medium hover:text-primary-700 hover:underline" :class="{ 'font-mono': p.instrument_symbol }">
                  {{ p.display_name }}
                </RouterLink>
                <span class="block text-xs text-neutral-500 truncate">
                  {{ p.instrument_name ?? t(`positions.modes.${p.is_cash ? 'cash' : p.valuation_mode}`) }}
                  <template v-if="p.asset_class"> · {{ t(`instruments.classes.${p.asset_class}`) }}</template>
                </span>
                <span class="flex lg:hidden items-center gap-1 text-xs text-neutral-500 truncate">
                  <EntityIcon :src="p.institution_icon_url" :name="p.institution_name" :size="14" />
                  {{ p.institution_name }} / {{ p.account_name }}
                </span>
              </div>
            </div>
          </template>
          <template #cell-account="{ row: p }">
            <span class="inline-flex items-center gap-1.5 text-neutral-600">
              <EntityIcon :src="p.institution_icon_url" :name="p.institution_name" :size="18" />
              {{ p.institution_name }} / {{ p.account_name }}
            </span>
          </template>
          <template #cell-holding="{ row: p }">
            <span :class="{ 'font-mono': isCrypto(p) }">{{ holdingLabel(p) }}</span>
            <span v-if="p.valuation_mode === 'market'" class="ml-1 text-xs text-neutral-500 font-mono">{{ p.instrument_symbol }}</span>
          </template>
          <template #cell-price="{ row: p }">
            <PriceBadge v-if="p.valuation_mode === 'market'" :price="p.last_price" :mono="isCrypto(p)" />
            <template v-else-if="p.current_value">
              <span class="whitespace-nowrap">{{ p.current_value.value !== null ? formatDec(p.current_value.value, 2, p.currency) : '—' }}</span>
              <span class="block text-xs text-neutral-500 whitespace-nowrap">
                {{ p.current_value.valuation_date ? t('positions.valued_at', { date: formatDate(p.current_value.valuation_date) }) : t('positions.no_valuation') }}
              </span>
            </template>
            <span v-else class="text-neutral-400">—</span>
          </template>
          <template #actions="{ row: p }">
            <span class="inline-flex gap-1">
              <IconButton v-if="p.is_cash && canWrite" icon="price" :label="t('cash.set')" @click="cashModal = p" />
              <IconButton icon="detail" :label="t('common.detail')" :to="`/positions/${p.id}`" />
            </span>
          </template>
        </DataTable>
      </SectionCard>
    </div>

    <CashBalanceModal v-if="cashModal" :position="cashModal" @close="cashModal = null" @saved="cashModal = null; load()" />

    <Modal v-if="showForm" :title="t('positions.new')" width-class="max-w-lg" @close="showForm = false">
      <form id="position-form" class="space-y-3" @submit.prevent="save">
        <div class="grid grid-cols-3 gap-2">
          <label v-for="m in (['market', 'manual', 'cash'] as const)" :key="m"
            class="cursor-pointer border rounded-md p-3 text-sm"
            :class="form.valuation_mode === m ? 'border-primary-500 bg-primary-50' : 'border-neutral-300 hover:bg-neutral-50'">
            <input v-model="form.valuation_mode" type="radio" :value="m" class="sr-only" />
            <span class="font-medium block">{{ t(`positions.modes.${m}`) }}</span>
            <span class="text-xs text-neutral-500">{{ t(`positions.mode_hints.${m}`) }}</span>
          </label>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label :for="fid('portfolio_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.portfolio') }}</label>
            <select :id="fid('portfolio_id')" v-model="form.portfolio_id" required :class="inputClass">
              <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label :for="fid('account_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.account') }}</label>
            <select :id="fid('account_id')" v-model="form.account_id" required :class="inputClass">
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.institution_name }} / {{ a.name }}</option>
            </select>
          </div>
        </div>

        <template v-if="form.valuation_mode === 'market'">
          <div>
            <label :for="fid('instrument_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.instrument') }}</label>
            <SearchableSelect :input-id="fid('instrument_id')" v-model="form.instrument_id" remote :loading="searching"
              :options="instrumentOptions.map(i => ({ value: i.id, label: `${i.symbol} (${i.quote_currency})`, secondary: i.name }))"
              :placeholder="t('instruments.search_placeholder')" @search="searchInstruments" />
            <p class="text-xs text-neutral-500 mt-1">
              {{ t('positions.instrument_missing') }}
              <RouterLink to="/catalog/instruments" class="text-primary-700 hover:underline">{{ t('instruments.new') }}</RouterLink>
            </p>
          </div>
        </template>
        <template v-else>
          <div>
            <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }} <span v-if="form.valuation_mode === 'cash'" class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
            <input :id="fid('name')" v-model="form.name" :required="form.valuation_mode === 'manual'" maxlength="120"
              :placeholder="form.valuation_mode === 'cash' ? t('cash.name_placeholder', { currency: (form.currency || accounts.find(a => a.id === form.account_id)?.currency || 'CZK').toUpperCase() }) : t('positions.manual_name_placeholder')" :class="inputClass" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label :for="fid('currency')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
              <input :id="fid('currency')" v-model="form.currency" maxlength="3" :placeholder="accounts.find(a => a.id === form.account_id)?.currency" class="uppercase font-mono" :class="inputClass" />
            </div>
            <div v-if="form.valuation_mode === 'manual'">
              <label :for="fid('interest_rate_pa')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.interest_rate') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
              <input :id="fid('interest_rate_pa')" v-model="form.interest_rate_pa" inputmode="decimal" :class="inputClass" />
            </div>
          </div>
        </template>
        <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="position-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.create') }}</button>
      </template>
    </Modal>
  </div>
</template>
