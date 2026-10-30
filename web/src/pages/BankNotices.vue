<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import { bankNoticesApi, type BankNotice, type ImapAccount, type ImapAccountInput, type NoticeStatus } from '@/api/bankNotices'
import { portfolioApi, type Position } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime, formatDec } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const toast = useToast()

const accounts = ref<ImapAccount[]>([])
const notices = ref<BankNotice[]>([])
const positions = ref<Position[]>([])
const loading = ref(true)
const busy = ref<{ id: number; action: 'test' | 'scan' } | null>(null)
const testResult = ref<Record<number, { ok: boolean; message: string; folders?: string[] }>>({})

const STATUS_BADGES: Record<NoticeStatus, string> = {
  imported: 'bg-success-50 text-success-600',
  skipped_old: 'bg-neutral-100 text-neutral-600',
  unmapped: 'bg-warning-50 text-warning-600',
  parse_failed: 'bg-danger-50 text-danger-500',
  security_rejected: 'bg-danger-50 text-danger-500',
  import_failed: 'bg-danger-50 text-danger-500',
}

const linked = computed(() => positions.value.filter(p => p.bank_account))
const manualWithoutLink = computed(() => positions.value.filter(p => p.valuation_mode === 'manual' && !p.bank_account))

async function load() {
  loading.value = true
  try {
    ;[accounts.value, notices.value, positions.value] = await Promise.all([
      bankNoticesApi.accounts(), bankNoticesApi.notices(), portfolioApi.positions(),
    ])
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ── Formulář schránky ───────────────────────────────────────────────────
const showForm = ref(false)
const formBusy = ref(false)
const formError = ref('')
const editId = ref<number | null>(null)
const emptyForm = (): ImapAccountInput => ({
  name: '', enabled: true, host: '', port: 993, encryption: 'ssl', validate_cert: true, username: '', password: '',
  folder: 'INBOX', max_messages_per_run: 50, require_email_auth: true, auth_serv_id: null,
})
const form = reactive<ImapAccountInput>(emptyForm())

function openCreate() {
  Object.assign(form, emptyForm())
  editId.value = null
  formError.value = ''
  showForm.value = true
}
function openEdit(a: ImapAccount) {
  const { id, last_scan_at, last_scan_status, last_scan_message, ...rest } = a
  Object.assign(form, { ...rest, password: '' })
  editId.value = id
  formError.value = ''
  showForm.value = true
}

async function save() {
  formBusy.value = true
  formError.value = ''
  const payload = { ...form, auth_serv_id: form.auth_serv_id?.trim() || null }
  try {
    if (editId.value === null) await bankNoticesApi.create(payload)
    else await bankNoticesApi.update(editId.value, payload)
    showForm.value = false
    await load()
  } catch (e) {
    formError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    formBusy.value = false
  }
}

async function remove(a: ImapAccount) {
  if (!confirm(t('common.confirm_delete', { name: a.name }))) return
  try {
    await bankNoticesApi.remove(a.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

async function test(a: ImapAccount) {
  busy.value = { id: a.id, action: 'test' }
  try {
    testResult.value[a.id] = await bankNoticesApi.test(a.id)
  } catch (e) {
    testResult.value[a.id] = { ok: false, message: apiErrorMessage(e, t('common.error')) }
  } finally {
    busy.value = null
  }
}

async function scan(a: ImapAccount) {
  busy.value = { id: a.id, action: 'scan' }
  try {
    const r = await bankNoticesApi.scan(a.id)
    if (r.error) toast.error(r.error)
    else toast.success(t('bank_notices.scan_done', { fetched: r.fetched ?? 0, imported: r.imported ?? 0, failed: r.failed ?? 0 }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

function amount(n: BankNotice): string {
  const p = n.payload
  return p?.amount ? formatDec(p.amount, 2, p.currency ?? 'CZK') : '—'
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
const noticeColumns = computed<DataColumn[]>(() => [
  { key: 'date', label: t('bank_notices.col_date') },
  { key: 'status', label: t('bank_notices.col_status') },
  { key: 'position', label: t('bank_notices.col_position'), primary: true },
  { key: 'amount', label: t('bank_notices.col_amount'), align: 'right' },
  { key: 'balance', label: t('bank_notices.col_balance'), align: 'right', hideBelow: 'lg' },
])
const noticeKey = (n: BankNotice) => n.id
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('bank_notices.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('bank_notices.subtitle') }}</p>
      </div>
      <button @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('bank_notices.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <!-- Schránky -->
      <section class="space-y-3 mb-6">
        <div v-if="!accounts.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
          <EmptyState :title="t('bank_notices.empty')" />
        </div>
        <div v-for="a in accounts" :key="a.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4" :class="{ 'opacity-60': !a.enabled }">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold">{{ a.name }}</span>
                <span v-if="!a.enabled" class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">{{ t('bank_notices.disabled') }}</span>
                <span v-if="a.require_email_auth" class="text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600">{{ t('bank_notices.auth_badge') }}</span>
              </div>
              <p class="text-sm text-neutral-500 font-mono break-all">{{ a.username }} · {{ a.host }}:{{ a.port }} · {{ a.folder }}</p>
              <p class="text-xs mt-1" :class="a.last_scan_status === 'error' ? 'text-danger-500' : 'text-neutral-500'">
                <template v-if="a.last_scan_at">{{ t('bank_notices.last_scan', { at: formatDateTime(a.last_scan_at) }) }} — {{ a.last_scan_message }}</template>
                <template v-else>{{ t('bank_notices.never_scanned') }}</template>
              </p>
            </div>
            <div class="inline-flex gap-1">
              <IconButton icon="plug" :label="t('bank_notices.test')" :loading="busy?.id === a.id && busy.action === 'test'" :disabled="busy !== null" @click="test(a)" />
              <IconButton icon="refresh" :label="t('bank_notices.scan')" :loading="busy?.id === a.id && busy.action === 'scan'" :disabled="busy !== null || !a.enabled" @click="scan(a)" />
              <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(a)" />
              <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="remove(a)" />
            </div>
          </div>
          <div v-if="testResult[a.id]" class="mt-3 text-sm rounded-md px-3 py-2" :class="testResult[a.id].ok ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
            {{ testResult[a.id].message }}
            <span v-if="testResult[a.id].folders?.length" class="block text-xs mt-1 text-neutral-600">
              {{ t('bank_notices.folders') }}: {{ testResult[a.id].folders!.join(', ') }}
            </span>
          </div>
        </div>
      </section>

      <!-- Napojené účty -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-6">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">{{ t('bank_notices.linked_title') }}</h2>
        <p v-if="!linked.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('bank_notices.linked_empty') }}</p>
        <table v-else class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="p in linked" :key="p.id">
              <td class="px-4 py-2">
                <span class="inline-flex items-center gap-2">
                  <EntityIcon :src="p.institution_icon_url" :name="p.institution_name" />
                  <RouterLink :to="`/positions/${p.id}`" class="font-medium hover:text-primary-700 hover:underline">{{ p.institution_name }} / {{ p.display_name }}</RouterLink>
                </span>
              </td>
              <td class="px-4 py-2 font-mono">{{ p.bank_account }}</td>
              <td class="px-4 py-2 text-neutral-500 whitespace-nowrap">{{ t('bank_notices.from_date', { date: formatDate(p.bank_notices_from) }) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="manualWithoutLink.length" class="px-4 py-2.5 text-xs text-neutral-500 border-t border-neutral-200">
          {{ t('bank_notices.link_hint') }}
          <RouterLink v-for="(p, i) in manualWithoutLink" :key="p.id" :to="`/positions/${p.id}`" class="text-primary-700 hover:underline">{{ i ? ', ' : ' ' }}{{ p.institution_name }} / {{ p.display_name }}</RouterLink>
        </p>
      </section>

      <!-- Protokol -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">{{ t('bank_notices.log_title') }}</h2>
        <p v-if="!notices.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('bank_notices.log_empty') }}</p>
        <DataTable v-else :columns="noticeColumns" :rows="notices" :row-key="noticeKey">
          <template #cell-date="{ row: n }">
            <span class="whitespace-nowrap tabular-nums">{{ formatDateTime(n.message_date) }}</span>
            <span class="block text-xs text-neutral-500">{{ n.parser }} · {{ n.imap_account_name }}</span>
          </template>
          <template #cell-status="{ row: n }">
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="STATUS_BADGES[n.status]">{{ t(`bank_notices.statuses.${n.status}`) }}</span>
            <span v-if="n.error" class="block text-xs text-neutral-500 mt-0.5">{{ n.error }}</span>
          </template>
          <template #cell-position="{ row: n }">
            <RouterLink v-if="n.position_id" :to="`/positions/${n.position_id}`" class="font-medium hover:text-primary-700 hover:underline">{{ n.position_name }}</RouterLink>
            <span v-else class="font-mono text-xs text-neutral-500">{{ n.payload?.account ?? '—' }}</span>
          </template>
          <template #cell-amount="{ row: n }">{{ amount(n) }}</template>
          <template #cell-balance="{ row: n }"><span class="text-neutral-600">{{ n.payload?.balance ? formatDec(n.payload.balance, 2, n.payload.currency ?? 'CZK') : '—' }}</span></template>
        </DataTable>
      </section>
    </template>

    <Modal v-if="showForm" :title="editId === null ? t('bank_notices.new') : t('bank_notices.edit_title')" width-class="max-w-lg" @close="showForm = false">
      <form id="imap-form" class="space-y-3" @submit.prevent="save">
        <div>
          <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
          <input :id="fid('name')" v-model="form.name" required maxlength="120" :placeholder="t('bank_notices.name_placeholder')" :class="inputClass" />
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label :for="fid('host')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.host') }}</label>
            <input :id="fid('host')" v-model="form.host" required placeholder="imap.example.com" autocomplete="off" :class="inputClass" />
          </div>
          <div>
            <label :for="fid('port')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.port') }}</label>
            <input :id="fid('port')" v-model.number="form.port" type="number" min="1" max="65535" required :class="inputClass" />
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label :for="fid('encryption')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.encryption') }}</label>
            <select :id="fid('encryption')" v-model="form.encryption" :class="inputClass">
              <option value="ssl">SSL/TLS</option>
              <option value="tls">STARTTLS</option>
              <option value="none">{{ t('bank_notices.encryption_none') }}</option>
            </select>
          </div>
          <div>
            <label :for="fid('folder')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.folder') }}</label>
            <input :id="fid('folder')" v-model="form.folder" placeholder="INBOX" :class="inputClass" />
          </div>
        </div>
        <div>
          <label :for="fid('username')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.username') }}</label>
          <input :id="fid('username')" v-model="form.username" required autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label :for="fid('password')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.password') }}</label>
          <input :id="fid('password')" v-model="form.password" type="password" :required="editId === null" autocomplete="new-password"
                 :placeholder="editId === null ? '' : t('bank_notices.password_keep')" :class="inputClass" />
        </div>
        <div class="space-y-2 pt-1">
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.require_email_auth" type="checkbox" class="rounded border-field text-primary-700" />
            {{ t('bank_notices.require_auth') }}
          </label>
          <p class="text-xs text-neutral-500 -mt-1 ml-6">{{ t('bank_notices.require_auth_hint') }}</p>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.validate_cert" type="checkbox" class="rounded border-field text-primary-700" />
            {{ t('bank_notices.validate_cert') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.enabled" type="checkbox" class="rounded border-field text-primary-700" />
            {{ t('bank_notices.enabled') }}
          </label>
        </div>
        <details class="text-sm">
          <summary class="cursor-pointer text-neutral-600">{{ t('bank_notices.advanced') }}</summary>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label :for="fid('max_messages_per_run')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.max_messages') }}</label>
              <input :id="fid('max_messages_per_run')" v-model.number="form.max_messages_per_run" type="number" min="1" max="500" :class="inputClass" />
            </div>
            <div>
              <label :for="fid('auth_serv_id')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_notices.auth_serv_id') }}</label>
              <input :id="fid('auth_serv_id')" v-model="form.auth_serv_id" placeholder="mx.example.com" :class="inputClass" />
            </div>
          </div>
        </details>
        <p v-if="formError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="imap-form" :disabled="formBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
