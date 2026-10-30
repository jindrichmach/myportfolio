<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, Filler, LinearScale, LineElement, PointElement, Tooltip, type ChartOptions } from 'chart.js'
import { crosshair } from '@/utils/chartSetup'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Icon from '@/components/ui/Icon.vue'
import Badge from '@/components/ui/Badge.vue'
import KpiTile from '@/components/ui/KpiTile.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import SegmentControl from '@/components/ui/SegmentControl.vue'
import {
  netWorthApi, NET_WORTH_KINDS,
  type NetWorthEntry, type NetWorthItem, type NetWorthKind, type NetWorthOverview, type NetWorthPoint,
} from '@/api/netWorth'
import { HISTORY_RANGES, type HistoryRange } from '@/api/history'
import { apiErrorMessage } from '@/api/errors'
import { useWindowSize } from '@vueuse/core'
import { formatDate, formatDec, formatMoney, formatMoneyCompact, formatSigned } from '@/composables/useFormat'
import { useChartColors } from '@/composables/useTheme'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'
import ManualLink from '@/components/ui/ManualLink.vue'

ChartJS.register(CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip)

const { t } = useI18n()
const fid = useFieldId()
const { canManage } = useOwnerContext()
const toast = useToast()
const colors = useChartColors()

const data = ref<NetWorthOverview | null>(null)
const loading = ref(true)
const range = ref<HistoryRange>('1Y')
const points = ref<NetWorthPoint[]>([])
const showArchived = ref(false)

const cur = computed(() => data.value?.currency ?? 'CZK')
const money = (v: string | null | undefined, currency = cur.value) => (v === null || v === undefined ? '—' : formatMoney(Number(v), currency, 0))
const today = () => new Date().toISOString().slice(0, 10)

async function load() {
  loading.value = true
  try {
    data.value = await netWorthApi.overview(showArchived.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
  loadHistory()
}
async function loadHistory() {
  try {
    points.value = (await netWorthApi.history(range.value)).points
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
onMounted(load)
watch(range, loadHistory)

const groups = computed(() => NET_WORTH_KINDS
  .map(kind => ({ kind, items: data.value?.items.filter(i => i.kind === kind) ?? [] }))
  .filter(g => g.items.length))

const tiles = computed(() => {
  const d = data.value
  if (!d) return []
  return [
    { key: 'inv', label: t('net_worth.investments'), text: money(d.investments), cls: '' },
    { key: 'assets', label: t('net_worth.assets'), text: money(d.assets), cls: '' },
    { key: 'liab', label: t('net_worth.liabilities'), text: formatSigned(String(-Number(d.liabilities)), cur.value, 0), cls: Number(d.liabilities) > 0 ? 'text-danger-500' : '' },
  ]
})
const rangeOptions = computed(() => HISTORY_RANGES.map(r => ({ value: r, label: t(`history.ranges.${r}`) })))
const { width } = useWindowSize()
const narrow = computed(() => width.value < 640)

const chartData = computed(() => ({
  labels: points.value.map(p => p.date),
  datasets: [
    {
      label: t('net_worth.net_worth'),
      data: points.value.map(p => (p.net_worth === null ? null : Number(p.net_worth))),
      borderColor: colors.value.primary,
      backgroundColor: colors.value.primary + '1f',
      fill: true, pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: colors.value.primary, borderWidth: 2, tension: 0.15, spanGaps: true,
    },
    {
      label: t('net_worth.investments'),
      data: points.value.map(p => (p.investments === null ? null : Number(p.investments))),
      borderColor: colors.value.tick, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 3, pointHoverBackgroundColor: colors.value.tick, borderWidth: 1.5, fill: false, spanGaps: true,
    },
    {
      label: t('net_worth.liabilities'),
      data: points.value.map(p => -Number(p.liabilities)),
      // závazky jako záporná čára v barvě ztráty
      borderColor: colors.value.loss, pointRadius: 0, pointHoverRadius: 3, pointHoverBackgroundColor: colors.value.loss, borderWidth: 1.5, fill: false, spanGaps: true,
    },
  ],
}))
const options = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: colors.value.tooltipBg,
      padding: 10,
      boxPadding: 4,
      usePointStyle: true,
      callbacks: {
        title: items => formatDate(items[0]?.label),
        label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y, cur.value, 0)}`,
      },
    },
  },
  scales: {
    x: { grid: { display: false }, ticks: { color: colors.value.tick, maxTicksLimit: narrow.value ? 3 : 6, maxRotation: 0, callback: (_v, i) => formatDate(chartData.value.labels[i]) } },
    y: { grid: { color: colors.value.grid }, border: { display: false },
      ticks: { color: colors.value.tick, maxTicksLimit: 6, callback: v => (narrow.value ? formatMoneyCompact(Number(v), cur.value) : formatMoney(Number(v), cur.value, 0)) } },
  },
}))

// ── Položka ─────────────────────────────────────────────────────────────
const showItem = ref(false)
const itemBusy = ref(false)
const itemError = ref('')
const editItem = ref<NetWorthItem | null>(null)
const itemForm = reactive({ kind: 'real_estate' as NetWorthKind, name: '', currency: 'CZK', note: '', archived: false, date: '', value: '', rate_pct: '', payment: '' })

function openCreate(kind: NetWorthKind = 'real_estate') {
  Object.assign(itemForm, { kind, name: '', currency: 'CZK', note: '', archived: false, date: today(), value: '', rate_pct: '', payment: '' })
  editItem.value = null
  itemError.value = ''
  showItem.value = true
}
function openEdit(i: NetWorthItem) {
  Object.assign(itemForm, { kind: i.kind, name: i.name, currency: i.currency, note: i.note ?? '', archived: i.archived_at !== null })
  editItem.value = i
  itemError.value = ''
  showItem.value = true
}
async function saveItem() {
  itemBusy.value = true
  itemError.value = ''
  const base = { name: itemForm.name.trim(), currency: itemForm.currency.trim().toUpperCase(), note: itemForm.note.trim() || null }
  try {
    if (editItem.value) {
      await netWorthApi.updateItem(editItem.value.id, { ...base, archived: itemForm.archived })
    } else {
      const liability = itemForm.kind === 'liability'
      await netWorthApi.createItem({
        ...base, kind: itemForm.kind,
        ...(liability || itemForm.value.trim() !== '' ? { date: itemForm.date, value: itemForm.value.replace(',', '.').replace(/\s/g, '') } : {}),
        ...(liability ? { rate_pct: itemForm.rate_pct.replace(',', '.'), payment: itemForm.payment.replace(',', '.').replace(/\s/g, '') } : {}),
      })
    }
    showItem.value = false
    await load()
  } catch (e) {
    itemError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    itemBusy.value = false
  }
}
// Splacený úvěr (nulový zůstatek) jde jedním klikem archivovat — zmizí ze seznamu, historie zůstane.
const isPaidOff = (i: NetWorthItem) => i.kind === 'liability' && i.archived_at === null && i.value !== null && Number(i.value) === 0
async function archivePaidOff(i: NetWorthItem) {
  try {
    await netWorthApi.updateItem(i.id, { name: i.name, currency: i.currency, note: i.note, archived: true })
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
watch(showArchived, load)

async function removeItem(i: NetWorthItem) {
  if (!confirm(t('common.confirm_delete', { name: i.name }))) return
  try {
    await netWorthApi.removeItem(i.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

// ── Záznamy (ocenění / zůstatky) ────────────────────────────────────────
const entriesFor = ref<NetWorthItem | null>(null)
const entries = ref<NetWorthEntry[]>([])
const schedule = ref<{ date: string; balance: string }[]>([])
const entryBusy = ref(false)
const entryError = ref('')
const entryForm = reactive({ date: '', value: '', rate_pct: '', payment: '', note: '' })

async function openEntries(i: NetWorthItem) {
  entriesFor.value = i
  Object.assign(entryForm, { date: today(), value: '', rate_pct: '', payment: '', note: '' })
  entryError.value = ''
  try {
    const r = await netWorthApi.entries(i.id)
    entries.value = r.entries
    schedule.value = r.schedule ?? []
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
async function saveEntry() {
  if (!entriesFor.value) return
  entryBusy.value = true
  entryError.value = ''
  try {
    await netWorthApi.saveEntry(entriesFor.value.id, {
      date: entryForm.date,
      value: entryForm.value.replace(',', '.').replace(/\s/g, ''),
      rate_pct: entryForm.rate_pct.replace(',', '.') || undefined,
      payment: entryForm.payment.replace(',', '.').replace(/\s/g, '') || undefined,
      note: entryForm.note.trim() || null,
    })
    await openEntries(entriesFor.value)
    await load()
  } catch (e) {
    entryError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    entryBusy.value = false
  }
}
async function removeEntry(e: NetWorthEntry) {
  if (!entriesFor.value || !confirm(t('net_worth.entry_delete_confirm', { date: formatDate(e.date) }))) return
  try {
    await netWorthApi.removeEntry(e.id)
    await openEntries(entriesFor.value)
    await load()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('common.error')))
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('net_worth.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('net_worth.subtitle') }}</p>
        <ManualLink class="mt-1" to="09_Alokace_a_ciste_jmeni#92-čisté-jmění" />
      </div>
      <button v-if="canManage" @click="openCreate()" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('net_worth.new') }}
      </button>
    </div>

    <div v-if="loading && !data" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:p-5 mb-4">
        <KpiTile bare hero :label="t('net_worth.net_worth')" :value="money(data.net_worth)" :value-class="Number(data.net_worth) < 0 ? 'text-danger-500' : ''" :hint="t('dashboard.net_worth_hint')" />
        <div class="mt-4 pt-4 border-t border-neutral-200 grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3">
          <KpiTile v-for="k in tiles" :key="k.key" bare :label="k.label" :value="k.text" :value-class="k.cls" />
        </div>
      </section>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-600 mb-3">
        <input v-model="showArchived" type="checkbox" class="rounded border-field text-primary-700" />
        {{ t('common.show_archived') }}
      </label>
      <p v-if="data.investments_incomplete" class="mb-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
        <Icon name="warning" class="w-4 h-4 align-[-3px] mr-1" />{{ t('net_worth.investments_incomplete', { n: data.investments_incomplete }) }}
      </p>

      <SectionCard v-if="points.length" :title="t('net_worth.history')" flush class="mb-4">
        <template #actions><SegmentControl v-model="range" :options="rangeOptions" :label="t('net_worth.history')" /></template>
        <div class="relative h-64 sm:h-72 px-2 pt-3"><Line :data="chartData" :options="options" :plugins="[crosshair]" :aria-label="t('net_worth.history')" /></div>
        <ul class="flex flex-wrap gap-x-4 gap-y-1 px-4 pt-1 pb-3 text-xs text-neutral-600">
          <li class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded" :style="{ background: colors.primary }"></span>{{ t('net_worth.net_worth') }}</li>
          <li class="inline-flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" :style="{ borderColor: colors.tick }"></span>{{ t('net_worth.investments') }}</li>
          <li class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded" :style="{ background: colors.loss }"></span>{{ t('net_worth.liabilities') }}</li>
        </ul>
      </SectionCard>

      <div v-if="!groups.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <EmptyState :title="t('net_worth.empty')" :message="t('net_worth.empty_hint')" />
      </div>
      <SectionCard v-for="g in groups" :key="g.kind" :title="t(`net_worth.kinds.${g.kind}`)" flush class="mb-4">
        <div class="divide-y divide-neutral-100">
          <div v-for="i in g.items" :key="i.id" class="px-4 py-3 flex flex-wrap items-center justify-between gap-3" :class="{ 'opacity-60': i.archived_at }">
            <div class="min-w-0">
              <div class="font-medium">{{ i.name }}<Badge v-if="i.archived_at" class="ml-1.5">{{ t('common.archived') }}</Badge></div>
              <p class="text-xs text-neutral-500">
                <template v-if="i.last_date">{{ t(i.kind === 'liability' ? 'net_worth.balance_from' : 'net_worth.valued_on', { date: formatDate(i.last_date) }) }}</template>
                <template v-else>{{ t('net_worth.no_value') }}</template>
                <template v-if="i.kind === 'liability' && i.rate_pct !== null"> · {{ t('net_worth.loan_terms', { rate: formatDec(i.rate_pct ?? '0', 4), payment: money(i.payment, i.currency) }) }}</template>
                <template v-if="i.kind === 'liability' && i.payoff"> · {{ t('net_worth.payoff', { date: formatDate(i.payoff.date), n: i.payoff.payments_left }) }}</template>
                <template v-else-if="i.kind === 'liability' && i.last_date"> · <span class="text-warning-600">{{ t('net_worth.not_amortizing') }}</span></template>
              </p>
              <p v-if="i.note" class="text-xs text-neutral-500">{{ i.note }}</p>
            </div>
            <div class="flex items-center gap-3">
              <div class="text-right tabular-nums">
                <div class="font-semibold" :class="i.kind === 'liability' && Number(i.value_display) > 0 ? 'text-danger-500' : ''">
                  {{ i.kind === 'liability' && i.value_display ? formatSigned(String(-Number(i.value_display)), cur, 0) : money(i.value_display) }}
                </div>
                <div v-if="i.currency !== cur && i.value" class="text-xs text-neutral-500">{{ money(i.value, i.currency) }}</div>
              </div>
              <div class="inline-flex flex-wrap items-center gap-1">
                <button v-if="canManage && isPaidOff(i)" type="button" @click="archivePaidOff(i)"
                  class="cursor-pointer h-8 px-3 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('net_worth.paid_off_archive') }}</button>
                <IconButton icon="price" :label="t(i.kind === 'liability' ? 'net_worth.balances' : 'net_worth.valuations')" @click="openEntries(i)" />
                <IconButton v-if="canManage" icon="edit" :label="t('common.edit')" @click="openEdit(i)" />
                <IconButton v-if="canManage" icon="trash" variant="danger" :label="t('common.delete')" @click="removeItem(i)" />
              </div>
            </div>
          </div>
        </div>
      </SectionCard>
      <p class="text-xs text-neutral-500">{{ t('net_worth.note') }}</p>
    </template>

    <Modal v-if="showItem" :title="editItem ? t('net_worth.edit_title') : t('net_worth.new')" width-class="max-w-lg" @close="showItem = false">
      <form id="nw-item-form" class="space-y-3" @submit.prevent="saveItem">
        <div v-if="!editItem">
          <label :for="fid('kind')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('net_worth.kind') }}</label>
          <select :id="fid('kind')" v-model="itemForm.kind" :class="inputClass">
            <option v-for="k in NET_WORTH_KINDS" :key="k" :value="k">{{ t(`net_worth.kinds.${k}`) }}</option>
          </select>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
            <input :id="fid('name')" v-model="itemForm.name" required maxlength="120" :placeholder="t(`net_worth.placeholders.${itemForm.kind}`)" :class="inputClass" />
          </div>
          <div>
            <label :for="fid('currency')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
            <input :id="fid('currency')" v-model="itemForm.currency" maxlength="3" required :class="inputClass" />
          </div>
        </div>
        <template v-if="!editItem">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label :for="fid('date')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t(itemForm.kind === 'liability' ? 'net_worth.start_date' : 'net_worth.value_date') }}</label>
              <input :id="fid('date')" v-model="itemForm.date" type="date" :required="itemForm.kind === 'liability'" :class="inputClass" />
            </div>
            <div>
              <label :for="fid('value')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t(itemForm.kind === 'liability' ? 'net_worth.principal' : 'net_worth.value') }}</label>
              <input :id="fid('value')" v-model="itemForm.value" inputmode="decimal" :required="itemForm.kind === 'liability'" :class="inputClass" />
            </div>
          </div>
          <div v-if="itemForm.kind === 'liability'" class="grid grid-cols-2 gap-3">
            <div>
              <label :for="fid('rate_pct')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('net_worth.rate') }}</label>
              <input :id="fid('rate_pct')" v-model="itemForm.rate_pct" inputmode="decimal" required :class="inputClass" />
            </div>
            <div>
              <label :for="fid('payment')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('net_worth.payment') }}</label>
              <input :id="fid('payment')" v-model="itemForm.payment" inputmode="decimal" required :class="inputClass" />
            </div>
          </div>
          <p class="text-xs text-neutral-500">{{ t(itemForm.kind === 'liability' ? 'net_worth.loan_hint' : 'net_worth.value_hint') }}</p>
        </template>
        <div>
          <label :for="fid('note')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.note') }}</label>
          <input :id="fid('note')" v-model="itemForm.note" maxlength="500" :class="inputClass" />
        </div>
        <label v-if="editItem" class="flex items-center gap-2 text-sm">
          <input v-model="itemForm.archived" type="checkbox" class="rounded border-field text-primary-700" />
          {{ t('net_worth.archived') }}
        </label>
        <p v-if="itemError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ itemError }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showItem = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="nw-item-form" :disabled="itemBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>

    <Modal v-if="entriesFor" :title="`${entriesFor.name} — ${t(entriesFor.kind === 'liability' ? 'net_worth.balances' : 'net_worth.valuations')}`" width-class="max-w-lg" @close="entriesFor = null">
      <form v-if="canManage" id="nw-entry-form" class="space-y-3" @submit.prevent="saveEntry">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label :for="fid('date2')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('net_worth.date') }}</label>
            <input :id="fid('date2')" v-model="entryForm.date" type="date" required :class="inputClass" />
          </div>
          <div>
            <label :for="fid('value2')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t(entriesFor.kind === 'liability' ? 'net_worth.balance' : 'net_worth.value') }} ({{ entriesFor.currency }})</label>
            <input :id="fid('value2')" v-model="entryForm.value" inputmode="decimal" required :class="inputClass" />
          </div>
        </div>
        <div v-if="entriesFor.kind === 'liability'" class="grid grid-cols-2 gap-3">
          <div>
            <label :for="fid('rate_pct2')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('net_worth.rate') }}</label>
            <input :id="fid('rate_pct2')" v-model="entryForm.rate_pct" inputmode="decimal" :placeholder="t('net_worth.unchanged')" :class="inputClass" />
          </div>
          <div>
            <label :for="fid('payment2')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('net_worth.payment') }}</label>
            <input :id="fid('payment2')" v-model="entryForm.payment" inputmode="decimal" :placeholder="t('net_worth.unchanged')" :class="inputClass" />
          </div>
        </div>
        <p class="text-xs text-neutral-500">{{ t(entriesFor.kind === 'liability' ? 'net_worth.correction_hint' : 'net_worth.valuation_hint') }}</p>
        <p v-if="entryError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ entryError }}</p>
        <div class="flex justify-end">
          <button type="submit" :disabled="entryBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('net_worth.add_entry') }}</button>
        </div>
      </form>
      <table v-if="entries.length" class="w-full text-sm mt-4">
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="e in entries" :key="e.id">
            <td class="py-1.5 tabular-nums">{{ formatDate(e.date) }}</td>
            <td class="py-1.5 text-right tabular-nums">{{ money(e.value, entriesFor.currency) }}</td>
            <td class="py-1.5 text-right text-xs text-neutral-500">
              <template v-if="e.rate_pct !== null">{{ formatDec(e.rate_pct, 4) }} %</template>
              <template v-if="e.payment !== null"> · {{ money(e.payment, entriesFor.currency) }}</template>
            </td>
            <td class="py-1.5 text-right w-10"><IconButton v-if="canManage" icon="trash" variant="danger" :label="t('common.delete')" @click="removeEntry(e)" /></td>
          </tr>
        </tbody>
      </table>
      <div v-if="schedule.length" class="mt-4">
        <h3 class="text-sm font-semibold mb-1">{{ t('net_worth.schedule') }}</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 text-xs tabular-nums">
          <div v-for="s in schedule" :key="s.date" class="flex justify-between gap-2 py-0.5">
            <span class="text-neutral-500">{{ formatDate(s.date) }}</span><span>{{ money(s.balance, entriesFor.currency) }}</span>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
