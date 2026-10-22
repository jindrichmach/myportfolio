<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import IconButton from '@/components/ui/IconButton.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { alertsApi, type AlertSettings, type PriceAlert } from '@/api/alerts'
import { catalogApi, type Instrument } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'
import { formatDateTime, formatDec } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const settings = ref<AlertSettings | null>(null)
const form = reactive({ enabled: false, recipients: '', move_portfolio_pct: '', move_position_pct: '', holding_days: '' as string | number, data_issues: true, allocation: true })
const busy = ref<'save' | 'send' | null>(null)
const error = ref('')

function fill(s: AlertSettings) {
  settings.value = s
  form.enabled = s.enabled
  form.recipients = s.recipients.join(', ')
  form.move_portfolio_pct = s.move_portfolio_pct ?? ''
  form.move_position_pct = s.move_position_pct ?? ''
  form.holding_days = s.holding_days ?? ''
  form.data_issues = s.data_issues
  form.allocation = s.allocation
}

onMounted(async () => {
  try {
    fill(await alertsApi.get())
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  }
})

async function save() {
  busy.value = 'save'
  error.value = ''
  try {
    fill(await alertsApi.update({
      enabled: form.enabled,
      recipients: form.recipients.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean),
      move_portfolio_pct: String(form.move_portfolio_pct).trim() || null,
      move_position_pct: String(form.move_position_pct).trim() || null,
      holding_days: form.holding_days === '' ? null : Number(form.holding_days),
      data_issues: form.data_issues,
      allocation: form.allocation,
    }))
    toast.success(t('alerts.saved'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}

async function sendNow() {
  busy.value = 'send'
  error.value = ''
  try {
    const r = await alertsApi.send()
    if (r.sent) toast.success(t('alerts.sent', { to: r.recipients.join(', ') }))
    else toast.success(t('alerts.nothing'))
    fill(await alertsApi.get())
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}

// ── Cenové hranice ──────────────────────────────────────────────────────
const priceForm = reactive({ instrument_id: null as number | null, direction: 'above' as 'above' | 'below', price: '', note: '' })
const instrumentOptions = ref<Instrument[]>([])
const searching = ref(false)
const priceError = ref('')
const priceBusy = ref(false)

async function searchInstruments(q: string) {
  if (!q.trim()) return
  searching.value = true
  try {
    instrumentOptions.value = await catalogApi.instruments({ q })
  } finally {
    searching.value = false
  }
}

async function reloadPrices() {
  if (settings.value) settings.value.price_alerts = (await alertsApi.get()).price_alerts
}

async function addPrice() {
  if (priceForm.instrument_id === null) return
  priceBusy.value = true
  priceError.value = ''
  try {
    await alertsApi.createPrice({ instrument_id: priceForm.instrument_id, direction: priceForm.direction, price: priceForm.price, note: priceForm.note || null })
    Object.assign(priceForm, { instrument_id: null, price: '', note: '' })
    await reloadPrices()
  } catch (e) {
    priceError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    priceBusy.value = false
  }
}

async function rearm(a: PriceAlert) {
  try {
    await alertsApi.updatePrice(a.id, { instrument_id: a.instrument_id, direction: a.direction, price: a.price, currency: a.currency, note: a.note })
    await reloadPrices()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

async function removePrice(a: PriceAlert) {
  if (!confirm(t('alerts.price_delete_confirm', { symbol: a.symbol }))) return
  try {
    await alertsApi.removePrice(a.id)
    await reloadPrices()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const inputClass = 'w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none'
</script>

<template>
  <form v-if="settings" @submit.prevent="save" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4 mt-6">
    <div>
      <h2 class="text-lg font-semibold">{{ t('alerts.title') }}</h2>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('alerts.subtitle') }}</p>
    </div>
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
      {{ t('alerts.enabled') }}
    </label>

    <div class="grid gap-4 sm:grid-cols-3">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('alerts.move_portfolio') }}</label>
        <input v-model="form.move_portfolio_pct" inputmode="decimal" :placeholder="t('alerts.off')" :class="inputClass" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('alerts.move_position') }}</label>
        <input v-model="form.move_position_pct" inputmode="decimal" :placeholder="t('alerts.off')" :class="inputClass" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('alerts.holding_days') }}</label>
        <input v-model="form.holding_days" type="number" min="1" max="365" :placeholder="t('alerts.off')" :class="inputClass" />
      </div>
    </div>
    <p class="text-xs text-neutral-500 -mt-2">{{ t('alerts.thresholds_hint') }}</p>
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.data_issues" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
      {{ t('alerts.data_issues') }}
    </label>
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.allocation" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
      {{ t('alerts.allocation') }}
    </label>
    <div>
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('weekly_report.recipients') }}</label>
      <input v-model="form.recipients" :placeholder="settings.email" autocomplete="off" :class="inputClass" />
      <p class="text-xs text-neutral-500 mt-1">{{ t('weekly_report.recipients_hint', { email: settings.email }) }}</p>
    </div>

    <!-- Cenové hranice -->
    <div class="border-t border-neutral-200 pt-4">
      <h3 class="text-sm font-semibold mb-2">{{ t('alerts.price_title') }}</h3>
      <ul v-if="settings.price_alerts.length" class="divide-y divide-neutral-100 mb-3">
        <li v-for="a in settings.price_alerts" :key="a.id" class="py-2 flex flex-wrap items-center justify-between gap-2">
          <div class="min-w-0 text-sm">
            <span class="font-medium">{{ a.symbol }}</span>
            {{ t(`alerts.${a.direction}`) }} {{ formatDec(a.price, 8, a.currency) }}
            <span v-if="a.note" class="text-neutral-500">· {{ a.note }}</span>
            <span class="block text-xs" :class="a.triggered_at ? 'text-success-600' : 'text-neutral-500'">
              {{ a.triggered_at ? t('alerts.triggered', { at: formatDateTime(a.triggered_at), price: formatDec(a.triggered_price, 8, a.currency) }) : t('alerts.waiting') }}
            </span>
          </div>
          <div class="inline-flex gap-1">
            <IconButton v-if="a.triggered_at" icon="refresh" :label="t('alerts.rearm')" @click="rearm(a)" />
            <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="removePrice(a)" />
          </div>
        </li>
      </ul>
      <div class="grid gap-2 sm:grid-cols-[minmax(0,2fr)_auto_minmax(0,1fr)_minmax(0,1fr)_auto] items-start">
        <SearchableSelect v-model="priceForm.instrument_id" remote :loading="searching"
          :options="instrumentOptions.map(i => ({ value: i.id, label: `${i.symbol} (${i.quote_currency})`, secondary: i.name }))"
          :placeholder="t('instruments.search_placeholder')" @search="searchInstruments" />
        <select v-model="priceForm.direction" :class="inputClass + ' sm:w-auto'">
          <option value="above">{{ t('alerts.above') }}</option>
          <option value="below">{{ t('alerts.below') }}</option>
        </select>
        <input v-model="priceForm.price" inputmode="decimal" :placeholder="t('alerts.price_placeholder')" :class="inputClass" />
        <input v-model="priceForm.note" maxlength="255" :placeholder="t('alerts.note')" :class="inputClass" />
        <button type="button" :disabled="priceBusy || priceForm.instrument_id === null || !priceForm.price" @click="addPrice"
          class="cursor-pointer px-3 h-10 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">{{ t('alerts.add') }}</button>
      </div>
      <p class="text-xs text-neutral-500 mt-1">{{ t('alerts.price_hint') }}</p>
      <p v-if="priceError" class="text-sm text-danger-500 mt-1">{{ priceError }}</p>
    </div>

    <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
      <p class="text-xs text-neutral-500">
        {{ settings.sent_at ? t('weekly_report.last_sent', { at: formatDateTime(settings.sent_at) }) : t('weekly_report.never_sent') }}
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" :disabled="busy !== null" @click="sendNow"
                class="cursor-pointer px-3 h-10 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ busy === 'send' ? '…' : t('alerts.check_now') }}
        </button>
        <button type="submit" :disabled="busy !== null"
                class="cursor-pointer px-4 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ busy === 'save' ? '…' : t('common.save') }}
        </button>
      </div>
    </div>
  </form>
</template>
