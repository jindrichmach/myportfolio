<script setup lang="ts">
/**
 * Bezpečnostní záložky profilu (záložky řídí pages/Profile.vue přes `tab`):
 *   - Heslo  (změna hesla, self-service)
 *   - 2FA    (TOTP setup, status, aktivace)
 *   - Passkeys (registrace a správa přístupových klíčů)
 *   - Zámek aplikace (osobní interval nečinnosti)
 * Pro jiné záložky nevykreslí nic; stav TOTP hlásí rodiči kvůli odznaku v záložkách.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi, type SessionLockPreference, type TotpSetup } from '@/api/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import Passkeys from '@/pages/Passkeys.vue'
import Icon from '@/components/ui/Icon.vue'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const toast = useToast()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()

const props = defineProps<{ tab: string }>()
const emit = defineEmits<{ 'totp-status': [enabled: boolean] }>()

// ── Heslo ─────────────────────────────────────────────────────────────
const MIN_LEN = 12
const MAX_LEN = 128
const current = ref('')
const next = ref('')
const confirm = ref('')
const showCurrent = ref(false)
const showNext = ref(false)
const pwBusy = ref(false)
const pwError = ref('')
const nextLenOk = computed(() => next.value.length >= MIN_LEN && next.value.length <= MAX_LEN)
const matchOk = computed(() => next.value !== '' && next.value === confirm.value)
const canSubmitPw = computed(() =>
  current.value !== '' && nextLenOk.value && matchOk.value && !pwBusy.value
)

async function submitPw() {
  if (!canSubmitPw.value) return
  pwError.value = ''
  pwBusy.value = true
  try {
    await authApi.changePassword(current.value, next.value)
    toast.success(t('auth.password_changed'))
    current.value = ''
    next.value = ''
    confirm.value = ''
  } catch (e: any) {
    pwError.value = apiErrorMessage(e, t('auth.password_change_failed'))
  } finally {
    pwBusy.value = false
  }
}

// ── TOTP / 2FA ────────────────────────────────────────────────────────
const totpStatus = ref<{ enabled: boolean } | null>(null)
const totpSetup = ref<TotpSetup | null>(null)
const totpCode = ref('')
const totpBusy = ref(false)
const totpError = ref('')

async function loadTotpStatus() {
  try {
    totpStatus.value = await authApi.totpStatus()
    emit('totp-status', totpStatus.value.enabled)
  } catch (e: any) {
    totpError.value = apiErrorMessage(e, t('common.error'))
  }
}

async function startTotpSetup() {
  totpBusy.value = true
  totpError.value = ''
  try {
    totpSetup.value = await authApi.totpSetup()
  } catch (e: any) {
    totpError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    totpBusy.value = false
  }
}

async function activateTotp() {
  if (!/^\d{6}$/.test(totpCode.value)) {
    totpError.value = t('auth.totp_invalid')
    return
  }
  totpBusy.value = true
  totpError.value = ''
  try {
    await authApi.totpEnable(totpCode.value)
    toast.success(t('auth.totp_enabled_done'))
    totpSetup.value = null
    totpCode.value = ''
    await loadTotpStatus()
  } catch (e: any) {
    totpError.value = e?.response?.data?.error?.message || t('auth.totp_invalid')
  } finally {
    totpBusy.value = false
  }
}

function cancelTotpSetup() {
  totpSetup.value = null
  totpCode.value = ''
  totpError.value = ''
}

// ── Automatický zámek aplikace ──────────────────────────────────────
type LockMode = 'admin' | 'custom'
const lockPreference = ref<SessionLockPreference | null>(null)
const lockMode = ref<LockMode>('admin')
const lockMinutes = ref(15)
const lockBusy = ref(false)
const lockError = ref('')
const lockMaximum = computed(() => lockPreference.value?.maximum_lock_after_minutes ?? 1440)
const lockMinutesValid = computed(() =>
  Number.isInteger(lockMinutes.value)
  && lockMinutes.value >= 1
  && lockMinutes.value <= lockMaximum.value
)
const hasPasskey = computed(() => (auth.user?.passkey_count ?? 0) > 0)
// Vlastní interval server bez použitelné passkey odmítne (zamčenou session by
// nešlo odemknout) — netlačíme uživatele do 400, tlačítko rovnou zůstane vypnuté.
const canSaveLock = computed(() =>
  !lockBusy.value
  && (lockMode.value === 'admin' || (lockMinutesValid.value && hasPasskey.value))
)
const draftLockEnabled = computed(() =>
  lockMode.value === 'admin'
    ? (lockPreference.value?.admin_lock_after_minutes ?? 0) > 0
    : lockMinutesValid.value
)

function applyLockPreference(preference: SessionLockPreference) {
  lockPreference.value = preference
  lockMode.value = preference.user_lock_after_minutes === null ? 'admin' : 'custom'
  lockMinutes.value = preference.user_lock_after_minutes
    ?? Math.min(15, preference.maximum_lock_after_minutes)
}

async function loadLockPreference() {
  if (lockBusy.value) return
  lockBusy.value = true
  lockError.value = ''
  try {
    applyLockPreference(await authApi.sessionLockPreference())
  } catch (e: any) {
    lockError.value = apiErrorMessage(e, t('session_lock.preference_load_failed'))
  } finally {
    lockBusy.value = false
  }
}

async function saveLockPreference() {
  if (!canSaveLock.value) return
  lockBusy.value = true
  lockError.value = ''
  try {
    const result = await authApi.updateSessionLockPreference(
      lockMode.value === 'admin' ? null : lockMinutes.value,
    )
    applyLockPreference(result)
    sessionSecurity.apply(result.session)
    toast.success(t('session_lock.preference_saved'))
  } catch (e: any) {
    lockError.value = apiErrorMessage(e, t('session_lock.preference_save_failed'))
  } finally {
    lockBusy.value = false
  }
}

watch(() => props.tab, next => {
  if (next === 'session-lock' && lockPreference.value === null) {
    void loadLockPreference()
  }
})

onMounted(() => {
  loadTotpStatus()
  if (props.tab === 'session-lock') {
    void loadLockPreference()
  }
})
</script>

<template>
  <div>
    <!-- ── Heslo ── -->
    <form v-if="tab === 'password'" @submit.prevent="submitPw"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div>
        <label :for="fid('current')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.current_password') }} *</label>
        <div class="flex gap-2">
          <input :id="fid('current')" v-model="current" :type="showCurrent ? 'text' : 'password'"
                 autocomplete="current-password" required
                 class="flex-1 h-10 px-3 border border-field rounded-md text-sm" />
          <button type="button" @click="showCurrent = !showCurrent" :aria-label="showCurrent ? t('auth.hide_password') : t('auth.show_password')"
                  :title="showCurrent ? t('auth.hide_password') : t('auth.show_password')"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            <Icon :name="showCurrent ? 'eye-off' : 'eye'" class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div>
        <label :for="fid('next')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password') }} *</label>
        <div class="flex gap-2">
          <input :id="fid('next')" v-model="next" :type="showNext ? 'text' : 'password'"
                 autocomplete="new-password" :minlength="MIN_LEN" :maxlength="MAX_LEN" required
                 class="flex-1 h-10 px-3 border rounded-md text-sm"
                 :class="next === '' ? 'border-field' : nextLenOk ? 'border-success-500/40' : 'border-warning-500/40'" />
          <button type="button" @click="showNext = !showNext" :aria-label="showNext ? t('auth.hide_password') : t('auth.show_password')"
                  :title="showNext ? t('auth.hide_password') : t('auth.show_password')"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            <Icon :name="showNext ? 'eye-off' : 'eye'" class="w-4 h-4" />
          </button>
        </div>
        <p class="text-xs mt-1"
           :class="next === '' ? 'text-neutral-500' : nextLenOk ? 'text-success-600' : 'text-warning-600'">
          {{ t('auth.password_min_hint', { n: MIN_LEN }) }}
          <span v-if="next !== ''">— {{ next.length }}/{{ MAX_LEN }}</span>
        </p>
      </div>

      <div>
        <label :for="fid('confirm')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }} *</label>
        <input :id="fid('confirm')" v-model="confirm" :type="showNext ? 'text' : 'password'"
               autocomplete="new-password" required
               class="w-full h-10 px-3 border rounded-md text-sm"
               :class="confirm === '' ? 'border-field' : matchOk ? 'border-success-500/40' : 'border-danger-500/40'" />
        <p v-if="confirm !== '' && !matchOk" class="text-xs text-danger-500 mt-1">
          {{ t('auth.password_mismatch') }}
        </p>
      </div>

      <div role="alert" v-if="pwError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ pwError }}
      </div>

      <div class="flex items-center justify-end pt-2 border-t border-neutral-100">
        <button type="submit" :disabled="!canSubmitPw"
                class="cursor-pointer h-10 px-5 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ pwBusy ? t('common.saving') : t('auth.change_password_submit') }}
        </button>
      </div>

      <p class="text-xs text-neutral-500 pt-2">
        ℹ {{ t('auth.password_change_note') }}
      </p>
    </form>

    <!-- ── 2FA / TOTP ── -->
    <div v-else-if="tab === 'totp'" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div v-if="totpStatus">
        <div v-if="totpStatus.enabled" class="flex items-center gap-2 text-success-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <span class="font-medium">{{ t('auth.totp_status_enabled') }}</span>
        </div>
        <div v-else class="flex items-center gap-2 text-neutral-500">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
          <span>{{ t('auth.totp_status_disabled') }}</span>
        </div>
      </div>

      <p v-if="totpStatus?.enabled" class="text-xs text-neutral-500">
        {{ t('auth.totp_disable_hint') }}
      </p>

      <button v-if="totpStatus && !totpStatus.enabled && !totpSetup"
              @click="startTotpSetup" :disabled="totpBusy"
              class="cursor-pointer h-10 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
        {{ totpBusy ? '…' : t('auth.totp_setup_btn') }}
      </button>

      <div v-if="totpSetup" class="space-y-4 pt-2 border-t border-neutral-200">
        <p class="text-sm text-neutral-700">{{ t('auth.totp_setup_step1') }}</p>
        <div class="flex justify-center bg-neutral-50 rounded-md p-4">
          <img :src="totpSetup.qr_data_uri" :alt="totpSetup.uri" class="border border-neutral-200 rounded" />
        </div>

        <details class="text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('auth.totp_setup_step1_alt') }}</summary>
          <code class="block mt-2 p-2 bg-neutral-100 rounded font-mono text-xs break-all select-all">{{ totpSetup.secret }}</code>
        </details>

        <div class="pt-2">
          <p class="text-sm text-neutral-700 mb-2">{{ t('auth.totp_setup_step2') }}</p>
          <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6"
                 pattern="\d{6}" placeholder="000000" @keydown.enter="activateTotp"
                 class="w-full h-10 px-3 border border-field rounded-md font-mono text-lg tracking-widest text-center" />
        </div>

        <div role="alert" v-if="totpError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ totpError }}
        </div>

        <div class="flex justify-end gap-2">
          <button @click="cancelTotpSetup"
                  class="cursor-pointer h-10 px-4 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="activateTotp" :disabled="totpBusy || totpCode.length !== 6"
                  class="cursor-pointer h-10 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
            {{ totpBusy ? '…' : t('auth.totp_enable_btn') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Passkeys ── -->
    <Passkeys v-else-if="tab === 'passkeys'" />

    <!-- ── Automatický zámek ── -->
    <form v-else-if="tab === 'session-lock'" @submit.prevent="saveLockPreference"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div>
        <h2 class="font-medium text-neutral-900">{{ t('session_lock.preference_title') }}</h2>
        <p class="text-sm text-neutral-500 mt-1">{{ t('session_lock.preference_description') }}</p>
      </div>

      <div v-if="!lockPreference" class="rounded-md bg-neutral-50 border border-neutral-200 p-4 text-sm">
        <p :class="lockError ? 'text-danger-500' : 'text-neutral-500'">
          {{ lockBusy ? t('common.loading') : lockError }}
        </p>
        <button v-if="!lockBusy" type="button" @click="loadLockPreference"
                class="cursor-pointer mt-3 h-9 px-3 border border-neutral-300 rounded-md hover:bg-neutral-100">
          {{ t('session_lock.preference_retry') }}
        </button>
      </div>

      <template v-else>
        <label class="flex items-start gap-3 rounded-md border p-4 cursor-pointer transition"
               :class="lockMode === 'admin' ? 'border-primary-500 bg-primary-50/40' : 'border-neutral-200'">
          <input v-model="lockMode" type="radio" value="admin" class="mt-1" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">
              {{ t('session_lock.preference_use_admin') }}
            </span>
            <span v-if="lockPreference.admin_lock_after_minutes > 0"
                  class="block text-xs text-neutral-500 mt-1">
              {{ t('session_lock.preference_admin_limit', { n: lockPreference.admin_lock_after_minutes }) }}
            </span>
            <span v-else class="block text-xs text-neutral-500 mt-1">
              {{ t('session_lock.preference_admin_disabled') }}
            </span>
          </span>
        </label>

        <label class="flex items-start gap-3 rounded-md border p-4 cursor-pointer transition"
               :class="lockMode === 'custom' ? 'border-primary-500 bg-primary-50/40' : 'border-neutral-200'">
          <input v-model="lockMode" type="radio" value="custom" class="mt-1" />
          <span class="flex-1">
            <span class="block text-sm font-medium text-neutral-800">
              {{ t('session_lock.preference_custom') }}
            </span>
            <span class="flex items-center gap-2 mt-2">
              <input v-model.number="lockMinutes" type="number" min="1"
                     :max="lockMaximum" :disabled="lockMode !== 'custom'"
                     class="w-28 h-10 px-3 border border-field rounded-md text-sm disabled:bg-neutral-100" />
              <span class="text-sm text-neutral-600">{{ t('session_lock.preference_minutes') }}</span>
            </span>
            <span class="block text-xs mt-1"
                  :class="lockMode === 'custom' && !lockMinutesValid ? 'text-danger-500' : 'text-neutral-500'">
              {{ t('session_lock.preference_range', { n: lockMaximum }) }}
            </span>
          </span>
        </label>

        <div class="rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-sm text-neutral-600">
          <span v-if="lockPreference.effective_lock_after_minutes > 0">
            {{ t('session_lock.preference_effective', { n: lockPreference.effective_lock_after_minutes }) }}
          </span>
          <span v-else>{{ t('session_lock.preference_effective_disabled') }}</span>
        </div>

        <div v-if="!hasPasskey && draftLockEnabled"
             class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
          {{ t('session_lock.preference_no_passkey') }}
        </div>

        <div role="alert" v-if="lockError"
             class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ lockError }}
        </div>

        <div class="flex justify-end pt-2 border-t border-neutral-100">
          <button type="submit" :disabled="!canSaveLock"
                  class="cursor-pointer h-10 px-5 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
            {{ lockBusy ? t('common.saving') : t('session_lock.preference_save') }}
          </button>
        </div>
      </template>
    </form>
  </div>
</template>
