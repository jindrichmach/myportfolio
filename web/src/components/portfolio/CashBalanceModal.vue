<script setup lang="ts">
/** Nastavení zůstatku hotovosti — rozdíl proti dosavadnímu zůstatku se zapíše jako vklad / výběr. */
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { portfolioApi, type Position } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDec } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'

const props = defineProps<{ position: Position }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { t } = useI18n()
const fid = useFieldId()
const toast = useToast()

const today = new Date().toLocaleDateString('sv-SE')
const form = reactive({ date: today, balance: props.position.current_value?.value ?? '', note: '' })
const busy = ref(false)
const error = ref('')

async function save() {
  busy.value = true
  error.value = ''
  try {
    const r = await portfolioApi.setCashBalance(props.position.id, { date: form.date, balance: String(form.balance).trim(), note: form.note.trim() || null })
    toast.success(r.transaction_id === null ? t('cash.unchanged') : t('cash.saved', { delta: formatDec(r.delta, 2, props.position.currency) }))
    emit('saved')
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <Modal :title="t('cash.title', { name: position.display_name })" width-class="max-w-md" @close="emit('close')">
    <form id="cash-balance-form" class="space-y-3" @submit.prevent="save">
      <p class="text-sm text-neutral-500">{{ t('cash.hint') }}</p>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label :for="fid('date')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.date') }}</label>
          <input :id="fid('date')" v-model="form.date" type="date" required :max="today" :class="inputClass" />
        </div>
        <div>
          <label :for="fid('balance')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.balance', { currency: position.currency }) }}</label>
          <input :id="fid('balance')" v-model="form.balance" required inputmode="decimal" :class="inputClass + ' tabular-nums'" />
        </div>
      </div>
      <div>
        <label :for="fid('note')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.note') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
        <input :id="fid('note')" v-model="form.note" maxlength="200" :class="inputClass" />
      </div>
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    </form>
    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="submit" form="cash-balance-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
    </template>
  </Modal>
</template>
