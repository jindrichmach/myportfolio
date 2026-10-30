<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { tokensApi, type ApiToken, type CreateTokenResult } from '@/api/tokens'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { authApi } from '@/api/auth'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Modal from '@/components/ui/Modal.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import ApiEndpoints from '@/components/api/ApiEndpoints.vue'
import Tabs, { type TabItem } from '@/components/ui/Tabs.vue'
import { useTabQuery } from '@/composables/useTabQuery'

const { t } = useI18n()
const auth = useAuthStore()

// Záložky Tokeny / Endpointy — volba v URL (?tab=endpoints), ať jde odkázat
const TAB_KEYS = ['tokens', 'endpoints'] as const
const tab = useTabQuery<(typeof TAB_KEYS)[number]>(TAB_KEYS, 'tokens')
const tabs = computed<TabItem<(typeof TAB_KEYS)[number]>[]>(() => TAB_KEYS.map(key => ({ key, label: t(`api.tabs.${key}`) })))
const toast = useToast()

const list = ref<ApiToken[]>([])
const loading = ref(false)
const error = ref('')

// Create modal state
const showCreate = ref(false)
const createBusy = ref(false)
const passkeyBusy = ref(false)
const createError = ref('')
const passkeyStepUpToken = ref('')
const passkeySupported = isWebAuthnAvailable()
const form = ref({
  name: '',
  // Default least-privilege — sladěno s backendem (CreateTokenAction).
  scope: 'read' as 'read' | 'read_write',
  expires_at: '' as string,
  totp_code: '',
})

// Created token reveal modal
const revealed = ref<CreateTokenResult | null>(null)
const copied = ref(false)
const confirmCopied = ref(false)

const needsTotp = computed(() => auth.user?.totp_enabled === true)
const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)
const needsStepUp = computed(() => needsTotp.value || hasPasskey.value)

async function load() {
  loading.value = true
  error.value = ''
  try {
    list.value = await tokensApi.list()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  form.value = {
    name: '',
    scope: 'read',
    expires_at: '',
    totp_code: '',
  }
  createError.value = ''
  passkeyStepUpToken.value = ''
  showCreate.value = true
}

async function verifyWithPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  createError.value = ''
  passkeyStepUpToken.value = ''
  try {
    const flow = await authApi.passkeyStepUpOptions('api_token.create')
    const credential = await getCredential(flow.public_key)
    passkeyStepUpToken.value = await authApi.passkeyStepUpVerify(
      flow.flow_token,
      'api_token.create',
      credential,
    )
    form.value.totp_code = ''
  } catch (e: any) {
    const ceremonyError = webAuthnErrorKey(e)
    createError.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('api_tokens.passkey_failed')
  } finally {
    passkeyBusy.value = false
  }
}

function closeCreate() {
  if (createBusy.value || passkeyBusy.value) return
  passkeyStepUpToken.value = ''
  form.value.totp_code = ''
  showCreate.value = false
}

async function submitCreate() {
  if (form.value.name.trim() === '') {
    createError.value = t('api_tokens.name_required')
    return
  }
  const hasValidTotp = needsTotp.value && /^\d{6}$/.test(form.value.totp_code)
  if (needsStepUp.value && !passkeyStepUpToken.value && !hasValidTotp) {
    createError.value = t('api_tokens.step_up_required')
    return
  }
  createBusy.value = true
  createError.value = ''
  const proof = passkeyStepUpToken.value
  try {
    const res = await tokensApi.create({
      name: form.value.name.trim(),
      scope: form.value.scope,
      expires_at: form.value.expires_at || null,
      totp_code: !proof && hasValidTotp ? form.value.totp_code : undefined,
      step_up_token: proof || undefined,
    })
    passkeyStepUpToken.value = ''
    showCreate.value = false
    revealed.value = res
    confirmCopied.value = false
    copied.value = false
    await load()
  } catch (e: any) {
    // Server validuje expires_at ještě před spotřebou jednorázového
    // proofu, takže po jiné než step-up chybě zůstává passkey ověření platné —
    // nenutíme uživatele znovu sahat na klíč. TOTP kód je spotřebovaný vždy.
    if (e?.response?.data?.error?.code === 'step_up_proof_invalid') {
      passkeyStepUpToken.value = ''
    }
    form.value.totp_code = ''
    createError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    createBusy.value = false
  }
}

async function copyToken() {
  if (!revealed.value) return
  try {
    await navigator.clipboard.writeText(revealed.value.token)
    copied.value = true
    toast.success(t('api_tokens.copied'))
  } catch {
    /* ignore — user can copy manually */
  }
}

function closeReveal() {
  if (!confirmCopied.value) return
  revealed.value = null
  confirmCopied.value = false
  copied.value = false
}

async function revoke(id: number, name: string) {
  if (!confirm(t('api_tokens.confirm_revoke', { name }))) return
  try {
    await tokensApi.revoke(id)
    toast.success(t('api_tokens.revoked'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function badgeClass(tk: ApiToken): string {
  if (tk.is_revoked) return 'bg-neutral-100 text-neutral-500'
  if (tk.is_expired) return 'bg-warning-50 text-warning-600'
  return 'bg-success-50 text-success-600'
}

function statusLabel(tk: ApiToken): string {
  if (tk.is_revoked) return t('api_tokens.status_revoked')
  if (tk.is_expired) return t('api_tokens.status_expired')
  return t('api_tokens.status_active')
}

function fmtTime(iso: string | null): string {
  if (!iso) return '—'
  return iso.replace('T', ' ').slice(0, 16)
}

onMounted(load)

const tokenColumns = computed<DataColumn[]>(() => [
  { key: 'name', label: t('api_tokens.col_name'), primary: true },
  { key: 'prefix', label: t('api_tokens.col_prefix'), mono: true },
  { key: 'scope', label: t('api_tokens.col_scope') },
  { key: 'last_used', label: t('api_tokens.col_last_used'), hideBelow: 'lg' },
  { key: 'expires', label: t('api_tokens.col_expires'), hideBelow: 'lg' },
  { key: 'status', label: t('api_tokens.col_status') },
])
const tokenKey = (tk: { id: number }) => tk.id
</script>

<template>
  <div class="max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('api.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('api.subtitle') }}</p>
      </div>
      <button
        v-if="tab === 'tokens'"
        @click="openCreate"
        class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0 whitespace-nowrap"
      >
        + {{ t('api_tokens.new') }}
      </button>
    </div>

    <Tabs v-model="tab" :tabs="tabs" :label="t('api.title')" class="mb-4" />

    <ApiEndpoints v-if="tab === 'endpoints'" />
    <template v-else>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 text-sm text-neutral-700">
        <p>
          {{ t('api_tokens.intro') }}
        </p>
      </div>

      <div role="alert" v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 mb-3 text-sm text-danger-500">
        {{ error }}
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <p v-if="!loading && list.length === 0" class="text-center text-sm text-neutral-500 py-8">{{ t('api_tokens.empty') }}</p>
        <DataTable v-else :columns="tokenColumns" :rows="list" :row-key="tokenKey">
          <template #cell-name="{ row: tk }"><span class="font-medium">{{ tk.name }}</span></template>
          <template #cell-prefix="{ row: tk }"><span class="text-xs text-neutral-600">{{ tk.prefix }}…</span></template>
          <template #cell-scope="{ row: tk }">
            <span class="px-2 py-0.5 rounded text-xs font-medium"
              :class="tk.scope === 'read' ? 'bg-neutral-100 text-neutral-600' : 'bg-primary-100 text-primary-700'">
              {{ tk.scope === 'read' ? t('api_tokens.scope_read') : t('api_tokens.scope_read_write') }}
            </span>
          </template>
          <template #cell-last_used="{ row: tk }"><span class="text-neutral-500">{{ fmtTime(tk.last_used_at) }}</span></template>
          <template #cell-expires="{ row: tk }"><span class="text-neutral-500">{{ tk.expires_at ? fmtTime(tk.expires_at) : t('api_tokens.expires_never') }}</span></template>
          <template #cell-status="{ row: tk }"><span class="px-2 py-0.5 rounded text-xs font-medium" :class="badgeClass(tk)">{{ statusLabel(tk) }}</span></template>
          <template #actions="{ row: tk }">
            <IconButton v-if="!tk.is_revoked" icon="ban" show-label variant="danger" :label="t('api_tokens.revoke')" @click="revoke(tk.id, tk.name)" />
          </template>
        </DataTable>
      </div>

      <!-- Create modal -->
    </template>

    <Modal v-if="showCreate" :title="t('api_tokens.new')" width-class="max-w-md" @close="!(createBusy || passkeyBusy) && closeCreate()">
        <div class="space-y-3">
          <label class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('api_tokens.col_name') }}</span>
            <input v-model="form.name" type="text" maxlength="100"
              :placeholder="t('api_tokens.name_placeholder')"
              class="mt-1 w-full h-10 px-3 border border-field rounded-md bg-surface" />
          </label>

          <fieldset class="block text-sm">
            <legend class="text-neutral-700 font-medium mb-1">{{ t('api_tokens.col_scope') }}</legend>
            <div class="space-y-1">
              <label class="flex items-start gap-2">
                <input type="radio" v-model="form.scope" value="read" class="mt-0.5" />
                <span>
                  <strong>{{ t('api_tokens.scope_read') }}</strong>
                  <span class="text-neutral-500"> — {{ t('api_tokens.scope_read_desc') }}</span>
                </span>
              </label>
              <label class="flex items-start gap-2">
                <input type="radio" v-model="form.scope" value="read_write" class="mt-0.5" />
                <span>
                  <strong>{{ t('api_tokens.scope_read_write') }}</strong>
                  <span class="text-neutral-500"> — {{ t('api_tokens.scope_read_write_desc') }}</span>
                </span>
              </label>
            </div>
          </fieldset>

          <label class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('api_tokens.col_expires') }} <span class="text-neutral-400">{{ t('common.optional') }}</span></span>
            <input v-model="form.expires_at" type="date"
              class="mt-1 w-full h-10 px-3 border border-field rounded-md bg-surface" />
          </label>

          <div v-if="hasPasskey" class="rounded-md border border-neutral-200 bg-neutral-50 p-3">
            <p class="text-sm text-neutral-700 mb-2">{{ t('api_tokens.step_up_hint') }}</p>
            <div v-if="passkeyStepUpToken"
              class="text-sm font-medium text-success-600">
              <Icon name="check" class="w-4 h-4 align-[-3px]" /> {{ t('api_tokens.passkey_verified') }}
            </div>
            <button v-else-if="passkeySupported" type="button" @click="verifyWithPasskey" :disabled="passkeyBusy || createBusy"
              class="cursor-pointer h-10 px-4 border border-primary-600 text-primary-700 hover:bg-primary-50 disabled:border-neutral-300 disabled:text-neutral-400 rounded-md font-medium">
              {{ passkeyBusy ? t('auth.passkey_verifying') : t('api_tokens.verify_passkey') }}
            </button>
            <p v-else class="text-sm text-warning-700">{{ t('api_tokens.passkey_unsupported') }}</p>
          </div>

          <label v-if="needsTotp && !passkeyStepUpToken" class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('auth.totp_code') }}</span>
            <input v-model="form.totp_code" type="text" inputmode="numeric" maxlength="6" pattern="\d{6}"
              placeholder="000000"
              class="mt-1 w-full h-10 px-3 border border-field rounded-md bg-surface font-mono text-lg tracking-widest text-center" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('api_tokens.totp_hint') }}</p>
          </label>
        </div>

        <div v-if="createError" role="alert"
          class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ createError }}
        </div>
      <template #footer>
          <button @click="closeCreate" :disabled="createBusy || passkeyBusy"
            class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="submitCreate" :disabled="createBusy || passkeyBusy"
            class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action text-sm font-medium rounded-md">
            {{ createBusy ? '…' : t('api_tokens.create') }}
          </button>
      </template>
    </Modal>

    <!-- Reveal modal — plaintext shown ONCE -->
    <!-- Esc ani klik mimo nezavřou, dokud uživatel nepotvrdí zkopírování (token se ukazuje jen jednou) -->
    <Modal v-if="revealed" :title="t('api_tokens.created_title')" width-class="max-w-lg" @close="confirmCopied && closeReveal()">
        <p class="text-sm text-warning-600 font-medium mb-3"><Icon name="warning" class="w-4 h-4 align-[-3px]" /> {{ t('api_tokens.created_warning') }}</p>

        <div class="bg-neutral-900 text-neutral-100 rounded-md p-3 font-mono text-sm break-all select-all">
          {{ revealed.token }}
        </div>

        <button @click="copyToken"
          class="mt-3 cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm rounded-md">
          <Icon v-if="copied" name="check" class="w-4 h-4 align-[-3px]" /> {{ copied ? t('api_tokens.copied') : t('api_tokens.copy') }}
        </button>

        <label class="mt-4 flex items-start gap-2 text-sm">
          <input type="checkbox" v-model="confirmCopied" class="mt-0.5" />
          <span>{{ t('api_tokens.created_confirm') }}</span>
        </label>

      <template #footer>
          <button @click="closeReveal" :disabled="!confirmCopied"
            class="cursor-pointer h-9 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action text-sm font-medium rounded-md">
            {{ t('common.close') }}
          </button>
      </template>
    </Modal>
  </div>
</template>
