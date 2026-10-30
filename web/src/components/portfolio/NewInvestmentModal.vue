<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import InstrumentFields from '@/components/catalog/InstrumentFields.vue'
import { catalogApi, type Instrument } from '@/api/catalog'
import { portfolioApi, type Account, type Portfolio, type Position } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { useFieldId } from '@/composables/useFieldId'
import { emptyInstrumentForm, instrumentPayload } from '@/utils/instrumentForm'

/**
 * Průvodce „Nová investice“: instrument (z katalogu podle tickeru, ISINu nebo názvu, nebo nový) → účet a portfolio.
 * Existující pozici (stejný instrument, účet a portfolio) použije, jinak ji založí; rodič pak otevře první nákup.
 */
const emit = defineEmits<{ close: []; ready: [position: Position] }>()
const { t } = useI18n()
const fid = useFieldId()

const accounts = ref<Account[]>([])
const portfolios = ref<Portfolio[]>([])
const loaded = ref(false)
onMounted(async () => {
  try {
    ;[accounts.value, portfolios.value] = await Promise.all([portfolioApi.accounts(), portfolioApi.portfolios()])
    if (accounts.value.length === 1) form.account_id = accounts.value[0].id
    if (portfolios.value.length) form.portfolio_id = portfolios.value[0].id
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loaded.value = true
  }
})

// Instrument z katalogu (hledání na serveru), nebo nový
const results = ref<Instrument[]>([])
const searching = ref(false)
const lastQuery = ref('')
async function search(q: string) {
  lastQuery.value = q
  if (q.trim().length < 2) { results.value = []; return }
  searching.value = true
  try { results.value = await catalogApi.instruments({ q }) } finally { searching.value = false }
}
const options = computed(() => results.value.map(i => ({
  value: i.id, label: `${i.symbol} · ${i.name}`, secondary: [i.isin, i.quote_currency, t(`instruments.classes.${i.asset_class}`)].filter(Boolean).join(' · '),
})))
const instrumentId = ref<number | null>(null)
const selected = computed(() => results.value.find(i => i.id === instrumentId.value) ?? null)
const creating = ref(false)
const instrument = reactive(emptyInstrumentForm())
function startCreate() {
  const q = lastQuery.value.trim().toUpperCase()
  Object.assign(instrument, emptyInstrumentForm(), /^[A-Z]{2}[A-Z0-9]{9}\d$/.test(q) ? { isin: q, asset_class: 'etf', decimals: 4, price_source: 'yahoo' } : { symbol: q })
  instrumentId.value = null
  creating.value = true
}

const form = reactive({ account_id: null as number | null, portfolio_id: null as number | null })
const busy = ref(false)
const error = ref('')
const canSubmit = computed(() => (creating.value || instrumentId.value !== null) && form.account_id !== null && form.portfolio_id !== null)

async function submit() {
  if (!canSubmit.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const id = creating.value ? (await catalogApi.createInstrument(instrumentPayload(instrument))).id : instrumentId.value!
    const existing = (await portfolioApi.positions({ portfolio_id: form.portfolio_id!, account_id: form.account_id! }))
      .find(p => p.instrument_id === id && p.valuation_mode === 'market')
    const position = existing ?? await portfolioApi.createPosition({
      valuation_mode: 'market', portfolio_id: form.portfolio_id!, account_id: form.account_id!, instrument_id: id,
    })
    emit('ready', position)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <Modal :title="t('invest.title')" width-class="max-w-lg" @close="emit('close')">
    <div v-if="!loaded" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else-if="!accounts.length || !portfolios.length" class="space-y-2 text-sm">
      <p>{{ t('invest.prereq') }}</p>
      <p class="flex flex-wrap gap-x-4">
        <RouterLink v-if="!accounts.length" to="/accounts" class="text-primary-700 hover:underline" @click="emit('close')">{{ t('accounts.new') }}</RouterLink>
        <RouterLink v-if="!portfolios.length" to="/portfolios" class="text-primary-700 hover:underline" @click="emit('close')">{{ t('portfolios.new') }}</RouterLink>
        <RouterLink to="/manual/00_Prvni_kroky" class="text-primary-700 hover:underline" @click="emit('close')">{{ t('invest.first_steps') }}</RouterLink>
      </p>
    </div>
    <form v-else id="invest-form" class="space-y-4" @submit.prevent="submit">
      <section class="space-y-2">
        <h3 class="text-sm font-semibold">1. {{ t('invest.step_instrument') }}</h3>
        <template v-if="!creating">
          <label :for="fid('instrument')" class="block text-sm font-medium text-neutral-700">{{ t('invest.search_label') }}</label>
          <SearchableSelect v-model="instrumentId" :input-id="fid('instrument')" :options="options" remote :loading="searching"
            :placeholder="t('invest.search_placeholder')" :no-results-label="t('invest.no_results')" :loading-label="t('common.loading')" @search="search" />
          <p v-if="selected" class="text-xs text-neutral-500">{{ selected.name }}<template v-if="selected.isin"> · {{ selected.isin }}</template> · {{ selected.quote_currency }}</p>
          <button type="button" class="cursor-pointer text-sm text-primary-700 hover:underline" @click="startCreate">{{ t('invest.not_in_catalog') }}</button>
        </template>
        <template v-else>
          <p class="text-xs text-neutral-500">{{ t('invest.create_hint') }}</p>
          <InstrumentFields :form="instrument" />
          <button type="button" class="cursor-pointer text-sm text-primary-700 hover:underline" @click="creating = false">{{ t('invest.back_to_search') }}</button>
        </template>
      </section>

      <section class="space-y-2">
        <h3 class="text-sm font-semibold">2. {{ t('invest.step_where') }}</h3>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label :for="fid('account')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.account') }}</label>
            <select :id="fid('account')" v-model="form.account_id" required :class="inputClass">
              <option :value="null" disabled>—</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.institution_name }} · {{ a.name }}</option>
            </select>
          </div>
          <div>
            <label :for="fid('portfolio')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('positions.portfolio') }}</label>
            <select :id="fid('portfolio')" v-model="form.portfolio_id" required :class="inputClass">
              <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
        </div>
      </section>

      <p class="text-xs text-neutral-500">3. {{ t('invest.step_buy') }}</p>
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button v-if="loaded && accounts.length && portfolios.length" type="submit" form="invest-form" :disabled="busy || !canSubmit"
        class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ busy ? t('common.saving') : t('invest.continue') }}</button>
    </template>
  </Modal>
</template>
