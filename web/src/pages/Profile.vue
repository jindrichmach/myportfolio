<script setup lang="ts">
/**
 * Profil uživatele — jméno, jazyk UI a měna, ve které se zobrazují hodnoty portfolia.
 * Heslo, 2FA a passkeys jsou na /profile/password.
 */
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { settingsApi, type Profile } from '@/api/settings'
import type { DisplayCurrency } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import WeeklyReportCard from '@/components/profile/WeeklyReportCard.vue'
import AlertsCard from '@/components/profile/AlertsCard.vue'

const { t, locale } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const CURRENCIES: DisplayCurrency[] = ['CZK', 'EUR', 'USD']

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
  <div class="max-w-2xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('profile.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('profile.subtitle') }}</p>
    </div>

    <form v-if="form" @submit.prevent="save" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.name') }}</label>
        <input v-model="form.name" required maxlength="120"
               class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }}</label>
        <input :value="form.email" disabled
               class="w-full h-10 px-3 border border-neutral-200 rounded-md bg-neutral-50 text-neutral-500" />
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.language') }}</label>
          <select v-model="form.locale" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option value="cs">Čeština</option>
            <option value="en">English</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('profile.display_currency') }}</label>
          <select v-model="form.display_currency" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('profile.display_currency_hint') }}</p>
        </div>
      </div>
      <div class="flex items-center justify-between gap-3 pt-2">
        <RouterLink to="/profile/password" class="text-sm text-primary-700 hover:text-primary-800 hover:underline">
          {{ t('profile.security_link') }}
        </RouterLink>
        <button type="submit" :disabled="busy"
                class="cursor-pointer px-4 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ busy ? '…' : t('common.save') }}
        </button>
      </div>
    </form>

    <WeeklyReportCard />
    <AlertsCard />
  </div>
</template>
