<script setup lang="ts">
/**
 * Převod množství z tržní pozice na jinou pozici téhož instrumentu (např. burza → peněženka).
 * Backend založí pár transfer_out + transfer_in; loty si ponesou původní datum nabytí.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { apiErrorMessage } from '@/api/errors'
import { portfolioApi, type Position } from '@/api/portfolio'

const props = defineProps<{ from: Position; positions: Position[] }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()
const { t } = useI18n()

const targets = computed(() => props.positions.filter(p =>
  p.id !== props.from.id && p.valuation_mode === 'market' && p.instrument_id === props.from.instrument_id))

const today = new Date()
const pad = (n: number) => String(n).padStart(2, '0')
const form = reactive({
  to_position_id: targets.value[0]?.id ?? (null as number | null),
  quantity: '',
  executed_at: `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}T${pad(today.getHours())}:${pad(today.getMinutes())}`,
  fee: '',
  // Síťový poplatek bývá v převáděné měně — sníží množství a jeho nákupní cena přejde na převedené kusy.
  fee_currency: props.from.instrument_symbol ?? props.from.currency,
  note: '',
})
const feeCurrencies = computed(() =>
  [...new Set([props.from.instrument_symbol, props.from.quote_currency ?? props.from.currency].filter((c): c is string => !!c))])
const busy = ref(false)
const error = ref('')

async function save() {
  if (busy.value || form.to_position_id === null) return
  busy.value = true
  error.value = ''
  try {
    await portfolioApi.transfer({
      from_position_id: props.from.id,
      to_position_id: form.to_position_id,
      quantity: form.quantity.trim(),
      executed_at: form.executed_at,
      fee: form.fee.trim() || null,
      fee_currency: form.fee.trim() ? form.fee_currency : null,
      note: form.note.trim() || null,
    })
    emit('saved')
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none'
</script>

<template>
  <Modal :title="t('tx.transfer_title', { name: from.display_name })" width-class="max-w-lg" @close="emit('close')">
    <p v-if="!targets.length" class="text-sm text-neutral-600">{{ t('tx.transfer_no_target') }}</p>
    <form v-else id="transfer-form" class="space-y-3" @submit.prevent="save">
      <p class="text-xs text-neutral-500">{{ t('tx.transfer_hint') }}</p>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.transfer_to') }}</label>
        <select v-model="form.to_position_id" required :class="inputClass">
          <option v-for="p in targets" :key="p.id" :value="p.id">
            {{ p.institution_name }} / {{ p.account_name }} · {{ p.portfolio_name }}
          </option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.quantity') }}</label>
          <input v-model="form.quantity" inputmode="decimal" required autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.executed_at') }}</label>
          <input v-model="form.executed_at" type="datetime-local" required :class="inputClass" />
        </div>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ t('tx.fee') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span>
          </label>
          <input v-model="form.fee" inputmode="decimal" autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.fee_currency') }}</label>
          <select v-model="form.fee_currency" :class="inputClass">
            <option v-for="c in feeCurrencies" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>
      <p class="text-xs text-neutral-500 -mt-1">{{ t('tx.fee_instrument_hint') }}</p>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.note') }}</label>
        <input v-model="form.note" maxlength="500" :class="inputClass" />
      </div>
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">
        {{ t('common.cancel') }}
      </button>
      <button v-if="targets.length" type="submit" form="transfer-form" :disabled="busy"
        class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
        {{ busy ? t('common.saving') : t('tx.transfer_submit') }}
      </button>
    </template>
  </Modal>
</template>
