<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import TransactionTable from '@/components/portfolio/TransactionTable.vue'
import TransactionFormModal from '@/components/portfolio/TransactionFormModal.vue'
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
const meta = ref({ total: 0, page: 1, per_page: 50, pages: 1 })
const loading = ref(true)
const positions = ref<Position[]>([])
const portfolios = ref<Portfolio[]>([])
const accounts = ref<Account[]>([])
const filter = reactive({
  portfolio_id: '' as number | '',
  account_id: '' as number | '',
  type: '' as TransactionType | '',
  from: '',
  to: '',
  page: 1,
})

async function load() {
  loading.value = true
  try {
    const result = await portfolioApi.transactions({
      portfolio_id: filter.portfolio_id || undefined,
      account_id: filter.account_id || undefined,
      type: filter.type || undefined,
      from: filter.from || undefined,
      to: filter.to || undefined,
      page: filter.page,
      per_page: 50,
    })
    rows.value = result.data
    meta.value = result.meta
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  ;[positions.value, portfolios.value, accounts.value] = await Promise.all([
    portfolioApi.positions(), portfolioApi.portfolios(), portfolioApi.accounts(),
  ])
  await load()
})
watch(() => [filter.portfolio_id, filter.account_id, filter.type, filter.from, filter.to], () => {
  filter.page = 1
  load()
})

function goPage(p: number) {
  filter.page = p
  load()
}

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

const selectClass = 'w-full min-w-0 h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
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

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 grid gap-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_auto_auto]">
      <select v-model="filter.portfolio_id" :class="selectClass">
        <option value="">{{ t('positions.all_portfolios') }}</option>
        <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <select v-model="filter.account_id" :class="selectClass">
        <option value="">{{ t('tx.all_accounts') }}</option>
        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.institution_name }} / {{ a.name }}</option>
      </select>
      <select v-model="filter.type" :class="selectClass">
        <option value="">{{ t('tx.all_types') }}</option>
        <option v-for="tp in ALL_TYPES" :key="tp" :value="tp">{{ t(`tx.types.${tp}`) }}</option>
      </select>
      <label class="flex items-center gap-1.5 text-sm text-neutral-600">
        <span class="w-6 shrink-0">{{ t('tx.from') }}</span> <input v-model="filter.from" type="date" :class="selectClass" class="lg:w-40" />
      </label>
      <label class="flex items-center gap-1.5 text-sm text-neutral-600">
        <span class="w-6 shrink-0">{{ t('tx.to') }}</span> <input v-model="filter.to" type="date" :class="selectClass" class="lg:w-40" />
      </label>
    </div>

    <div v-if="loading && !rows.length" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <SectionCard v-else :title="t('tx.title')" :meta="t('tx.count', { n: meta.total })" flush>
      <EmptyState v-if="!rows.length" :title="t('tx.empty')" :message="positions.length ? undefined : t('tx.no_positions')" />
      <TransactionTable v-else :rows="rows" show-position @edit="tx => (txModal = { tx })" @delete="removeTx" />
      <div v-if="meta.pages > 1" class="px-3 py-2 border-t border-neutral-200 flex items-center justify-end gap-2 text-sm">
        <div class="flex items-center gap-2">
          <button :disabled="meta.page <= 1" @click="goPage(meta.page - 1)" class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md disabled:opacity-40">{{ t('common.previous') }}</button>
          <span class="text-neutral-500">{{ t('common.page') }} {{ meta.page }} / {{ meta.pages }}</span>
          <button :disabled="meta.page >= meta.pages" @click="goPage(meta.page + 1)" class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md disabled:opacity-40">{{ t('common.next') }}</button>
        </div>
      </div>
    </SectionCard>

    <TransactionFormModal v-if="txModal" :positions="positions" :transaction="txModal.tx" @close="txModal = null" @saved="onSaved" />
  </div>
</template>
