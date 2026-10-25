<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import TransactionTable from '@/components/portfolio/TransactionTable.vue'
import TransactionFormModal from '@/components/portfolio/TransactionFormModal.vue'
import type { GridDateRange, GridFilter, GridQuery } from '@/components/ui/grid'
import {
  portfolioApi, ALL_TYPES,
  type Account, type Portfolio, type Position, type Transaction, type TransactionType,
} from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const { canWrite } = useOwnerContext()
const toast = useToast()

const rows = ref<Transaction[]>([])
const total = ref(0)
const loading = ref(true)
const positions = ref<Position[]>([])
const portfolios = ref<Portfolio[]>([])
const accounts = ref<Account[]>([])
let lastQuery: GridQuery | null = null

/** Filtry jdou na server: portfolio, účet, typ a období. */
const filters = computed<GridFilter[]>(() => [
  { key: 'portfolio_id', type: 'select', label: t('positions.portfolio'), allLabel: t('positions.all_portfolios'),
    options: portfolios.value.map(p => ({ value: String(p.id), label: p.name })) },
  { key: 'account_id', type: 'select', label: t('positions.account'), allLabel: t('tx.all_accounts'),
    options: accounts.value.map(a => ({ value: String(a.id), label: `${a.institution_name} / ${a.name}` })) },
  { key: 'type', type: 'select', label: t('common.type'), allLabel: t('tx.all_types'),
    options: ALL_TYPES.map(tp => ({ value: tp, label: t(`tx.types.${tp}`) })) },
  { key: 'period', type: 'daterange', label: t('grid.period') },
])

async function load(query: GridQuery | null = lastQuery) {
  if (!query) return
  lastQuery = query
  const f = query.filterValues
  const range = (f.period ?? { from: '', to: '' }) as GridDateRange
  loading.value = true
  try {
    const result = await portfolioApi.transactions({
      portfolio_id: f.portfolio_id ? Number(f.portfolio_id) : undefined,
      account_id: f.account_id ? Number(f.account_id) : undefined,
      type: (f.type || undefined) as TransactionType | undefined,
      from: range.from || undefined,
      to: range.to || undefined,
      page: query.page,
      per_page: query.pageSize,
    })
    rows.value = result.data
    total.value = result.meta.total
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  ;[positions.value, portfolios.value, accounts.value] = await Promise.all([
    portfolioApi.positions(), portfolioApi.portfolios(), portfolioApi.accounts(),
  ])
})

const txModal = ref<{ tx: Transaction | null } | null>(null)

async function onSaved() {
  txModal.value = null
  await load()
}

async function removeTx(tx: Transaction) {
  if (!confirm(tx.transfer_group_id ? t('tx.delete_transfer_confirm') : t('tx.delete_confirm'))) return
  try {
    await portfolioApi.deleteTransaction(tx.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('tx.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('tx.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <RouterLink v-if="canWrite" to="/transactions/import" class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
          {{ t('nav.import') }}
        </RouterLink>
        <button v-if="canWrite" @click="txModal = { tx: null }" :disabled="!positions.length" :title="positions.length ? undefined : t('tx.no_positions')"
          class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md">
          {{ t('tx.new') }}
        </button>
      </div>
    </div>

    <TransactionTable :rows="rows" :total="total" :loading="loading" :filters="filters" show-position
      :empty-text="t('tx.empty')" :empty-hint="positions.length ? undefined : t('tx.no_positions')"
      @change="load" @edit="tx => (txModal = { tx })" @delete="removeTx" />

    <TransactionFormModal v-if="txModal" :positions="positions" :transaction="txModal.tx" @close="txModal = null" @saved="onSaved" />
  </div>
</template>
