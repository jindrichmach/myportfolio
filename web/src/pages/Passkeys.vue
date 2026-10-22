<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi, type PasskeyCredential } from '@/api/auth'
import { createCredential, getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import RecoveryCodes from '@/components/security/RecoveryCodes.vue'
import IconButton from '@/components/ui/IconButton.vue'

const { t } = useI18n()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()
const list = ref<PasskeyCredential[]>([])
const label = ref('')
const currentPassword = ref('')
const totpCode = ref('')
const busy = ref(false)
const error = ref('')
const passkeySupported = isWebAuthnAvailable()

// Politika MFA ze serveru: `allowed_mfa_methods` říká, ČÍM se smí ověřovat, a
// `require_mfa`, jestli je silný faktor povinný. Stránka to dřív ignorovala,
// takže nabízela operace, které API vzápětí odmítlo (403 mfa_method_not_allowed,
// 400 „Nepovolený účel ověření", 409 last_mfa_factor) — a u posledního faktoru
// až POTÉ, co uživatel prošel celou ceremonií nebo spotřeboval TOTP kód.
const passkeyAllowed = computed(() => auth.allowedMfaMethods.includes('passkey'))
const totpAllowed = computed(() => auth.allowedMfaMethods.includes('totp'))

const hasTotp = computed(() => auth.user?.totp_enabled === true)
// TOTP jde k ověření použít, jen když ho uživatel má A politika ho připouští.
const totpUsable = computed(() => hasTotp.value && totpAllowed.value)
const totpCodeValid = computed(() => /^\d{6}$/.test(totpCode.value))
// Step-up umí jen passkey nebo TOTP. Dokud uživatel žádnou passkey nemá, je TOTP
// jediný použitelný faktor — tedy povinný, ne „volitelná alternativa". Jakmile
// nějakou passkey má, může se ověřit jí a kód je skutečně volitelný.
const totpRequired = computed(() => totpUsable.value && list.value.length === 0)

// Kolik povolených silných faktorů uživateli zbyde po odebrání jednoho klíče.
// Při povinném MFA server poslední faktor odebrat nedovolí (409 last_mfa_factor),
// takže tlačítko zamkneme rovnou — dřív se to uživatel dozvěděl až po step-upu.
const revokeBlocked = computed(() =>
  auth.requireMfa
  && list.value.length === 1
  && !(totpAllowed.value && hasTotp.value)
)

/** Čím se dá potvrdit odebrání klíče. Prázdné = ničím, operace je zablokovaná. */
const stepUpMethods = computed<Array<'passkey' | 'totp'>>(() => {
  const methods: Array<'passkey' | 'totp'> = []
  if (passkeyAllowed.value && passkeySupported && list.value.length > 0) methods.push('passkey')
  if (totpUsable.value) methods.push('totp')
  return methods
})

async function load() {
  list.value = await authApi.passkeys()
}

async function stepUp(operation: string): Promise<string> {
  // TOTP má přednost, jen když je kód opravdu vyplněný — jinak by zapomenutý kód
  // z minulého pokusu tiše přepnul metodu ověření a operace by skončila hláškou
  // „Neplatný TOTP kód" tam, kde ji uživatel chtěl potvrdit klíčem.
  if (totpCodeValid.value && totpUsable.value) {
    return authApi.totpStepUp(operation, totpCode.value)
  }
  if (totpRequired.value || !stepUpMethods.value.includes('passkey')) {
    throw new Error('totp_code_required')
  }
  const flow = await authApi.passkeyStepUpOptions(operation)
  const credential = await getCredential(flow.public_key)
  return authApi.passkeyStepUpVerify(flow.flow_token, operation, credential)
}

async function add() {
  if (!label.value.trim() || !passkeySupported) return
  if (totpRequired.value && !totpCodeValid.value) {
    error.value = t('passkeys.totp_required_error')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const authorization: { current_password?: string; step_up_token?: string } = {}
    if (list.value.length > 0 || auth.user?.totp_enabled) {
      authorization.step_up_token = await stepUp('passkey.register')
    } else {
      authorization.current_password = currentPassword.value
    }
    const flow = await authApi.passkeyRegisterOptions(authorization)
    const credential = await createCredential(flow.public_key)
    const registered = await authApi.passkeyRegisterVerify(flow.flow_token, credential, label.value.trim())
    if (registered.csrf_token) {
      auth.setSessionCsrfToken(registered.csrf_token)
    }
    await auth.refresh()
    await sessionSecurity.refresh({ force: true })
    label.value = ''
    currentPassword.value = ''
    totpCode.value = ''
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    const ceremonyError = webAuthnErrorKey(e)
    error.value = e?.message === 'totp_code_required' || code === 'passkey_unavailable'
      ? t('passkeys.totp_required_error')
      : ceremonyError !== null
        ? t(ceremonyError)
        : e?.response?.data?.error?.message || t('passkeys.operation_failed')
  } finally {
    busy.value = false
  }
}

async function rename(item: PasskeyCredential) {
  const next = window.prompt(t('passkeys.rename_prompt'), item.label)?.trim()
  if (!next || next === item.label) return
  try {
    await authApi.passkeyRename(item.id, next)
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('passkeys.operation_failed')
  }
}

async function revoke(item: PasskeyCredential) {
  if (revokeBlocked.value || stepUpMethods.value.length === 0) return
  if (!window.confirm(t('passkeys.revoke_confirm', { label: item.label }))) return
  busy.value = true
  error.value = ''
  try {
    const proof = await stepUp(`passkey.revoke:${item.id}`)
    await authApi.passkeyRevoke(item.id, proof)
    totpCode.value = ''
    await auth.refresh()
    await sessionSecurity.refresh({ force: true })
    await load()
  } catch (e: any) {
    // Zrušený nebo vypršelý systémový dialog není „operace se nepodařila" —
    // uživatel typicky klíč nemá po ruce a potřebuje vědět, že ho lze odebrat
    // i TOTP kódem. Bez tohohle mapování končila celá cesta u generické hlášky.
    const ceremonyError = webAuthnErrorKey(e)
    if (e?.message === 'totp_code_required') {
      error.value = t('passkeys.revoke_needs_totp')
    } else if (ceremonyError !== null) {
      error.value = totpUsable.value
        ? `${t(ceremonyError)} ${t('passkeys.revoke_totp_fallback')}`
        : t(ceremonyError)
    } else {
      error.value = e?.response?.data?.error?.message || t('passkeys.operation_failed')
    }
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void load()
  // Politika chodí z /me — po tvrdém reloadu stránky nemusí být ve storu ještě
  // načtená a stránka by se rozhodovala podle prázdného seznamu metod.
  if (auth.allowedMfaMethods.length === 0) void auth.refresh()
})
</script>

<template>
  <div>
    <p class="text-sm text-neutral-500 mb-5">{{ t('passkeys.subtitle') }}</p>
    <p v-if="!passkeySupported"
       class="mb-5 rounded-md bg-warning-50 border border-warning-300 px-3 py-2 text-sm text-warning-700">
      {{ t('passkeys.unsupported') }}
    </p>

    <p v-if="!passkeyAllowed"
       class="mb-5 rounded-md bg-warning-50 border border-warning-300 px-3 py-2 text-sm text-warning-700">
      {{ t('passkeys.method_not_allowed') }}
    </p>

    <div v-if="passkeyAllowed" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3 mb-5">
      <div>
        <label for="passkey-label" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('passkeys.credential_label') }} *
        </label>
        <input id="passkey-label" v-model="label" type="text" maxlength="100"
               autocomplete="off" required :placeholder="t('passkeys.label_placeholder')"
               class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
      </div>
      <div v-if="list.length === 0 && !auth.user?.totp_enabled">
        <label for="passkey-current-password" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('auth.current_password') }} *
        </label>
        <input id="passkey-current-password" v-model="currentPassword"
               type="password" autocomplete="current-password" required
               class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
      </div>
      <div v-if="totpUsable">
        <label for="passkey-totp" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ totpRequired ? t('passkeys.totp_label_required') : t('passkeys.totp_label') }}
        </label>
        <input id="passkey-totp" v-model="totpCode" type="text" inputmode="numeric"
               autocomplete="one-time-code" maxlength="6" pattern="\d{6}"
               :required="totpRequired"
               :placeholder="totpRequired ? t('passkeys.totp_required_placeholder') : t('passkeys.totp_optional')"
               class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
        <p v-if="totpRequired" class="text-xs text-neutral-500 mt-1">
          {{ t('passkeys.totp_required_hint') }}
        </p>
      </div>
      <button type="button" @click="add"
              :disabled="busy || !label.trim() || !passkeySupported || (totpRequired && !totpCodeValid)"
              class="h-10 px-4 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action rounded-md font-medium">
        {{ t('passkeys.add') }}
      </button>
      <p class="text-xs text-neutral-500">{{ t('passkeys.fresh_auth_hint') }}</p>
    </div>

    <p v-if="error" class="mb-4 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    <div class="space-y-3">
      <div v-for="item in list" :key="item.id"
           class="bg-surface border border-neutral-200 rounded-lg p-4 flex items-center justify-between gap-4">
        <div>
          <div class="font-medium">{{ item.label }}</div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('passkeys.created') }} {{ item.created_at?.replace('T', ' ').slice(0, 16) || '—' }}
            · {{ t('passkeys.last_used') }} {{ item.last_used_at?.replace('T', ' ').slice(0, 16) || '—' }}
          </div>
        </div>
        <div class="inline-flex gap-1 shrink-0">
          <IconButton icon="edit" :label="t('passkeys.rename')" @click="rename(item)" />
          <IconButton icon="trash" variant="danger" :label="t('passkeys.revoke')"
                      :disabled="busy || revokeBlocked || stepUpMethods.length === 0"
                      :tooltip="revokeBlocked ? t('passkeys.revoke_last_factor') : undefined" @click="revoke(item)" />
        </div>
      </div>

      <!-- Čím se odebrání potvrzuje. Bez tohohle uživatel netušil, že po něm
           aplikace bude chtít step-up, ani že TOTP kód z pole nahoře je plnohodnotná
           alternativa, když klíč zrovna nemá po ruce. -->
      <p v-if="list.length > 0" class="text-xs text-neutral-500">
        <template v-if="revokeBlocked">{{ t('passkeys.revoke_last_factor') }}</template>
        <template v-else-if="stepUpMethods.length === 0">{{ t('passkeys.revoke_no_method') }}</template>
        <template v-else-if="stepUpMethods.includes('totp')">{{ t('passkeys.revoke_hint_totp') }}</template>
        <template v-else>{{ t('passkeys.revoke_hint_passkey') }}</template>
      </p>

      <p v-if="list.length === 0" class="text-sm text-neutral-500 text-center py-8">{{ t('passkeys.empty') }}</p>
    </div>

    <!-- Poslední cesta zpátky, když faktor zmizí i s telefonem. Patří sem, ne do
         zvláštní záložky: kdo řeší klíče, řeší i to, co dělat po jejich ztrátě. -->
    <RecoveryCodes class="mt-5" />
  </div>
</template>
