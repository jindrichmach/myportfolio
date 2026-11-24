<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/components/ui/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Icon from '@/components/ui/Icon.vue'
import ManualLink from '@/components/ui/ManualLink.vue'
import Modal from '@/components/ui/Modal.vue'
import { adminApi, type BackupItem, type BackupsResponse } from '@/api/admin'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { apiErrorMessage } from '@/api/errors'
import { formatBytes, formatDateTime } from '@/composables/useFormat'
import { useFieldId } from '@/composables/useFieldId'
import { useToast } from '@/composables/useToast'

/**
 * Systém → Zálohy: seznam záloh z cron-backup, ruční záloha, stažení ZIPu a obnova celé instance z webu
 * (step-up + opsání názvu zálohy; obnovu řídí `restore-backup.php --web` na pozadí).
 */
const { t, te } = useI18n()
const toast = useToast()
const fid = useFieldId()
const auth = useAuthStore()

const data = ref<BackupsResponse | null>(null)
const loading = ref(true)

async function load() {
  try {
    data.value = await adminApi.backups()
  } catch (e: any) {
    const err = e?.response?.data?.error
    if (e?.response?.status === 503 && err?.code === 'maintenance') {
      startPolling(err.step ?? null)
    } else {
      toast.error(apiErrorMessage(e, t('common.error')))
    }
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  await load()
  const run = data.value?.restore.last_run
  if (run?.status === 'running' && !run.interrupted) startPolling(run.step ?? null)
})
onBeforeUnmount(() => { pollStopped = true; pollTimer && clearTimeout(pollTimer) })

const backups = computed(() => data.value?.backups ?? [])
const reasons = computed(() => data.value?.restore.reasons ?? [])
const run = computed(() => data.value?.restore.last_run ?? null)
const runFailed = computed(() => !running.value && (run.value?.status === 'failed' || run.value?.interrupted === true))
const logText = computed(() => (run.value?.log_tail ?? []).join('\n'))
const stepLabel = (step: string | null | undefined) => step ? (te(`backups.step_${step}`) ? t(`backups.step_${step}`) : step) : '…'

const columns = computed<DataColumn[]>(() => [
  { key: 'name', label: t('backups.col_file'), primary: true, mono: true },
  { key: 'created_at', label: t('backups.col_date') },
  { key: 'size', label: t('backups.col_size'), align: 'right' },
  { key: 'encrypted', label: t('backups.col_status') },
])

const created = (b: BackupItem) => formatDateTime(b.created_at)

// --- Zálohovat teď ---
const BACKUP_POLL_MS = 2000
const BACKUP_POLL_MAX = 150
const backingUp = ref(false)

async function backupNow() {
  if (backingUp.value) return
  backingUp.value = true
  try {
    const before = (await adminApi.cronJobs()).jobs.find(j => j.script === 'cron-backup')?.last_started_at ?? null
    await adminApi.runCronJob('cron-backup')
    for (let i = 0; i < BACKUP_POLL_MAX && !pollStopped; i++) {
      await new Promise(r => setTimeout(r, BACKUP_POLL_MS))
      const job = (await adminApi.cronJobs()).jobs.find(j => j.script === 'cron-backup')
      if (job && job.last_started_at !== before && job.last_status !== 'running') {
        job.last_status === 'ok' ? toast.success(t('backups.backup_ok')) : toast.error(t('backups.backup_error'))
        await load()
        return
      }
    }
    if (!pollStopped) toast.error(t('backups.backup_timeout'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    backingUp.value = false
  }
}

// --- Průběh obnovy ---
const POLL_MS = 3000
const POLL_MAX_ERRORS = 5
const running = ref(false)
const lostContact = ref(false)
const runStep = ref<string | null>(null)
const runFile = ref('')
let pollTimer: ReturnType<typeof setTimeout> | undefined
let pollStopped = false

function toLogin() { window.location.href = '/login?restored=1' }

function startPolling(step: string | null) {
  if (running.value) return
  running.value = true
  lostContact.value = false
  pollStopped = false
  runStep.value = step
  runFile.value = data.value?.restore.last_run.file ?? runFile.value
  let errors = 0
  const tick = async () => {
    if (pollStopped) return
    let done = false
    try {
      const fresh = await adminApi.backups()
      errors = 0
      data.value = fresh
      runStep.value = fresh.restore.last_run.step ?? runStep.value
      const st = fresh.restore.last_run
      if (st.status === 'succeeded') { toLogin(); return }
      if (st.status !== 'running' || st.interrupted) done = true
    } catch (e: any) {
      const status = e?.response?.status
      const err = e?.response?.data?.error
      if (status === 503 && err?.code === 'maintenance') {
        errors = 0
        runStep.value = err.step ?? runStep.value
      } else if (status === 401) {
        // všechna přihlášení po obnově zneplatněna = obnova doběhla
        toLogin()
        return
      } else if (++errors >= POLL_MAX_ERRORS) {
        done = true
        lostContact.value = true
      }
    }
    if (done) running.value = false
    else pollTimer = setTimeout(tick, POLL_MS)
  }
  pollTimer = setTimeout(tick, POLL_MS)
}

// --- Dialog (stažení / obnova) se step-upem ---
const target = ref<{ kind: 'download' | 'restore'; backup: BackupItem } | null>(null)
const busy = ref(false)
const passkeyBusy = ref(false)
const dialogError = ref('')
const totpCode = ref('')
const passkeyToken = ref('')
const confirmText = ref('')
const passkeySupported = isWebAuthnAvailable()
const needsTotp = computed(() => auth.user?.totp_enabled === true)
const hasPasskey = computed(() => auth.user?.mfa_methods?.includes('passkey') === true || (auth.user?.passkey_count ?? 0) > 0)
const needsStepUp = computed(() => needsTotp.value || hasPasskey.value)
const operation = computed(() => target.value?.kind === 'restore' ? 'backup.restore' : 'backup.download')
const confirmMatches = computed(() => target.value?.kind !== 'restore' || confirmText.value === target.value.backup.name)
const canRestoreAny = computed(() => reasons.value.length === 0 && !running.value)

function openDialog(kind: 'download' | 'restore', backup: BackupItem) {
  totpCode.value = ''
  passkeyToken.value = ''
  confirmText.value = ''
  dialogError.value = ''
  target.value = { kind, backup }
}
function closeDialog() {
  if (busy.value || passkeyBusy.value) return
  target.value = null
}

async function verifyWithPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  dialogError.value = ''
  passkeyToken.value = ''
  try {
    const flow = await authApi.passkeyStepUpOptions(operation.value)
    const credential = await getCredential(flow.public_key)
    passkeyToken.value = await authApi.passkeyStepUpVerify(flow.flow_token, operation.value, credential)
    totpCode.value = ''
  } catch (e: any) {
    const ceremonyError = webAuthnErrorKey(e)
    dialogError.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('api_tokens.passkey_failed')
  } finally {
    passkeyBusy.value = false
  }
}

/** Chybová odpověď s blobem (stažení) → zpráva z JSON těla */
async function blobErrorMessage(e: any): Promise<string> {
  const body = e?.response?.data
  if (body instanceof Blob) {
    try { return JSON.parse(await body.text())?.error?.message || '' } catch { return '' }
  }
  return e?.response?.data?.error?.message || ''
}

async function submit() {
  const current = target.value
  if (!current || !confirmMatches.value) return
  const hasValidTotp = needsTotp.value && /^\d{6}$/.test(totpCode.value)
  if (needsStepUp.value && !passkeyToken.value && !hasValidTotp) {
    dialogError.value = t('api_tokens.step_up_required')
    return
  }
  busy.value = true
  dialogError.value = ''
  const proof = passkeyToken.value
  const stepUp = {
    totp_code: !proof && hasValidTotp ? totpCode.value : undefined,
    step_up_token: proof || undefined,
  }
  try {
    if (current.kind === 'download') {
      const blob = await adminApi.downloadBackup(current.backup.name, stepUp)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = current.backup.name
      a.click()
      setTimeout(() => URL.revokeObjectURL(url), 10_000)
      target.value = null
    } else {
      await adminApi.restoreBackup({ ...stepUp, file: current.backup.name, confirm: confirmText.value })
      target.value = null
      runFile.value = current.backup.name
      startPolling('starting')
    }
  } catch (e: any) {
    // proof i TOTP kód jsou jednorázové — po chybě je potřeba nový
    totpCode.value = ''
    dialogError.value = (current.kind === 'download' ? await blobErrorMessage(e) : e?.response?.data?.error?.message)
      || (current.kind === 'download' ? t('backups.download_failed') : t('common.error'))
    if (e?.response?.status === 409) {
      target.value = null
      toast.error(dialogError.value)
      load()
    }
  } finally {
    passkeyToken.value = ''
    busy.value = false
  }
}

const safetyName = computed(() => run.value?.safety_backup ?? null)
const cliCommand = (name: string) => [
  `php8.5 api/bin/restore-backup.php storage/backup/${name} --yes`,
  '# Docker:',
  `docker compose exec app php api/bin/restore-backup.php /data/storage/backup/${name} --yes`,
].join('\n')
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('backups.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('backups.subtitle') }}</p>
      </div>
      <button type="button" :disabled="backingUp || loading || running" @click="backupNow"
        class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md shrink-0 whitespace-nowrap">
        {{ backingUp ? t('backups.backup_running') : t('backups.backup_now') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <SectionCard v-if="running" class="mb-4">
        <div role="status" class="flex items-center gap-3 text-sm">
          <svg class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
          <div>
            <p class="font-medium">{{ runFile ? t('backups.running', { file: runFile }) : t('backups.running_unknown') }}</p>
            <p class="text-neutral-500">{{ t('backups.running_step', { step: stepLabel(runStep) }) }}</p>
            <p class="text-xs text-neutral-500 mt-1">{{ t('backups.running_hint') }}</p>
          </div>
        </div>
      </SectionCard>

      <div v-if="lostContact && !running" role="alert" class="mb-4 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        <p class="font-medium">{{ t('backups.lost_contact', { step: stepLabel(runStep) }) }}</p>
        <template v-if="runFile !== ''">
          <p class="mt-1">{{ t('backups.lost_contact_hint') }}</p>
          <pre class="mt-1 rounded-md bg-neutral-900 text-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 p-3 font-mono text-xs leading-relaxed overflow-x-auto">{{ cliCommand(t('backups.safety_placeholder')) }}</pre>
          <p class="mt-1 text-xs">{{ t('backups.cli_path_note') }}</p>
        </template>
        <p v-else class="mt-1">{{ t('backups.lost_contact_unknown') }}</p>
        <p class="mt-2"><ManualLink inline to="11_Administrace#118-zálohy-a-obnova" :label="t('backups.manual_link')" /></p>
      </div>

      <div v-if="runFailed" role="alert" class="mb-4 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        <p class="font-medium">
          {{ run?.interrupted ? t('backups.interrupted') : t('backups.failed', { step: stepLabel(run?.error_step) }) }}
        </p>
        <pre v-if="logText" class="mt-2 max-h-72 overflow-auto rounded-md bg-neutral-900 text-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap">{{ logText }}</pre>
        <template v-if="safetyName">
          <p class="mt-2">{{ t('backups.safety_backup', { name: safetyName }) }}</p>
          <p class="mt-1">{{ t('backups.cli_hint') }}</p>
          <pre class="mt-1 rounded-md bg-neutral-900 text-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 p-3 font-mono text-xs leading-relaxed overflow-x-auto">{{ cliCommand(safetyName) }}</pre>
          <p class="mt-1 text-xs">{{ t('backups.cli_path_note') }}</p>
        </template>
        <p class="mt-2"><ManualLink inline to="11_Administrace#118-zálohy-a-obnova" :label="t('backups.manual_link')" /></p>
      </div>

      <SectionCard :title="t('backups.list_title')" class="mb-4">
        <EmptyState v-if="backups.length === 0" :title="t('backups.empty_title')" :message="t('backups.empty_message')" />
        <DataTable v-else :columns="columns" :rows="backups" :row-key="(b: BackupItem) => b.name">
          <template #cell-created_at="{ row }">{{ created(row as BackupItem) }}</template>
          <template #cell-size="{ row }">{{ formatBytes((row as BackupItem).size) }}</template>
          <template #cell-encrypted="{ row }">
            <Badge :tone="(row as BackupItem).encrypted ? 'primary' : 'neutral'">
              {{ (row as BackupItem).encrypted ? t('backups.encrypted') : t('backups.plain') }}
            </Badge>
          </template>
          <template #actions="{ row }">
            <div class="flex flex-wrap items-center justify-end gap-2">
              <button type="button" :disabled="running" @click="openDialog('download', row as BackupItem)"
                class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md bg-surface text-sm hover:bg-neutral-50 disabled:opacity-60">
                {{ t('backups.download') }}
              </button>
              <button type="button" :disabled="!canRestoreAny || !(row as BackupItem).restorable"
                :title="!(row as BackupItem).restorable ? t('backups.not_restorable') : undefined"
                @click="openDialog('restore', row as BackupItem)"
                class="cursor-pointer h-8 px-3 border border-danger-500 text-danger-500 rounded-md bg-surface text-sm hover:bg-danger-50 disabled:border-neutral-300 disabled:text-neutral-400 disabled:bg-surface disabled:cursor-not-allowed">
                {{ t('backups.restore') }}
              </button>
            </div>
          </template>
        </DataTable>
        <template #footer>
          <div v-if="reasons.length" class="text-sm text-neutral-600">
            <p>{{ t('backups.reasons_title') }}</p>
            <ul class="list-disc pl-5 mt-1 space-y-0.5">
              <li v-for="r in reasons" :key="r">{{ te(`backups.reason_${r}`) ? t(`backups.reason_${r}`) : r }}</li>
            </ul>
          </div>
          <p v-if="backups.some(b => !b.restorable)" class="text-xs text-neutral-500" :class="{ 'mt-2': reasons.length }">{{ t('backups.not_restorable') }}</p>
          <p class="text-xs text-neutral-500" :class="{ 'mt-2': reasons.length || backups.some(b => !b.restorable) }">
            <ManualLink inline to="11_Administrace#118-zálohy-a-obnova" :label="t('backups.manual_link')" />
          </p>
        </template>
      </SectionCard>
    </template>

    <Modal v-if="target" :title="target.kind === 'restore' ? t('backups.restore_title', { file: target.backup.name }) : t('backups.download_title', { file: target.backup.name })"
      width-class="max-w-lg" @close="closeDialog">
      <div class="space-y-3">
        <div v-if="target.kind === 'restore'" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          <p class="font-semibold"><Icon name="warning" class="w-4 h-4 align-[-3px] mr-1" />{{ t('backups.warn_title') }}</p>
          <ul class="list-disc pl-5 mt-1 space-y-1">
            <li>{{ t('backups.warn_all_users') }}</li>
            <li>{{ t('backups.warn_loss') }}</li>
            <li>{{ t('backups.warn_logout') }}</li>
            <li>{{ t('backups.warn_revive') }}</li>
            <li>{{ t('backups.warn_safety') }}</li>
          </ul>
        </div>
        <p v-else class="text-sm text-neutral-700">{{ t('backups.download_warn') }}</p>

        <label v-if="target.kind === 'restore'" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('backups.confirm_label') }}</span>
          <input v-model="confirmText" type="text" autocomplete="off" spellcheck="false" :aria-describedby="fid('confirm-hint')"
            class="mt-1 w-full h-10 px-3 border border-field rounded-md bg-surface font-mono text-sm" />
          <p :id="fid('confirm-hint')" class="text-xs text-neutral-500 mt-1 break-all">{{ t('backups.confirm_hint', { file: target.backup.name }) }}</p>
        </label>

        <div v-if="hasPasskey" class="rounded-md border border-neutral-200 bg-neutral-50 p-3">
          <p class="text-sm text-neutral-700 mb-2">{{ t('backups.step_up_hint') }}</p>
          <div v-if="passkeyToken" class="text-sm font-medium text-success-600">
            <Icon name="check" class="w-4 h-4 align-[-3px]" /> {{ t('api_tokens.passkey_verified') }}
          </div>
          <button v-else-if="passkeySupported" type="button" @click="verifyWithPasskey" :disabled="passkeyBusy || busy"
            class="cursor-pointer h-10 px-4 border border-primary-600 text-primary-700 hover:bg-primary-50 disabled:border-neutral-300 disabled:text-neutral-400 rounded-md font-medium">
            {{ passkeyBusy ? t('auth.passkey_verifying') : t('api_tokens.verify_passkey') }}
          </button>
          <p v-else class="text-sm text-warning-600">{{ t('api_tokens.passkey_unsupported') }}</p>
        </div>

        <label v-if="needsTotp && !passkeyToken" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('auth.totp_code') }}</span>
          <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="000000"
            class="mt-1 w-full h-10 px-3 border border-field rounded-md bg-surface font-mono text-lg tracking-widest text-center" />
          <p class="text-xs text-neutral-500 mt-1">{{ t('backups.totp_hint') }}</p>
        </label>
      </div>

      <div v-if="dialogError" role="alert"
        class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ dialogError }}
      </div>
      <template #footer>
        <button type="button" @click="closeDialog" :disabled="busy || passkeyBusy"
          class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button type="button" @click="submit" :disabled="busy || passkeyBusy || !confirmMatches"
          class="cursor-pointer h-9 px-4 text-sm font-medium rounded-md disabled:cursor-not-allowed"
          :class="target.kind === 'restore'
            ? 'border border-danger-500 text-danger-500 bg-surface hover:bg-danger-50 disabled:border-neutral-300 disabled:text-neutral-400 disabled:hover:bg-surface'
            : 'bg-action hover:bg-action-hover text-on-action disabled:bg-neutral-300'">
          {{ busy ? '…' : target.kind === 'restore' ? t('backups.confirm_run') : t('backups.download_run') }}
        </button>
      </template>
    </Modal>
  </div>
</template>
