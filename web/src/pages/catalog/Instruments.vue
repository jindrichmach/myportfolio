<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconField from '@/components/catalog/IconField.vue'
import InstrumentFields from '@/components/catalog/InstrumentFields.vue'
import PriceBadge from '@/components/portfolio/PriceBadge.vue'
import ManualPriceModal from '@/components/portfolio/ManualPriceModal.vue'
import IconButton from '@/components/ui/IconButton.vue'
import DataGrid from '@/components/ui/DataGrid.vue'
import type { DataColumn } from '@/components/ui/DataTable.vue'
import type { GridFilter, GridFilterValues } from '@/components/ui/grid'
import { catalogApi, ASSET_CLASSES, type Instrument } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { emptyInstrumentForm, instrumentPayload } from '@/utils/instrumentForm'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const rows = ref<Instrument[]>([])
const loading = ref(true)

const showForm = ref(false)
// Upravovaná položka (ikona se mění hned, i autorovi, který není admin — ostatní pole smí jen admin)
const editRow = ref<Instrument | null>(null)
const busy = ref(false)
const error = ref('')
const form = reactive(emptyInstrumentForm())

async function load() {
  loading.value = true
  try {
    rows.value = await catalogApi.instruments({ all: auth.isAdmin })
  } finally {
    loading.value = false
  }
}
onMounted(load)

function openCreate() {
  editRow.value = null
  Object.assign(form, emptyInstrumentForm())
  error.value = ''
  showForm.value = true
}
function openEdit(row: Instrument) {
  editRow.value = row
  Object.assign(form, {
    ...row,
    isin: row.isin ?? '',
    refs: { coingecko: row.source_refs.coingecko ?? '', xtb: row.source_refs.xtb ?? '', yahoo: row.source_refs.yahoo ?? '' },
  })
  error.value = ''
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  const payload = instrumentPayload(form)
  try {
    if (form.id === null) await catalogApi.createInstrument(payload)
    else await catalogApi.updateInstrument(form.id, payload)
    showForm.value = false
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove(row: Instrument) {
  if (!confirm(t('common.confirm_delete', { name: row.symbol }))) return
  try {
    await catalogApi.deleteInstrument(row.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const priceFor = ref<Instrument | null>(null)
function canSetPrice(row: Instrument): boolean {
  return auth.isAdmin || row.price_source === 'manual'
}

const columns = computed<DataColumn[]>(() => [
  { key: 'symbol', label: t('instruments.symbol'), primary: true },
  { key: 'asset_class', label: t('instruments.asset_class') },
  { key: 'currency', label: t('common.currency'), mono: true },
  { key: 'isin', label: 'ISIN', hideBelow: 'xl' },
  { key: 'source', label: t('instruments.price_source'), hideBelow: 'lg' },
  { key: 'price', label: t('prices.last'), align: 'right' },
])
const instrumentKey = (i: Instrument) => i.id

const filters = computed<GridFilter[]>(() => [
  { key: 'q', type: 'text', label: t('grid.search'), placeholder: t('instruments.search_placeholder') },
  { key: 'asset_class', type: 'select', label: t('instruments.asset_class'), allLabel: t('instruments.all_classes'), column: 'asset_class',
    options: ASSET_CLASSES.map(c => ({ value: c, label: t(`instruments.classes.${c}`) })) },
])
/** Hledání v symbolu, názvu a ISINu. */
function matchesInstrument(i: Instrument, v: GridFilterValues): boolean {
  const q = String(v.q ?? '').trim().toLowerCase()
  return !q || [i.symbol, i.name, i.isin ?? ''].some(x => x.toLowerCase().includes(q))
}
const inactiveClass = (i: Instrument) => (i.is_active ? '' : 'opacity-50')

</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('instruments.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('instruments.subtitle') }}</p>
      </div>
      <button @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('instruments.new') }}
      </button>
    </div>

    <DataGrid :columns="columns" :rows="rows" :row-key="instrumentKey" :row-class="inactiveClass" :filters="filters"
      :filter-fn="matchesInstrument" :loading="loading" :empty-text="t('instruments.empty')" sticky-first>
        <template #cell-symbol="{ row: r }">
          <span class="flex items-center gap-2 min-w-0">
            <EntityIcon :src="r.icon_url" :name="r.symbol" :size="28" />
            <span class="min-w-0"><span class="font-mono font-medium">{{ r.symbol }}</span><span class="block text-xs text-neutral-500 truncate max-w-64">{{ r.name }}</span></span>
          </span>
        </template>
        <template #cell-asset_class="{ row: r }">{{ t(`instruments.classes.${r.asset_class}`) }}</template>
        <template #cell-currency="{ row: r }">{{ r.quote_currency }}</template>
        <template #cell-isin="{ row: r }"><span class="font-mono text-xs">{{ r.isin ?? '—' }}</span></template>
        <template #cell-source="{ row: r }">
          {{ t(`instruments.sources.${r.price_source}`) }}
          <span v-if="r.source_refs[r.price_source]" class="font-mono text-xs text-neutral-500">· {{ r.source_refs[r.price_source] }}</span>
        </template>
        <template #cell-price="{ row: r }"><PriceBadge :price="r.last_price" :mono="r.asset_class === 'crypto'" /></template>
        <template #actions="{ row: r }">
          <span class="inline-flex gap-1">
            <IconButton v-if="canSetPrice(r)" icon="price" :label="t('prices.set')" @click="priceFor = r" />
            <IconButton v-if="auth.isAdmin || r.can_edit_icon" icon="edit" :label="t('common.edit')" @click="openEdit(r)" />
            <IconButton v-if="auth.isAdmin" icon="trash" variant="danger" :label="t('common.delete')" @click="remove(r)" />
          </span>
        </template>
    </DataGrid>

    <ManualPriceModal v-if="priceFor" :instrument-id="priceFor.id" :symbol="priceFor.symbol" :currency="priceFor.quote_currency"
      @close="priceFor = null" @saved="priceFor = null; load()" />

    <Modal v-if="showForm" :title="form.id === null ? t('instruments.new') : t('instruments.edit_title')" width-class="max-w-lg" @close="showForm = false">
      <form id="instrument-form" class="space-y-3" @submit.prevent="save">
        <IconField v-if="editRow?.can_edit_icon" kind="instruments" :id="editRow.id" :name="editRow.symbol" :icon-url="editRow.icon_url"
          :hint="editRow.asset_class === 'crypto' ? editRow.symbol : editRow.asset_class === 'etf' ? editRow.name.split(' ')[0] : ''" @changed="url => { if (editRow) editRow.icon_url = url }" />
        <p v-else-if="form.id === null" class="text-xs text-neutral-500">{{ t('icons.after_save') }}</p>
        <fieldset :disabled="!auth.isAdmin && form.id !== null" class="space-y-3">
          <InstrumentFields :form="form" />
          <label v-if="form.id !== null" class="flex items-center gap-2 text-sm">
            <input v-model="form.is_active" type="checkbox" class="rounded border-field text-primary-700" />
            {{ t('common.active') }}
          </label>
        </fieldset>
        <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ auth.isAdmin || form.id === null ? t('common.cancel') : t('common.close') }}</button>
        <button v-if="auth.isAdmin || form.id === null" type="submit" form="instrument-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
