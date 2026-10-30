<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { marketApi } from '@/api/market'
import { apiErrorMessage } from '@/api/errors'
import { useFieldId } from '@/composables/useFieldId'

const props = defineProps<{ instrumentId: number; symbol: string; currency: string }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()
const { t } = useI18n()
const fid = useFieldId()

const form = reactive({ date: new Date().toISOString().slice(0, 10), close: '', currency: props.currency })
const busy = ref(false)
const error = ref('')

async function save() {
  busy.value = true
  error.value = ''
  try {
    await marketApi.setManualPrice({ instrument_id: props.instrumentId, date: form.date, close: form.close.trim(), currency: form.currency.trim() })
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
  <Modal :title="t('prices.manual_title', { symbol })" width-class="max-w-sm" @close="emit('close')">
    <form id="manual-price-form" class="space-y-3" @submit.prevent="save">
      <p class="text-xs text-neutral-500">{{ t('prices.manual_hint') }}</p>
      <div>
        <label :for="fid('date')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tx.executed_at') }}</label>
        <input :id="fid('date')" v-model="form.date" type="date" required :class="inputClass" />
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label :for="fid('close')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('prices.close') }}</label>
          <input :id="fid('close')" v-model="form.close" inputmode="decimal" required autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label :for="fid('currency')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
          <input :id="fid('currency')" v-model="form.currency" maxlength="3" class="uppercase font-mono" :class="inputClass" />
        </div>
      </div>
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    </form>
    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="submit" form="manual-price-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
    </template>
  </Modal>
</template>
