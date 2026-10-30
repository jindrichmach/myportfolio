<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import IconButton from '@/components/ui/IconButton.vue'
import {
  exchangesApi,
  type ExchangeConnection, type ExchangeInput, type ExchangeProvider, type ExchangeStart, type ExchangeStatus, type ExchangeTest,
} from '@/api/exchanges'
import { portfolioApi, type Account, type Portfolio } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const toast = useToast()

const providers = ref<ExchangeProvider[]>([])
const connections = ref<ExchangeConnection[]>([])
const accounts = ref<Account[]>([])
const portfolios = ref<Portfolio[]>([])
const loading = ref(true)
const syncing = ref<number | null>(null)

const STATUS_BADGES: Record<ExchangeStatus, string> = {
  ok: 'bg-success-50 text-success-600',
  pending: 'bg-warning-50 text-warning-600',
  error: 'bg-danger-50 text-danger-500',
}

const activeAccounts = computed(() => accounts.value.filter(a => !a.archived_at))
const activePortfolios = computed(() => portfolios.value.filter(p => !p.archived_at))
const providerLabel = (key: string) => providers.value.find(p => p.provider === key)?.label ?? key

async function load() {
  loading.value = true
  try {
    const [list, acc, pf] = await Promise.all([exchangesApi.list(), portfolioApi.accounts(), portfolioApi.portfolios()])
    providers.value = list.providers
    connections.value = list.connections
    accounts.value = acc
    portfolios.value = pf
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function sync(c: ExchangeConnection) {
  syncing.value = c.id
  try {
    const r = await exchangesApi.sync(c.id)
    if (r.status === 'error') toast.error(r.message)
    else toast.success(r.message)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    syncing.value = null
  }
}

async function remove(c: ExchangeConnection) {
  if (!confirm(t('exchanges.confirm_delete', { name: providerLabel(c.provider) }))) return
  try {
    await exchangesApi.remove(c.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

// ── Formulář napojení ───────────────────────────────────────────────────
const showForm = ref(false)
const formBusy = ref(false)
const formError = ref('')
const testing = ref(false)
const testResult = ref<ExchangeTest | null>(null)
const warnings = ref<string[]>([])
const editId = ref<number | null>(null)
const form = reactive({
  provider: '', account_id: '' as number | '', portfolio_id: '' as number | '', enabled: true,
  credentials: {} as Record<string, string>, start: 'continue' as ExchangeStart, start_date: '',
})
const fields = computed(() => providers.value.find(p => p.provider === form.provider)?.fields ?? [])
const credentialsFilled = computed(() => fields.value.every(f => (form.credentials[f.key] ?? '').trim() !== ''))

function openCreate() {
  Object.assign(form, {
    provider: providers.value[0]?.provider ?? '', account_id: '', portfolio_id: activePortfolios.value.length === 1 ? activePortfolios.value[0].id : '',
    enabled: true, credentials: {}, start: 'continue', start_date: '',
  })
  editId.value = null
  formError.value = ''
  testResult.value = null
  showForm.value = true
}
function openEdit(c: ExchangeConnection) {
  Object.assign(form, { provider: c.provider, account_id: c.account_id, portfolio_id: c.portfolio_id, enabled: c.enabled, credentials: {} })
  editId.value = c.id
  formError.value = ''
  testResult.value = null
  showForm.value = true
}

async function test() {
  testing.value = true
  try {
    testResult.value = await exchangesApi.test(form.provider, form.credentials)
  } catch (e) {
    testResult.value = { ok: false, message: apiErrorMessage(e, t('common.error')), warnings: [] }
  } finally {
    testing.value = false
  }
}

async function save() {
  if (!form.account_id || !form.portfolio_id) return
  formBusy.value = true
  formError.value = ''
  const payload: ExchangeInput = {
    account_id: form.account_id, portfolio_id: form.portfolio_id, enabled: form.enabled,
    credentials: Object.values(form.credentials).some(v => v.trim() !== '') ? form.credentials : {},
  }
  try {
    const r = editId.value === null
      ? await exchangesApi.create({ ...payload, provider: form.provider, start: form.start, start_date: form.start === 'date' ? form.start_date : undefined })
      : await exchangesApi.update(editId.value, payload)
    warnings.value = r.warnings
    showForm.value = false
    await load()
  } catch (e) {
    formError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    formBusy.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('exchanges.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('exchanges.subtitle') }}</p>
      </div>
      <button @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('exchanges.new') }}
      </button>
    </div>

    <div v-if="warnings.length" class="mb-4 text-sm rounded-md px-3 py-2 bg-warning-50 text-warning-600">
      <p v-for="w in warnings" :key="w">{{ w }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <section v-else class="space-y-3">
      <div v-if="!connections.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <EmptyState :title="t('exchanges.empty')" :message="t('exchanges.empty_hint', { list: providers.map(p => p.label).join(', ') })" />
      </div>
      <div v-for="c in connections" :key="c.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4" :class="{ 'opacity-60': !c.enabled }">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-semibold">{{ providerLabel(c.provider) }}</span>
              <span class="text-xs text-neutral-500 font-mono">{{ c.key_hint }}</span>
              <span v-if="!c.enabled" class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">{{ t('exchanges.disabled') }}</span>
              <span v-if="c.last_status" class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS_BADGES[c.last_status]">{{ t(`exchanges.statuses.${c.last_status}`) }}</span>
            </div>
            <p class="text-sm text-neutral-500">{{ c.institution_name }} / {{ c.account_name }} → {{ c.portfolio_name }}</p>
            <p class="text-xs text-neutral-500 mt-1">
              <template v-if="c.synced_until">{{ t('exchanges.synced_until', { at: formatDateTime(c.synced_until) }) }}</template>
              <template v-else-if="c.sync_from">{{ t('exchanges.sync_from', { date: formatDate(c.sync_from) }) }}</template>
              <template v-else>{{ t('exchanges.sync_all') }}</template>
            </p>
            <p v-if="c.last_sync_at" class="text-xs mt-0.5" :class="c.last_status === 'error' ? 'text-danger-500' : 'text-neutral-500'">
              {{ t('exchanges.last_sync', { at: formatDateTime(c.last_sync_at) }) }} — {{ c.last_message }}
            </p>
            <RouterLink v-if="c.last_status === 'pending' && c.pending_token" :to="{ path: '/transactions/import', query: { token: c.pending_token } }"
                        class="inline-block mt-2 text-sm font-medium text-primary-700 hover:underline">
              {{ t('exchanges.open_preview') }}
            </RouterLink>
          </div>
          <div class="inline-flex gap-1">
            <IconButton icon="refresh" :label="t('exchanges.sync_now')" :loading="syncing === c.id" :disabled="syncing !== null" @click="sync(c)" />
            <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(c)" />
            <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="remove(c)" />
          </div>
        </div>
      </div>
      <p class="text-xs text-neutral-500">{{ t('exchanges.cron_hint') }}</p>
    </section>

    <Modal v-if="showForm" :title="editId === null ? t('exchanges.new') : t('exchanges.edit_title')" width-class="max-w-lg" @close="showForm = false">
      <form id="exchange-form" class="space-y-3" @submit.prevent="save">
        <div>
          <label :for="fid('provider')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('exchanges.provider') }}</label>
          <select :id="fid('provider')" v-model="form.provider" :disabled="editId !== null" :class="inputClass" @change="form.credentials = {}; testResult = null">
            <option v-for="p in providers" :key="p.provider" :value="p.provider">{{ p.label }}</option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('exchanges.key_hint') }}</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label :for="fid('account_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('exchanges.account') }}</label>
            <select :id="fid('account_id')" v-model="form.account_id" required :class="inputClass">
              <option value="" disabled>—</option>
              <option v-for="a in activeAccounts" :key="a.id" :value="a.id">{{ a.institution_name }} / {{ a.name }}</option>
            </select>
          </div>
          <div>
            <label :for="fid('portfolio_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('exchanges.portfolio') }}</label>
            <select :id="fid('portfolio_id')" v-model="form.portfolio_id" required :class="inputClass">
              <option value="" disabled>—</option>
              <option v-for="p in activePortfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
        </div>
        <div v-for="f in fields" :key="f.key">
          <label :for="fid(`cred-${f.key}`)" class="block text-sm font-medium text-neutral-700 mb-1">{{ f.label }}</label>
          <textarea :id="fid(`cred-${f.key}`)" v-if="f.multiline" v-model="form.credentials[f.key]" rows="4" :required="editId === null"
                    autocomplete="off" spellcheck="false" :placeholder="editId === null ? '' : t('exchanges.keep_key')"
                    class="w-full px-3 py-2 border border-field rounded-md bg-surface text-xs font-mono"></textarea>
          <input v-else :id="fid(`cred-${f.key}`)" v-model="form.credentials[f.key]" :type="f.secret ? 'password' : 'text'" :required="editId === null"
                 autocomplete="off" spellcheck="false" :placeholder="editId === null ? '' : t('exchanges.keep_key')" :class="inputClass" />
        </div>
        <div class="flex items-center gap-3">
          <button type="button" :disabled="testing || !credentialsFilled" @click="test"
                  class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
            {{ testing ? t('common.loading') : t('exchanges.test') }}
          </button>
        </div>
        <div v-if="testResult" class="text-sm rounded-md px-3 py-2" :class="testResult.ok ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
          {{ testResult.message }}
          <span v-for="w in testResult.warnings" :key="w" class="block text-xs mt-1 text-warning-600">{{ w }}</span>
        </div>
        <fieldset v-if="editId === null" class="space-y-2 pt-1">
          <legend class="text-sm font-medium text-neutral-700 mb-1">{{ t('exchanges.start') }}</legend>
          <label v-for="s in (['continue', 'date', 'all'] as const)" :key="s" class="flex items-start gap-2 text-sm">
            <input v-model="form.start" type="radio" :value="s" class="mt-0.5 border-field text-primary-700" />
            <span>{{ t(`exchanges.start_${s}`) }}<span class="block text-xs text-neutral-500">{{ t(`exchanges.start_${s}_hint`) }}</span></span>
          </label>
          <input v-if="form.start === 'date'" v-model="form.start_date" type="date" required :class="inputClass" />
        </fieldset>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.enabled" type="checkbox" class="rounded border-field text-primary-700" />
          {{ t('exchanges.enabled') }}
        </label>
        <p v-if="formError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="exchange-form" :disabled="formBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ formBusy ? t('common.loading') : t('common.save') }}
        </button>
      </template>
    </Modal>
  </div>
</template>
