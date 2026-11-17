<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import SectionCard from '@/components/ui/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/ui/Modal.vue'
import SegmentControl from '@/components/ui/SegmentControl.vue'
import { adminApi, type UpdateRun, type UpdateStatus } from '@/api/admin'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { renderManual } from '@/utils/manual'

/**
 * Systém → Aktualizace: nainstalovaná vs. nejnovější vydaná verze (GitHub releases) a příkazy pro aktualizaci
 * podle typu instalace. Aktualizaci spouští správce na serveru — aplikace nemá práva měnit své soubory ani image.
 */
const { t, te } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const data = ref<UpdateStatus | null>(null)
const loading = ref(true)
const checking = ref(false)
const install = ref<'docker' | 'native'>('native')

async function load(refresh = false) {
  checking.value = refresh
  try {
    data.value = await adminApi.update(refresh)
    if (!refresh) install.value = data.value.install
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
    checking.value = false
  }
}
onMounted(async () => {
  await load()
  if (data.value?.last_run?.status === 'running' && !data.value.last_run.stale) startPolling()
})
onBeforeUnmount(() => { pollTimer && clearTimeout(pollTimer); pollStopped = true })

const target = computed(() => data.value?.latest?.version ?? data.value?.current ?? '')
const status = computed(() => {
  const d = data.value
  if (!d?.latest) return { tone: 'neutral' as const, text: t('update.unknown') }
  return d.update_available
    ? { tone: 'warning' as const, text: t('update.available', { version: d.latest.version }) }
    : { tone: 'success' as const, text: t('update.up_to_date') }
})
const notesHtml = (notes: string) => renderManual(notes)

/** Sekce „### Po aktualizaci“ z poznámek k vydání — kroky, které je potřeba spustit po aktualizaci */
const POST_UPDATE = /^#{2,4}\s+(Po aktualizaci|After updating)\s*$/i
function postUpdate(notes: string): string {
  let out: string[] | null = null
  let level = 0
  for (const line of notes.split(/\r?\n/)) {
    const heading = /^(#{1,6})\s/.exec(line)
    if (out) {
      if (heading && heading[1].length <= level) break
      out.push(line)
    } else if (heading && POST_UPDATE.test(line)) {
      out = []
      level = heading[1].length
    }
  }
  return out ? out.join('\n').trim() : ''
}
// od nejstarší verze — v pořadí, v jakém změny přišly
const postSteps = computed(() => [...(data.value?.releases ?? [])].reverse()
  .map(r => ({ version: r.version, markdown: postUpdate(r.notes) }))
  .filter(s => s.markdown !== '')
  .map(s => ({ version: s.version, html: renderManual(s.markdown) })))
const installOptions = computed(() => (['docker', 'native'] as const).map(v => ({ value: v, label: t(`update.install_${v}`) })))

const commands = computed(() => {
  const d = data.value
  if (!d) return ''
  if (install.value === 'docker') {
    return [
      `# docker-compose.production.yml → image: ghcr.io/${d.repo.toLowerCase()}:${target.value}`,
      'docker compose -f docker-compose.production.yml pull',
      'docker compose -f docker-compose.production.yml up -d',
    ].join('\n')
  }
  return [
    `# ${t('update.in_app_dir')}`,
    'git fetch --tags',
    `git checkout v${target.value.replace(/^v/, '')}`,
    'cd api && composer install --no-dev --optimize-autoloader && cd ..',
    `${d.php} api/bin/migrate.php`,
    'cd web && pnpm install --frozen-lockfile && pnpm build && cd ..',
  ].join('\n')
})

// --- Aktualizace z webu (nativní instalace) ---
const OPERATION = 'update.run'
const POLL_MS = 3000
const POLL_MAX_ERRORS = 5

const reasons = computed(() => data.value?.web_update?.reasons ?? [])
const canWebUpdate = computed(() => data.value?.install === 'native' && !!data.value.latest && data.value.update_available)
const run = computed<UpdateRun | null>(() => data.value?.last_run ?? null)
const running = ref(false)
const runStep = ref<string | null>(null)
const finishedOk = ref(false)
const runFailed = computed(() => !running.value && !finishedOk.value && run.value?.target !== data.value?.current && (run.value?.status === 'failed' || run.value?.stale === true))
const errorStep = computed(() => run.value?.stale ? null : run.value?.error_step ?? null)
const logText = computed(() => (run.value?.log_tail ?? []).join('\n'))
const stepLabel = (step: string | null | undefined) => step ? (te(`update.step_${step}`) ? t(`update.step_${step}`) : step) : '…'

let pollTimer: ReturnType<typeof setTimeout> | undefined
let pollStopped = false

function startPolling() {
  running.value = true
  finishedOk.value = false
  pollStopped = false
  runStep.value = data.value?.last_run?.step ?? null
  let errors = 0
  const tick = async () => {
    if (pollStopped) return
    let done = false
    try {
      const fresh = await adminApi.update()
      errors = 0
      data.value = fresh
      runStep.value = fresh.last_run.step ?? runStep.value
      if (fresh.last_run.status !== 'running' || fresh.last_run.stale) {
        done = true
        finishedOk.value = fresh.last_run.status === 'succeeded'
      }
    } catch (e: any) {
      const err = e?.response?.data?.error
      if (e?.response?.status === 503 && err?.code === 'maintenance') {
        errors = 0
        runStep.value = err.step ?? runStep.value
      } else if (++errors >= POLL_MAX_ERRORS) {
        done = true
        toast.error(apiErrorMessage(e, t('common.error')))
      }
    }
    if (done) running.value = false
    else pollTimer = setTimeout(tick, POLL_MS)
  }
  pollTimer = setTimeout(tick, POLL_MS)
}

const showConfirm = ref(false)
const busy = ref(false)
const passkeyBusy = ref(false)
const confirmError = ref('')
const totpCode = ref('')
const passkeyToken = ref('')
const passkeySupported = isWebAuthnAvailable()
const needsTotp = computed(() => auth.user?.totp_enabled === true)
const hasPasskey = computed(() => auth.user?.mfa_methods?.includes('passkey') === true || (auth.user?.passkey_count ?? 0) > 0)
const needsStepUp = computed(() => needsTotp.value || hasPasskey.value)

function openConfirm() {
  totpCode.value = ''
  passkeyToken.value = ''
  confirmError.value = ''
  showConfirm.value = true
}
function closeConfirm() {
  if (busy.value || passkeyBusy.value) return
  showConfirm.value = false
}

async function verifyWithPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  confirmError.value = ''
  passkeyToken.value = ''
  try {
    const flow = await authApi.passkeyStepUpOptions(OPERATION)
    const credential = await getCredential(flow.public_key)
    passkeyToken.value = await authApi.passkeyStepUpVerify(flow.flow_token, OPERATION, credential)
    totpCode.value = ''
  } catch (e: any) {
    const ceremonyError = webAuthnErrorKey(e)
    confirmError.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('api_tokens.passkey_failed')
  } finally {
    passkeyBusy.value = false
  }
}

async function submitRun() {
  const version = data.value?.latest?.version
  if (!version) return
  const hasValidTotp = needsTotp.value && /^\d{6}$/.test(totpCode.value)
  if (needsStepUp.value && !passkeyToken.value && !hasValidTotp) {
    confirmError.value = t('api_tokens.step_up_required')
    return
  }
  busy.value = true
  confirmError.value = ''
  const proof = passkeyToken.value
  try {
    await adminApi.runUpdate({
      version,
      totp_code: !proof && hasValidTotp ? totpCode.value : undefined,
      step_up_token: proof || undefined,
    })
    passkeyToken.value = ''
    showConfirm.value = false
    if (data.value) data.value.last_run = { status: 'running', target: version, step: 'starting', log_tail: [], stale: false }
    startPolling()
  } catch (e: any) {
    // proof i TOTP kód jsou jednorázové — po chybě je potřeba nový
    passkeyToken.value = ''
    totpCode.value = ''
    const err = e?.response?.data?.error
    confirmError.value = err?.message || t('common.error')
    if (e?.response?.status === 409) {
      showConfirm.value = false
      toast.error(confirmError.value)
      load(true)
    }
  } finally {
    busy.value = false
  }
}

function reload() { window.location.reload() }

const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(commands.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    toast.error(t('common.error'))
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('update.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('update.subtitle') }}</p>
      </div>
      <button type="button" :disabled="checking || loading" @click="load(true)"
        class="cursor-pointer h-9 px-3 inline-flex items-center gap-1.5 border border-neutral-300 rounded-md bg-surface text-sm hover:bg-neutral-50 disabled:opacity-60 shrink-0 whitespace-nowrap">
        {{ checking ? t('update.checking') : t('update.check_now') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <SectionCard :title="t('update.versions')" class="mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3 items-start">
          <div class="min-w-0">
            <div class="text-xs text-neutral-500">{{ t('update.current') }}</div>
            <div class="mt-1 font-mono font-semibold text-xl sm:text-3xl tracking-tight break-all">{{ data.current }}</div>
          </div>
          <div class="min-w-0">
            <div class="text-xs text-neutral-500">{{ t('update.latest') }}</div>
            <div class="mt-1 font-mono font-semibold text-xl sm:text-3xl tracking-tight break-all">{{ data.latest?.version ?? '—' }}</div>
            <div v-if="data.latest?.published_at" class="text-sm text-neutral-500 tabular-nums">{{ formatDate(data.latest.published_at) }}</div>
          </div>
          <div class="col-span-2 sm:col-span-1 flex flex-col gap-1.5 sm:items-end">
            <Badge :tone="status.tone">{{ status.text }}</Badge>
            <span v-if="data.checked_at" class="text-xs text-neutral-500">{{ t('update.checked_at', { at: formatDateTime(data.checked_at) }) }}</span>
          </div>
        </div>
        <p v-if="data.error" class="mt-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
          <Icon name="warning" class="w-4 h-4 align-[-3px] mr-1" />{{ t(`update.err_${data.error}`, { repo: data.repo }) }}
        </p>
      </SectionCard>

      <SectionCard v-if="canWebUpdate || running || runFailed || finishedOk" :title="t('update.web_title')" class="mb-4">
        <div v-if="running" role="status" class="flex items-center gap-3 text-sm">
          <svg class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
          <div>
            <p class="font-medium">{{ t('update.running', { version: run?.target ?? '' }) }}</p>
            <p class="text-neutral-500">{{ t('update.running_step', { step: stepLabel(runStep) }) }}</p>
            <p class="text-xs text-neutral-500 mt-1">{{ t('update.running_hint') }}</p>
          </div>
        </div>
        <div v-else-if="finishedOk" role="status" class="space-y-3">
          <p class="text-sm font-medium text-success-600"><Icon name="check" class="w-4 h-4 align-[-3px]" /> {{ t('update.succeeded', { version: data.current }) }}</p>
          <button type="button" @click="reload"
            class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md">
            {{ t('update.reload') }}
          </button>
        </div>
        <template v-else>
          <div v-if="runFailed" role="alert" class="mb-4 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            <p class="font-medium">{{ run?.stale ? t('update.failed_stale') : t('update.failed', { step: stepLabel(errorStep) }) }}</p>
            <pre v-if="logText" class="mt-2 max-h-72 overflow-auto rounded-md bg-neutral-900 text-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap">{{ logText }}</pre>
            <p class="mt-2">
              {{ t('update.failed_manual') }}
              <RouterLink to="/manual/11_Administrace#117-aktualizace" class="text-primary-700 hover:underline">{{ t('update.manual_link_admin') }}</RouterLink>
            </p>
          </div>
          <div v-if="canWebUpdate">
            <button type="button" :disabled="reasons.length > 0" @click="openConfirm"
              class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md">
              {{ t('update.run_button', { version: data.latest?.version ?? '' }) }}
            </button>
            <div v-if="reasons.length" class="mt-3 text-sm text-neutral-600">
              <p>{{ t('update.reasons_title') }}</p>
              <ul class="list-disc pl-5 mt-1 space-y-0.5">
                <li v-for="r in reasons" :key="r">{{ t(`update.reason_${r}`) }}</li>
              </ul>
              <RouterLink to="/manual/11_Administrace#117-aktualizace" class="inline-block mt-2 text-primary-700 hover:underline">{{ t('update.manual_link_admin') }}</RouterLink>
            </div>
          </div>
        </template>
      </SectionCard>

      <SectionCard v-if="data.update_available && data.releases.length" :title="t('update.notes_title')" class="mb-4">
        <div class="max-h-[36rem] overflow-y-auto divide-y divide-neutral-200 -my-4">
          <section v-for="r in data.releases" :key="r.version" class="py-4">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-2">
              <h3 class="font-semibold">{{ r.name }}</h3>
              <span v-if="r.published_at" class="text-xs text-neutral-500 tabular-nums">{{ formatDate(r.published_at) }}</span>
              <Badge v-if="r.prerelease">{{ t('update.prerelease') }}</Badge>
              <a v-if="r.url" :href="r.url" target="_blank" rel="noopener" class="ml-auto text-xs text-primary-700 hover:underline">{{ t('update.open_github') }}</a>
            </div>
            <!-- markdown-it s html: false — HTML v popisu vydání se escapuje -->
            <div v-if="r.notes" class="manual-prose [&>:first-child]:mt-0" v-html="notesHtml(r.notes)" />
            <p v-else class="text-sm text-neutral-500">{{ t('update.no_notes') }}</p>
          </section>
        </div>
      </SectionCard>

      <SectionCard :title="t('update.howto_title')">
        <template #actions>
          <SegmentControl v-model="install" :options="installOptions" :label="t('update.howto_title')" />
        </template>
        <ol class="space-y-4 text-sm">
          <li class="flex gap-3">
            <span class="w-6 h-6 shrink-0 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold inline-flex items-center justify-center">1</span>
            <p class="pt-0.5">{{ t('update.step_backup') }}
              <RouterLink to="/admin/cron-jobs" class="text-primary-700 hover:underline whitespace-nowrap">{{ t('update.step_backup_link') }} →</RouterLink>
            </p>
          </li>
          <li class="flex gap-3">
            <span class="w-6 h-6 shrink-0 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold inline-flex items-center justify-center">2</span>
            <div class="min-w-0 flex-1 space-y-2">
              <p class="pt-0.5">
                {{ t('update.step_run') }}
                <span v-if="install === data.install" class="text-xs text-neutral-500">({{ t('update.detected') }})</span>
              </p>
              <div class="relative">
                <pre class="bg-neutral-900 text-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 rounded-md p-3 pr-24 font-mono text-xs leading-relaxed overflow-x-auto">{{ commands }}</pre>
                <button type="button" @click="copy"
                  class="cursor-pointer absolute top-2 right-2 h-7 px-2 rounded-md text-xs font-medium bg-surface text-neutral-700 border border-neutral-300 hover:bg-neutral-50">
                  <Icon v-if="copied" name="check" class="w-3.5 h-3.5 align-[-2px]" /> {{ copied ? t('update.copied') : t('update.copy') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500">{{ install === 'docker' ? t('update.docker_hint', { version: target }) : t('update.native_hint') }}</p>
            </div>
          </li>
          <li v-if="postSteps.length" class="flex gap-3">
            <span class="w-6 h-6 shrink-0 rounded-full bg-warning-50 text-warning-600 text-xs font-semibold inline-flex items-center justify-center">3</span>
            <div class="min-w-0 flex-1 space-y-2">
              <p class="pt-0.5">{{ t('update.step_after') }}</p>
              <div v-for="s in postSteps" :key="s.version" class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2">
                <div class="text-xs font-mono font-semibold text-warning-600 mb-1">{{ s.version }}</div>
                <div class="manual-prose text-sm [&>:first-child]:mt-0 [&>:last-child]:mb-0" v-html="s.html" />
              </div>
            </div>
          </li>
        </ol>
        <template #footer>
          <p class="text-xs text-neutral-500">
            {{ t('update.manual_only') }}
            <RouterLink to="/manual/02_Instalace#24-aktualizace" class="text-primary-700 hover:underline">{{ t('update.manual_link') }}</RouterLink>
          </p>
        </template>
      </SectionCard>
    </template>
    <Modal v-if="showConfirm" :title="t('update.confirm_title', { version: data?.latest?.version ?? '' })" width-class="max-w-md" @close="closeConfirm">
      <div class="space-y-3">
        <ul class="list-disc pl-5 text-sm space-y-1">
          <li>{{ t('update.confirm_backup') }}</li>
          <li>{{ t('update.confirm_downtime') }}</li>
          <li>{{ t('update.confirm_no_rollback') }}</li>
        </ul>

        <div v-if="hasPasskey" class="rounded-md border border-neutral-200 bg-neutral-50 p-3">
          <p class="text-sm text-neutral-700 mb-2">{{ t('update.step_up_hint') }}</p>
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
          <p class="text-xs text-neutral-500 mt-1">{{ t('update.totp_hint') }}</p>
        </label>
      </div>

      <div v-if="confirmError" role="alert"
        class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ confirmError }}
      </div>
      <template #footer>
        <button type="button" @click="closeConfirm" :disabled="busy || passkeyBusy"
          class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button type="button" @click="submitRun" :disabled="busy || passkeyBusy"
          class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action text-sm font-medium rounded-md">
          {{ busy ? '…' : t('update.confirm_run') }}
        </button>
      </template>
    </Modal>
  </div>
</template>
