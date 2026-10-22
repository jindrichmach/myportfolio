<script setup lang="ts">
/**
 * Formulář transakce (založení i úprava). Pole se řídí typem transakce a režimem pozice.
 * Čísla se posílají jako text — parsuje a validuje backend (BigDecimal), tady nic nepočítáme.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { apiErrorMessage } from '@/api/errors'
import {
  portfolioApi, MARKET_TYPES, MANUAL_TYPES,
  type Position, type Transaction, type TransactionInput, type TransactionType,
} from '@/api/portfolio'

const props = defineProps<{
  /** Pevná pozice (detail pozice). */
  position?: Position | null
  /** Výběr pozice (globální seznam transakcí), když `position` chybí. */
  positions?: Position[]
  /** Upravovaná transakce; bez ní se zakládá nová. */
  transaction?: Transaction | null
}>()

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved', tx: Transaction): void }>()
const { t } = useI18n()

function nowLocal(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const tx = props.transaction ?? null
const form = reactive({
  position_id: props.position?.id ?? tx?.position_id ?? (null as number | null),
  type: (tx?.type ?? (props.position?.valuation_mode === 'manual' ? 'deposit' : 'buy')) as TransactionType,
  executed_at: tx ? tx.executed_at.replace(' ', 'T').slice(0, 16) : nowLocal(),
  quantity: tx?.quantity ?? '',
  amount: tx?.amount ?? '',
  unit_price: tx?.unit_price ?? '',
  price_currency: tx?.price_currency ?? '',
  fee: tx?.fee ?? '',
  fee_currency: tx?.fee_currency ?? '',
  split_ratio: tx?.split_ratio ?? '',
  note: tx?.note ?? '',
})
const busy = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const selectedPosition = computed<Position | null>(() =>
  props.position ?? props.positions?.find(p => p.id === form.position_id) ?? null)
const types = computed(() => selectedPosition.value?.valuation_mode === 'manual' ? MANUAL_TYPES : MARKET_TYPES)
const isTrade = computed(() => form.type === 'buy' || form.type === 'sell')
const hasQuantity = computed(() => isTrade.value || form.type === 'reward')
const isSplit = computed(() => form.type === 'split')

watch(types, list => {
  if (!list.includes(form.type)) form.type = list[0]
})
watch(selectedPosition, p => {
  if (p && !props.transaction) form.price_currency = p.currency
}, { immediate: true })

function nullable(v: string): string | null {
  return v.trim() === '' ? null : v.trim()
}

async function save() {
  if (busy.value || form.position_id === null) return
  busy.value = true
  error.value = ''
  fieldErrors.value = {}
  const payload: TransactionInput = {
    type: form.type,
    executed_at: form.executed_at,
    quantity: hasQuantity.value ? nullable(form.quantity) : null,
    amount: isSplit.value ? null : nullable(form.amount),
    unit_price: isTrade.value ? nullable(form.unit_price) : null,
    price_currency: isSplit.value ? null : nullable(form.price_currency),
    fee: isSplit.value ? null : nullable(form.fee),
    fee_currency: nullable(form.fee_currency),
    split_ratio: isSplit.value ? nullable(form.split_ratio) : null,
    note: nullable(form.note),
  }
  try {
    const saved = props.transaction
      ? await portfolioApi.updateTransaction(props.transaction.id, payload)
      : await portfolioApi.createTransaction({ ...payload, position_id: form.position_id })
    emit('saved', saved)
  } catch (e: any) {
    fieldErrors.value = e?.response?.data?.error?.fields ?? {}
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none'
</script>

<template>
  <Modal :title="transaction ? t('tx.edit_title') : t('tx.new')" width-class="max-w-lg" @close="emit('close')">
    <form id="tx-form" class="space-y-3" @submit.prevent="save">
      <div v-if="!position">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.position') }}</label>
        <select v-model="form.position_id" :disabled="!!transaction" required :class="inputClass">
          <option :value="null" disabled>—</option>
          <option v-for="p in positions" :key="p.id" :value="p.id">
            {{ p.display_name }} · {{ p.institution_name }} / {{ p.account_name }} · {{ p.portfolio_name }}
          </option>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.type') }}</label>
          <select v-model="form.type" :class="inputClass">
            <option v-for="tp in types" :key="tp" :value="tp">{{ t(`tx.types.${tp}`) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.executed_at') }}</label>
          <input v-model="form.executed_at" type="datetime-local" required :class="inputClass" />
        </div>
      </div>

      <div v-if="hasQuantity">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.quantity') }}</label>
        <input v-model="form.quantity" inputmode="decimal" autocomplete="off" required :class="inputClass" />
      </div>

      <div v-if="isSplit">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.split_ratio') }}</label>
        <input v-model="form.split_ratio" inputmode="decimal" autocomplete="off" required :class="inputClass" />
        <p class="text-xs text-neutral-500 mt-1">{{ t('tx.split_hint') }}</p>
      </div>

      <div v-if="!isSplit" class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ form.type === 'reward' ? t('tx.reward_value') : t('tx.amount') }}
          </label>
          <input v-model="form.amount" inputmode="decimal" autocomplete="off" :required="!isTrade && form.type !== 'reward'" :class="inputClass" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
          <input v-model="form.price_currency" maxlength="3" class="uppercase" :class="inputClass" />
        </div>
      </div>

      <div v-if="isTrade">
        <label class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('tx.unit_price') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span>
        </label>
        <input v-model="form.unit_price" inputmode="decimal" autocomplete="off" :class="inputClass" />
        <p class="text-xs text-neutral-500 mt-1">{{ t('tx.amount_hint') }}</p>
      </div>

      <div v-if="!isSplit" class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ t('tx.fee') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span>
          </label>
          <input v-model="form.fee" inputmode="decimal" autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.fee_currency') }}</label>
          <input v-model="form.fee_currency" maxlength="10" :placeholder="form.price_currency" class="uppercase" :class="inputClass" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.note') }}</label>
        <input v-model="form.note" maxlength="500" :class="inputClass" />
      </div>

      <ul v-if="Object.keys(fieldErrors).length" class="text-xs text-danger-500 space-y-0.5">
        <li v-for="(msgs, field) in fieldErrors" :key="field">{{ field }}: {{ msgs.join(', ') }}</li>
      </ul>
      <p v-else-if="error" class="text-sm text-danger-500">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">
        {{ t('common.cancel') }}
      </button>
      <button type="submit" form="tx-form" :disabled="busy"
        class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
        {{ busy ? t('common.saving') : t('common.save') }}
      </button>
    </template>
  </Modal>
</template>
