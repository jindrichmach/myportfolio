<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import IconButton from '@/components/ui/IconButton.vue'
import Badge, { type BadgeTone } from '@/components/ui/Badge.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import SegmentControl from '@/components/ui/SegmentControl.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { portfolioApi, type Account, type Portfolio } from '@/api/portfolio'
import { catalogApi, ASSET_CLASSES, type AssetClass, type Instrument } from '@/api/catalog'
import {
  importsApi,
  type AssetDecision, type ImportAsset, type ImportBatch, type ImportPreview, type ImportSource, type RowStatus, type TransferDecision,
} from '@/api/imports'
import { apiErrorMessage } from '@/api/errors'
import { formatDateTime, formatDec } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'
import ManualLink from '@/components/ui/ManualLink.vue'

const { t } = useI18n()
const fid = useFieldId()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const accounts = ref<Account[]>([])
const portfolios = ref<Portfolio[]>([])
const sources = ref<ImportSource[]>([])
const batches = ref<ImportBatch[]>([])
const loading = ref(true)

async function loadBatches() {
  batches.value = await importsApi.batches()
}
onMounted(async () => {
  try {
    ;[accounts.value, portfolios.value, sources.value] = await Promise.all([
      portfolioApi.accounts(), portfolioApi.portfolios(), importsApi.sources(),
    ])
    await loadBatches()
    if (portfolios.value.length === 1) upload.portfolio_id = portfolios.value[0].id
    // Čekající náhled ze synchronizace burzy (Profil → Burzy)
    if (typeof route.query.token === 'string') setPreview(await importsApi.get(route.query.token))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
})

function sourceLabel(source: string): string {
  const key = source.replace(/^(csv|api):/, '')
  return sources.value.find(s => s.key === key)?.label ?? source
}

// ── Nahrání souboru ─────────────────────────────────────────────────────
const upload = reactive({ account_id: '' as number | '', portfolio_id: '' as number | '', source: '', file: null as File | null })
const uploading = ref(false)
const uploadError = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

function onFile(e: Event) {
  upload.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function loadPreview() {
  if (!upload.file || !upload.account_id || !upload.portfolio_id) return
  uploading.value = true
  uploadError.value = ''
  try {
    setPreview(await importsApi.preview(upload.file, upload.account_id, upload.portfolio_id, upload.source))
  } catch (e) {
    uploadError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    uploading.value = false
  }
}

// ── Náhled a rozhodnutí ─────────────────────────────────────────────────
type AssetMode = 'existing' | 'create' | 'skip'
interface AssetChoice {
  mode: AssetMode
  instrument_id: number | null
  create: { symbol: string; name: string; asset_class: AssetClass; quote_currency: string }
}

const preview = ref<ImportPreview | null>(null)
const choices = reactive<Record<string, AssetChoice>>({})
const transferChoices = reactive<Record<number, string>>({})
const statusFilter = ref<RowStatus | ''>('')
const confirming = ref(false)
const confirmError = ref('')

function setPreview(p: ImportPreview) {
  preview.value = p
  confirmError.value = ''
  statusFilter.value = ''
  for (const k of Object.keys(choices)) delete choices[k]
  for (const k of Object.keys(transferChoices)) delete transferChoices[Number(k)]
  for (const a of p.assets) {
    choices[a.key] = {
      mode: a.instrument_id !== null ? 'existing' : 'create',
      instrument_id: a.instrument_id,
      create: {
        symbol: a.symbol,
        name: a.name ?? a.symbol,
        asset_class: a.asset_class === 'crypto' ? 'crypto' : /\b(ETF|UCITS)\b/i.test(a.name ?? '') ? 'etf' : 'stock',
        quote_currency: a.currency ?? '',
      },
    }
  }
  for (const tr of p.transfers) {
    const asset = p.assets.find(a => a.key === tr.asset_key)
    transferChoices[tr.index] = asset?.positions.length === 1 ? `p:${asset.positions[0].id}` : ''
  }
}

const pendingAssets = computed(() => preview.value?.assets.filter(a => a.new_rows > 0) ?? [])
const filteredRows = computed(() => (preview.value?.rows ?? []).filter(r => !statusFilter.value || r.status === statusFilter.value))
const accountCurrency = computed(() => accounts.value.find(a => a.id === preview.value?.account.id)?.currency ?? null)
const assetByKey = computed(() => Object.fromEntries((preview.value?.assets ?? []).map(a => [a.key, a])))

// Hledání v katalogu pro „existující instrument" (kandidáti z náhledu + výsledky hledání)
const searchResults = ref<Record<string, Instrument[]>>({})
const searching = ref<string | null>(null)
async function searchInstruments(a: ImportAsset, q: string) {
  if (q.trim().length < 1) return
  searching.value = a.key
  try {
    searchResults.value[a.key] = await catalogApi.instruments({ q })
  } finally {
    searching.value = null
  }
}
function instrumentOptions(a: ImportAsset) {
  const seen = new Set<number>()
  return [...a.candidates, ...(searchResults.value[a.key] ?? [])]
    .filter(i => !seen.has(i.id) && seen.add(i.id))
    .map(i => ({ value: i.id, label: `${i.symbol} (${i.quote_currency})`, secondary: i.name }))
}

function decisions(): { assets: Record<string, AssetDecision>; transfers: Record<number, TransferDecision> } {
  const assets: Record<string, AssetDecision> = {}
  for (const a of pendingAssets.value) {
    const c = choices[a.key]
    if (c.mode === 'skip') assets[a.key] = { skip: true }
    else if (c.mode === 'existing' && c.instrument_id !== null) assets[a.key] = { instrument_id: c.instrument_id }
    else if (c.mode === 'create') assets[a.key] = { create: { ...c.create, quote_currency: c.create.quote_currency.toUpperCase(), isin: a.isin } }
  }
  const transfers: Record<number, TransferDecision> = {}
  for (const [index, v] of Object.entries(transferChoices)) {
    const [kind, id] = v.split(':')
    transfers[Number(index)] = kind === 'p' ? { position_id: Number(id) } : kind === 'a' ? { account_id: Number(id) } : null
  }
  return { assets, transfers }
}

async function confirmImport() {
  if (!preview.value) return
  confirming.value = true
  confirmError.value = ''
  try {
    const d = decisions()
    const r = await importsApi.confirm(preview.value.token, d.assets, d.transfers)
    toast.success(t('import.done', { imported: r.imported, skipped: r.skipped }))
    reset()
    await loadBatches()
  } catch (e) {
    confirmError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    confirming.value = false
  }
}

function reset() {
  preview.value = null
  upload.file = null
  if (fileInput.value) fileInput.value.value = ''
  if (route.query.token) router.replace({ query: {} })
}

// ── Dávky ───────────────────────────────────────────────────────────────
const reverting = ref<number | null>(null)
async function revert(b: ImportBatch) {
  if (!confirm(t('import.revert_confirm', { n: b.transactions }))) return
  reverting.value = b.id
  try {
    const r = await importsApi.revert(b.id)
    toast.success(t('import.reverted', { n: r.deleted }))
    await loadBatches()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    reverting.value = null
  }
}

const STATUS_TONES: Record<RowStatus, BadgeTone> = {
  new: 'success',
  duplicate: 'neutral',
  skip: 'warning',
}
const modeOptions = computed(() => (['existing', 'create', 'skip'] as const).map(m => ({ value: m, label: t(`import.mode.${m}`) })))
const batchColumns = computed<DataColumn[]>(() => [
  { key: 'created_at', label: t('import.col_date') },
  { key: 'source', label: t('import.source'), primary: true },
  { key: 'transactions', label: t('import.col_transactions'), align: 'right' },
])
const batchKey = (b: { id: number }) => b.id
const NOTICE = 'rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500'

const inputClass = 'w-full h-9 px-3 border border-field rounded-md bg-surface text-sm'
const labelClass = 'block text-sm font-medium text-neutral-700 mb-1'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('import.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('import.subtitle') }}</p>
        <ManualLink class="mt-1" to="05_Transakce#55-import-z-burzy--brokera" :label="t('manual.guide')" />
      </div>
      <RouterLink to="/transactions" class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 shrink-0">
        {{ t('nav.transactions') }}
      </RouterLink>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <!-- 1. Soubor -->
      <SectionCard v-if="!preview" class="mb-6">
        <form class="grid gap-3 md:grid-cols-2" @submit.prevent="loadPreview">
          <div>
            <label :for="fid('account_id')" :class="labelClass">{{ t('import.account') }}</label>
            <select :id="fid('account_id')" v-model="upload.account_id" required :class="inputClass">
              <option value="" disabled>{{ t('import.choose') }}</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.institution_name }} / {{ a.name }} ({{ a.currency }})</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('import.account_hint') }}</p>
          </div>
          <div>
            <label :for="fid('portfolio_id')" :class="labelClass">{{ t('import.portfolio') }}</label>
            <select :id="fid('portfolio_id')" v-model="upload.portfolio_id" required :class="inputClass">
              <option value="" disabled>{{ t('import.choose') }}</option>
              <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('import.portfolio_hint') }}</p>
          </div>
          <div>
            <label :for="fid('source')" :class="labelClass">{{ t('import.source') }}</label>
            <select :id="fid('source')" v-model="upload.source" :class="inputClass">
              <option value="">{{ t('import.source_auto') }}</option>
              <option v-for="s in sources" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
          </div>
          <div>
            <label :for="fid('field')" :class="labelClass">{{ t('import.file') }}</label>
            <input :id="fid('field')" ref="fileInput" type="file" required accept=".csv,.txt,.xlsx,.zip" @change="onFile"
              class="block w-full text-sm file:mr-3 file:h-9 file:px-3 file:border-0 file:rounded-md file:bg-neutral-100 file:text-sm file:cursor-pointer" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('import.file_hint') }}</p>
          </div>
          <div class="md:col-span-2 flex flex-wrap items-center gap-3">
            <button type="submit" :disabled="uploading || !upload.file || !upload.account_id || !upload.portfolio_id"
              class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md">
              {{ uploading ? t('common.loading') : t('import.load_preview') }}
            </button>
          </div>
          <p v-if="uploadError" role="alert" class="md:col-span-2" :class="NOTICE">{{ uploadError }}</p>
        </form>
        <p class="text-xs text-neutral-500 mt-3">{{ t('import.supported') }}: {{ sources.map(s => s.label).join(', ') }}</p>
      </SectionCard>

      <!-- 2. Náhled -->
      <template v-else>
        <SectionCard class="mb-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-semibold">{{ preview.source.label }} · <span class="font-normal text-neutral-600 break-all">{{ preview.filename }}</span></p>
              <p class="text-sm text-neutral-500">{{ preview.account.name }} → {{ preview.portfolio.name }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <Badge tone="success">{{ t('import.summary.new', { n: preview.summary.new }) }}</Badge>
              <Badge>{{ t('import.summary.duplicate', { n: preview.summary.duplicate }) }}</Badge>
              <Badge tone="warning">{{ t('import.summary.skipped', { n: preview.summary.skipped }) }}</Badge>
              <Badge v-if="preview.summary.transfers" tone="primary">{{ t('import.summary.transfers', { n: preview.summary.transfers }) }}</Badge>
            </div>
          </div>
        </SectionCard>

        <!-- Instrumenty -->
        <SectionCard v-if="pendingAssets.length" :title="t('import.assets_title')" flush class="mb-4">
          <div class="divide-y divide-neutral-100">
            <div v-for="a in pendingAssets" :key="a.key" class="p-4 grid gap-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
              <div class="min-w-0">
                <p class="font-semibold">{{ a.symbol || a.isin }}</p>
                <p class="text-xs text-neutral-500 break-words">
                  {{ a.name }}<template v-if="a.isin && a.symbol"> · <span class="font-mono">{{ a.isin }}</span></template>
                </p>
                <p class="text-xs text-neutral-500">{{ t('import.asset_rows', { n: a.new_rows }) }}</p>
              </div>
              <div class="space-y-2 min-w-0">
                <SegmentControl v-model="choices[a.key].mode" :options="modeOptions" :label="a.symbol || a.isin || ''" />
                <SearchableSelect v-if="choices[a.key].mode === 'existing'" v-model="choices[a.key].instrument_id" remote
                  :loading="searching === a.key" :options="instrumentOptions(a)"
                  :placeholder="t('instruments.search_placeholder')" @search="q => searchInstruments(a, q)" />
                <div v-else-if="choices[a.key].mode === 'create'" class="grid gap-2 grid-cols-2 md:grid-cols-4">
                  <input v-model="choices[a.key].create.symbol" :placeholder="t('import.create.symbol')" required maxlength="40" :class="inputClass" />
                  <input v-model="choices[a.key].create.name" :placeholder="t('import.create.name')" required maxlength="190" :class="inputClass" />
                  <select v-model="choices[a.key].create.asset_class" :class="inputClass">
                    <option v-for="c in ASSET_CLASSES.filter(c => c !== 'cash')" :key="c" :value="c">{{ t(`instruments.classes.${c}`) }}</option>
                  </select>
                  <input v-model="choices[a.key].create.quote_currency" :placeholder="t('import.create.currency')" required maxlength="3" :class="inputClass + ' uppercase'" />
                </div>
                <p v-else class="text-xs text-neutral-500">{{ t('import.mode_skip_hint') }}</p>
              </div>
            </div>
          </div>
        </SectionCard>

        <!-- Převody -->
        <SectionCard v-if="preview.transfers.length" :title="t('import.transfers_title')" flush class="mb-4">
          <p class="px-4 pt-3 text-xs text-neutral-500">{{ t('import.transfers_hint') }}</p>
          <div class="divide-y divide-neutral-100">
            <div v-for="tr in preview.transfers" :key="tr.index" class="px-4 py-3 flex flex-wrap items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm">
                  <span class="font-medium">{{ t(`import.kinds.${tr.kind}`) }}</span>
                  {{ formatDec(tr.quantity) }} {{ tr.symbol }}
                </p>
                <p class="text-xs text-neutral-500">{{ formatDateTime(tr.executed_at) }}<template v-if="tr.note"> · {{ tr.note }}</template></p>
              </div>
              <select v-model="transferChoices[tr.index]" :class="inputClass + ' md:w-80'">
                <option value="">{{ t('import.transfer_skip') }}</option>
                <optgroup v-if="assetByKey[tr.asset_key]?.positions.length" :label="t('import.transfer_positions')">
                  <option v-for="p in assetByKey[tr.asset_key].positions" :key="p.id" :value="`p:${p.id}`">{{ p.label }}</option>
                </optgroup>
                <optgroup :label="t('import.transfer_accounts')">
                  <option v-for="a in preview.accounts" :key="a.id" :value="`a:${a.id}`">{{ a.name }}</option>
                </optgroup>
              </select>
            </div>
          </div>
        </SectionCard>

        <!-- Řádky -->
        <SectionCard :title="t('import.rows_title')" flush class="mb-4">
          <template #actions>
            <select v-model="statusFilter" :aria-label="t('import.rows_title')" class="h-7 px-2 border border-field rounded-md bg-surface text-xs">
              <option value="">{{ t('import.all_rows', { n: preview.summary.rows }) }}</option>
              <option v-for="s in (['new', 'duplicate', 'skip'] as const)" :key="s" :value="s">{{ t(`import.statuses.${s}`) }}</option>
            </select>
          </template>
          <div class="overflow-x-auto max-h-[32rem]">
            <table class="w-full text-sm">
              <thead class="text-xs text-neutral-500 uppercase tracking-wide sticky top-0 bg-surface">
                <tr>
                  <th class="px-4 py-2 text-left font-medium">{{ t('tx.executed_at') }}</th>
                  <th class="px-4 py-2 text-left font-medium">{{ t('import.col_kind') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('tx.quantity') }}</th>
                  <th class="px-4 py-2 text-right font-medium">{{ t('tx.amount') }}</th>
                  <th class="px-4 py-2 text-right font-medium hidden md:table-cell">{{ t('tx.fee') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in filteredRows" :key="r.index" :class="{ 'text-neutral-500': r.status !== 'new' }">
                  <td class="px-4 py-2 whitespace-nowrap tabular-nums">
                    {{ formatDateTime(r.executed_at) }}
                    <span class="block text-xs text-neutral-500">{{ t('import.line', { n: r.line }) }}</span>
                  </td>
                  <td class="px-4 py-2">
                    <span class="font-medium">{{ t(`import.kinds.${r.kind}`) }}</span> {{ r.symbol }}
                    <Badge class="ml-1" :tone="STATUS_TONES[r.status]">{{ t(`import.statuses.${r.status}`) }}</Badge>
                    <span v-if="r.reason || r.note" class="block text-xs text-neutral-500 mt-0.5">{{ r.reason ?? r.note }}</span>
                  </td>
                  <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ r.quantity ? formatDec(r.quantity) : '—' }}</td>
                  <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ r.amount ? formatDec(r.amount, 4, r.currency ?? accountCurrency) : '—' }}</td>
                  <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap hidden md:table-cell">{{ r.fee ? formatDec(r.fee, 8, r.fee_currency) : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <template v-if="preview.rows.length < preview.summary.rows" #footer>
            <p class="text-xs text-neutral-500">{{ t('import.rows_truncated', { shown: preview.rows.length, total: preview.summary.rows }) }}</p>
          </template>
        </SectionCard>

        <div class="flex flex-wrap items-center gap-3 mb-6">
          <button @click="confirmImport" :disabled="confirming || preview.summary.new === 0"
            class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md">
            {{ confirming ? t('common.loading') : t('import.confirm', { n: preview.summary.new }) }}
          </button>
          <button @click="reset" class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <p v-if="confirmError" role="alert" class="w-full" :class="NOTICE">{{ confirmError }}</p>
        </div>
      </template>

      <!-- 3. Dávky -->
      <SectionCard :title="t('import.batches_title')" flush>
        <p v-if="!batches.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('import.batches_empty') }}</p>
        <DataTable v-else :columns="batchColumns" :rows="batches" :row-key="batchKey" :row-class="b => (b.reverted_at ? 'text-neutral-500' : '')">
          <template #cell-created_at="{ row: b }"><span class="whitespace-nowrap tabular-nums">{{ formatDateTime(b.created_at) }}</span></template>
          <template #cell-source="{ row: b }">
            <span class="font-medium">{{ sourceLabel(b.source) }}</span>
            <span class="block text-xs text-neutral-500 break-all">{{ b.filename }}</span>
          </template>
          <template #cell-transactions="{ row: b }">
            <Badge v-if="b.reverted_at">{{ t('import.reverted_badge') }}</Badge>
            <template v-else>{{ b.transactions }}</template>
          </template>
          <template #actions="{ row: b }">
            <IconButton v-if="!b.reverted_at" icon="undo" show-label variant="danger" :label="t('import.revert')"
              :loading="reverting === b.id" :disabled="reverting !== null" @click="revert(b)" />
          </template>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
