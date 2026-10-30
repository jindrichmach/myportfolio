<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TransactionTable from '@/components/portfolio/TransactionTable.vue'
import type { GridQuery } from '@/components/ui/grid'
import TransactionFormModal from '@/components/portfolio/TransactionFormModal.vue'
import TransferFormModal from '@/components/portfolio/TransferFormModal.vue'
import PositionValuationPanel from '@/components/portfolio/PositionValuationPanel.vue'
import HistoryChart from '@/components/portfolio/HistoryChart.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Badge from '@/components/ui/Badge.vue'
import CashBalanceModal from '@/components/portfolio/CashBalanceModal.vue'
import { portfolioApi, type Account, type Portfolio, type Position, type Transaction } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const { canWrite, canManage } = useOwnerContext()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const id = computed(() => Number(route.params.id))
const position = ref<Position | null>(null)
const transactions = ref<Transaction[]>([])
const total = ref(0)
const loading = ref(true)
const txLoading = ref(true)
let lastQuery: GridQuery | null = null
const notFound = ref(false)
const valuationPanel = ref<InstanceType<typeof PositionValuationPanel> | null>(null)
const historyChart = ref<InstanceType<typeof HistoryChart> | null>(null)

async function load() {
  loading.value = true
  try {
    ;[position.value] = await Promise.all([portfolioApi.position(id.value), loadTx()])
  } catch (e: any) {
    if (e?.response?.status === 404) notFound.value = true
    else toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

/** Stránka transakcí pozice — grid ji vyžádá po připojení a při změně stránky. */
async function loadTx(query: GridQuery | null = lastQuery) {
  if (!query) return
  lastQuery = query
  txLoading.value = true
  try {
    const list = await portfolioApi.transactions({ position_id: id.value, page: query.page, per_page: query.pageSize })
    transactions.value = list.data
    total.value = list.meta.total
  } finally {
    txLoading.value = false
  }
}

// ── Transakce ───────────────────────────────────────────────────────────
const txModal = ref<{ tx: Transaction | null } | null>(null)
const transferOpen = ref(false)
const allPositions = ref<Position[]>([])

async function openTransfer() {
  allPositions.value = await portfolioApi.positions()
  transferOpen.value = true
}

const cashOpen = ref(false)

async function onSaved() {
  txModal.value = null
  transferOpen.value = false
  cashOpen.value = false
  await load()
  valuationPanel.value?.load()
  historyChart.value?.load()
}

async function removeTx(tx: Transaction) {
  const msg = tx.transfer_group_id ? t('tx.delete_transfer_confirm') : t('tx.delete_confirm')
  if (!confirm(msg)) return
  try {
    await portfolioApi.deleteTransaction(tx.id)
    await load()
    valuationPanel.value?.load()
    historyChart.value?.load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

// ── Úprava pozice ───────────────────────────────────────────────────────
const editOpen = ref(false)
const editBusy = ref(false)
const editError = ref('')
const portfolios = ref<Portfolio[]>([])
const accounts = ref<Account[]>([])
const editForm = reactive({ portfolio_id: 0, account_id: 0, name: '', interest_rate_pa: '', archived: false, bank_account: '', bank_notices_from: '' })

async function openEdit() {
  const p = position.value
  if (!p) return
  ;[portfolios.value, accounts.value] = await Promise.all([portfolioApi.portfolios(), portfolioApi.accounts()])
  Object.assign(editForm, {
    portfolio_id: p.portfolio_id,
    account_id: p.account_id,
    name: p.name ?? '',
    interest_rate_pa: p.interest_rate_pa ?? '',
    archived: p.archived_at !== null,
    bank_account: p.bank_account ?? '',
    bank_notices_from: p.bank_notices_from ?? '',
  })
  editError.value = ''
  editOpen.value = true
}

async function saveEdit() {
  editBusy.value = true
  editError.value = ''
  try {
    position.value = await portfolioApi.updatePosition(id.value, {
      portfolio_id: editForm.portfolio_id,
      account_id: editForm.account_id,
      name: editForm.name.trim() || null,
      interest_rate_pa: editForm.interest_rate_pa.trim() || null,
      archived: editForm.archived,
      ...(position.value?.valuation_mode === 'manual'
        ? { bank_account: editForm.bank_account.trim(), bank_notices_from: editForm.bank_notices_from || null }
        : {}),
    })
    editOpen.value = false
  } catch (e) {
    editError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    editBusy.value = false
  }
}

async function removePosition() {
  if (!position.value || !confirm(t('common.confirm_delete', { name: position.value.display_name }))) return
  try {
    await portfolioApi.deletePosition(id.value)
    router.push('/positions')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <RouterLink to="/positions" class="text-sm text-neutral-500 hover:text-primary-700">{{ t('common.back') }}</RouterLink>

    <div v-if="loading && !position" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="notFound" :title="t('positions.not_found')" :cta="t('common.back')" to="/positions" />

    <template v-else-if="position">
      <div class="flex flex-wrap items-start justify-between gap-3 mt-2 mb-4">
        <div class="flex items-center gap-3">
          <EntityIcon :src="position.valuation_mode === 'market' ? position.instrument_icon_url : (position.instrument_icon_url ?? position.institution_icon_url)" :name="position.display_name" :size="44" />
          <div>
            <h1 class="text-2xl font-semibold" :class="{ 'font-mono': position.instrument_symbol }">
              {{ position.display_name }}
              <Badge v-if="position.archived_at" class="ml-1 align-middle font-sans">{{ t('common.archived') }}</Badge>
            </h1>
            <p class="flex flex-wrap items-center gap-1 text-sm text-neutral-500 mt-0.5">
              <template v-if="position.instrument_name">{{ position.instrument_name }} · </template>
              <EntityIcon :src="position.institution_icon_url" :name="position.institution_name" :size="16" />
              {{ position.institution_name }} / {{ position.account_name }} · {{ position.portfolio_name }}
            </p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <div class="inline-flex gap-1">
            <IconButton v-if="canWrite" icon="edit" :label="t('common.edit')" @click="openEdit" />
            <IconButton v-if="position.valuation_mode === 'market' && canWrite" icon="transfer" :label="t('tx.transfer')" @click="openTransfer" />
            <IconButton v-if="canManage" icon="trash" variant="danger" :label="t('positions.delete')" @click="removePosition" />
          </div>
          <button v-if="position.is_cash && canWrite" @click="cashOpen = true" class="cursor-pointer h-9 px-3 border border-primary-600 text-primary-700 hover:bg-primary-50 text-sm font-medium rounded-md">{{ t('cash.set') }}</button>
          <button v-if="canWrite" @click="txModal = { tx: null }" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md">{{ t('tx.new') }}</button>
        </div>
      </div>

      <PositionValuationPanel ref="valuationPanel" :position="position" :transaction-count="total" />
      <HistoryChart ref="historyChart" class="mb-4" scope="position" :id="position.id" />

      <h2 class="text-lg font-semibold mb-2">{{ t('tx.title') }}</h2>
      <TransactionTable :rows="transactions" :total="total" :loading="txLoading" :empty-text="t('tx.empty')"
        @change="loadTx" @edit="tx => (txModal = { tx })" @delete="removeTx" />

    </template>

    <TransactionFormModal v-if="txModal && position" :position="position" :transaction="txModal.tx" @close="txModal = null" @saved="onSaved" />
    <TransferFormModal v-if="transferOpen && position" :from="position" :positions="allPositions" @close="transferOpen = false" @saved="onSaved" />

    <Modal v-if="editOpen && position" :title="t('positions.edit_title')" width-class="max-w-md" @close="editOpen = false">
      <form id="position-edit-form" class="space-y-3" @submit.prevent="saveEdit">
        <div>
          <label :for="fid('portfolio_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.portfolio') }}</label>
          <select :id="fid('portfolio_id')" v-model="editForm.portfolio_id" :class="inputClass">
            <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label :for="fid('account_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.account') }}</label>
          <select :id="fid('account_id')" v-model="editForm.account_id" :class="inputClass">
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.institution_name }} / {{ a.name }}</option>
          </select>
        </div>
        <div>
          <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">
            {{ t('common.name') }}
            <span v-if="position.valuation_mode === 'market'" class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span>
          </label>
          <input :id="fid('name')" v-model="editForm.name" maxlength="120" :required="position.valuation_mode === 'manual'" :class="inputClass" />
        </div>
        <div v-if="position.valuation_mode === 'manual'">
          <label :for="fid('interest_rate_pa')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.interest_rate') }}</label>
          <input :id="fid('interest_rate_pa')" v-model="editForm.interest_rate_pa" inputmode="decimal" :class="inputClass" />
        </div>
        <div v-if="position.valuation_mode === 'manual'" class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-neutral-200 pt-3">
          <div>
            <label :for="fid('bank_account')" class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('bank_notices.position_account') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span>
            </label>
            <input :id="fid('bank_account')" v-model="editForm.bank_account" placeholder="123456789/2010" autocomplete="off" :class="inputClass" />
          </div>
          <div v-if="editForm.bank_account.trim()">
            <label :for="fid('bank_notices_from')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.notices_from') }}</label>
            <input :id="fid('bank_notices_from')" v-model="editForm.bank_notices_from" type="date" :class="inputClass" />
          </div>
          <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('bank_notices.position_hint') }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="editForm.archived" type="checkbox" class="rounded border-field text-primary-700" />
          {{ t('common.archived') }}
        </label>
        <p v-if="editError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ editError }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="editOpen = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="position-edit-form" :disabled="editBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
    <CashBalanceModal v-if="cashOpen && position" :position="position" @close="cashOpen = false" @saved="onSaved" />
  </div>
</template>
