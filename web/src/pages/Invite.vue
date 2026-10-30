<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { sharesApi, type InviteInfo } from '@/api/shares'
import { apiErrorMessage } from '@/api/errors'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const route = useRoute()
const token = String(route.params.token ?? '')

const info = ref<InviteInfo | null>(null)
const loading = ref(true)
const name = ref('')
const password = ref('')
const passwordConfirm = ref('')
const submitting = ref(false)
const done = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    info.value = await sharesApi.invite(token)
  } catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  } finally {
    loading.value = false
  }
})

const passwordOk = computed(() => password.value.length >= 12)
const passwordMatch = computed(() => password.value === passwordConfirm.value)
const valid = computed(() => name.value.trim() !== '' && passwordOk.value && passwordMatch.value)

async function submit() {
  submitting.value = true
  error.value = ''
  try {
    await sharesApi.acceptInvite(token, { name: name.value.trim(), password: password.value, password_confirm: passwordConfirm.value })
    done.value = true
  } catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  } finally {
    submitting.value = false
  }
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none'
</script>

<template>
  <AppShell :title="t('sharing.invite_title')">
    <div class="w-full max-w-sm">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6">
        <h1 class="text-xl font-semibold mb-1">{{ t('sharing.invite_title') }}</h1>
        <p v-if="loading" class="text-sm text-neutral-500 mt-4">{{ t('common.loading') }}</p>

        <template v-else-if="info && !done">
          <p class="text-sm text-neutral-600 mt-2">
            {{ t('sharing.invite_text', { name: info.owner_name }) }}
            {{ t(`sharing.permissions.${info.permission}`) }}<template v-if="info.scope === 'portfolios'"> · {{ info.portfolios.join(', ') }}</template>
          </p>
          <div v-if="info.account_exists" class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600 mt-4">
            {{ t('sharing.invite_account_exists') }}
            <RouterLink to="/login" class="font-medium underline">{{ t('auth.login') }}</RouterLink>
          </div>
          <form v-else @submit.prevent="submit" class="space-y-4 mt-4">
            <div>
              <label :for="fid('email')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('sharing.email') }}</label>
              <input :id="fid('email')" :value="info.email" disabled :class="inputClass + ' bg-neutral-50'" />
            </div>
            <div>
              <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
              <input :id="fid('name')" v-model="name" required maxlength="120" autocomplete="name" :class="inputClass" />
            </div>
            <div>
              <label :for="fid('password')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password') }}</label>
              <input :id="fid('password')" v-model="password" type="password" required autocomplete="new-password" :class="inputClass" />
              <p class="text-xs text-neutral-500 mt-1" :class="{ 'text-danger-500': password && !passwordOk }">{{ t('auth.min_chars', { n: 12 }) }}</p>
            </div>
            <div>
              <label :for="fid('passwordconfirm')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }}</label>
              <input :id="fid('passwordconfirm')" v-model="passwordConfirm" type="password" required autocomplete="new-password" :class="inputClass" />
              <p v-if="passwordConfirm && !passwordMatch" class="text-xs text-danger-500 mt-1">{{ t('auth.passwords_dont_match') }}</p>
            </div>
            <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
            <button type="submit" :disabled="!valid || submitting" class="cursor-pointer w-full h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
              {{ submitting ? t('common.saving') : t('sharing.invite_accept') }}
            </button>
          </form>
        </template>

        <div v-else-if="done" class="rounded-md bg-primary-50 border border-primary-200 p-4 text-sm text-primary-800 mt-4">
          {{ t('sharing.invite_done') }}
          <RouterLink to="/login" class="block mt-2 font-medium underline">{{ t('auth.login') }}</RouterLink>
        </div>
        <div v-else class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mt-4">{{ error }}</div>
      </div>
    </div>
  </AppShell>
</template>
