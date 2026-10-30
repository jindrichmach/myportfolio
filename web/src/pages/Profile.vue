<script setup lang="ts">
/**
 * Profil uživatele po záložkách: Účet (jméno, jazyk UI, měna zobrazení, barevné schéma), zabezpečení (heslo, 2FA,
 * přístupové klíče, zámek aplikace — components/profile/SecuritySettings.vue), Reporty e-mailem a Upozornění.
 */
import { computed, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type Profile } from '@/api/settings'
import type { DisplayCurrency } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import WeeklyReportCard from '@/components/profile/WeeklyReportCard.vue'
import AlertsCard from '@/components/profile/AlertsCard.vue'
import SecuritySettings from '@/components/profile/SecuritySettings.vue'
import ColorSchemePicker from '@/components/ColorSchemePicker.vue'
import { DEFAULT_COLOR_SCHEME } from '@/theme/colorSchemes'
import Tabs, { type TabItem } from '@/components/ui/Tabs.vue'
import { useTabQuery } from '@/composables/useTabQuery'
import { useFieldId } from '@/composables/useFieldId'

const { t, locale } = useI18n()
const fid = useFieldId()
const toast = useToast()
const auth = useAuthStore()

const CURRENCIES: DisplayCurrency[] = ['CZK', 'EUR', 'USD']

const TAB_KEYS = ['account', 'password', 'totp', 'passkeys', 'session-lock', 'reports', 'alerts'] as const
type ProfileTab = (typeof TAB_KEYS)[number]
const tab = useTabQuery<ProfileTab>(TAB_KEYS, 'account')
const totpOn = ref(false)
const tabs = computed<TabItem<ProfileTab>[]>(() => [
  { key: 'account', label: t('profile.tab_account') },
  { key: 'password', label: t('auth.change_password_title') },
  { key: 'totp', label: t('auth.totp_tab'), badge: totpOn.value ? t('auth.totp_badge_on') : null },
  { key: 'passkeys', label: t('passkeys.title') },
  { key: 'session-lock', label: t('session_lock.preference_tab') },
  { key: 'reports', label: t('profile.tab_reports') },
  { key: 'alerts', label: t('profile.tab_alerts') },
])

const form = ref<Profile | null>(null)
const busy = ref(false)

onMounted(async () => {
  try {
    form.value = await settingsApi.profile()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
})

async function save() {
  if (!form.value || busy.value) return
  busy.value = true
  try {
    form.value = await settingsApi.updateProfile({
      name: form.value.name.trim(),
      locale: form.value.locale,
      display_currency: form.value.display_currency,
      color_scheme: form.value.color_scheme,
    })
    locale.value = form.value.locale
    localStorage.setItem('locale', form.value.locale)
    await auth.refresh()
    toast.success(t('profile.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('profile.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('profile.subtitle') }}</p>
    </div>

    <Tabs v-model="tab" :tabs="tabs" :label="t('profile.title')" />

    <div class="max-w-3xl">
      <form v-if="tab === 'account' && form" @submit.prevent="save" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
        <div>
          <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.name') }}</label>
          <input :id="fid('name')" v-model="form.name" required maxlength="120"
                 class="w-full h-10 px-3 border border-field rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
        </div>
        <div>
          <label :for="fid('email')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }}</label>
          <input :id="fid('email')" :value="form.email" disabled
                 class="w-full h-10 px-3 border border-neutral-200 rounded-md bg-neutral-50 text-neutral-500" />
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label :for="fid('locale')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.language') }}</label>
            <select :id="fid('locale')" v-model="form.locale" class="w-full h-10 px-3 border border-field rounded-md bg-surface">
              <option value="cs">Čeština</option>
              <option value="en">English</option>
            </select>
          </div>
          <div>
            <label :for="fid('display_currency')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('profile.display_currency') }}</label>
            <select :id="fid('display_currency')" v-model="form.display_currency" class="w-full h-10 px-3 border border-field rounded-md bg-surface">
              <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('profile.display_currency_hint') }}</p>
          </div>
        </div>
        <div>
          <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('appearance.scheme') }}</span>
          <ColorSchemePicker v-model="form.color_scheme" name="profile-scheme"
                             :inherit="auth.setupStatus?.color_scheme ?? DEFAULT_COLOR_SCHEME" />
        </div>
        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="submit" :disabled="busy"
                  class="cursor-pointer px-4 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
            {{ busy ? '…' : t('common.save') }}
          </button>
        </div>
      </form>

      <WeeklyReportCard v-else-if="tab === 'reports'" />
      <AlertsCard v-else-if="tab === 'alerts'" />
      <!-- Vždy připojené (hlásí stav TOTP pro odznak), mimo bezpečnostní záložky nic nevykreslí -->
      <SecuritySettings :tab="tab" class="max-w-2xl" @totp-status="totpOn = $event" />
    </div>
  </div>
</template>
