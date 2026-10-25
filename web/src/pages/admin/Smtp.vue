<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { smtpApi, type SmtpForm, type SmtpSettingsResponse } from '@/api/admin'
import { apiErrorMessage } from '@/api/errors'
import { formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const data = ref<SmtpSettingsResponse | null>(null)
const form = reactive<SmtpForm & { password: string }>({
  enabled: true, host: '', port: 587, encryption: 'tls', verify_peer: true, auth_enabled: true, username: '',
  from_email: '', from_name: 'MyPortfolio', reply_to_email: '', reply_to_name: '', password: '',
})
const testTo = ref('')
const busy = ref<'save' | 'test' | null>(null)
const error = ref('')
const testResult = ref<{ ok: boolean; message: string } | null>(null)

/** Port podle šifrování, pokud uživatel nechal výchozí hodnotu jiné volby. */
const DEFAULT_PORTS = { ssl: 465, tls: 587, none: 25 } as const
function onEncryption() {
  if ((Object.values(DEFAULT_PORTS) as number[]).includes(form.port)) form.port = DEFAULT_PORTS[form.encryption]
}

function fill(r: SmtpSettingsResponse) {
  data.value = r
  const src = r.settings ?? r.cfg
  Object.assign(form, {
    enabled: r.settings?.enabled ?? true, host: src.host, port: src.port, encryption: src.encryption,
    verify_peer: src.verify_peer, auth_enabled: src.auth_enabled, username: src.username ?? '',
    from_email: src.from_email, from_name: src.from_name, reply_to_email: src.reply_to_email ?? '',
    reply_to_name: src.reply_to_name ?? '', password: '',
  })
}

onMounted(async () => {
  testTo.value = auth.user?.email ?? ''
  try {
    fill(await smtpApi.get())
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  }
})

function payload() {
  return { ...form, username: form.username?.trim() || null, reply_to_email: form.reply_to_email?.trim() || null, reply_to_name: form.reply_to_name?.trim() || null }
}

async function save() {
  busy.value = 'save'
  error.value = ''
  try {
    fill(await smtpApi.save(payload()))
    toast.success(t('smtp.saved'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}

async function test() {
  busy.value = 'test'
  error.value = ''
  testResult.value = null
  try {
    testResult.value = await smtpApi.test({ ...payload(), to: testTo.value.trim() })
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}

const inputClass = 'w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm'
const labelClass = 'block text-sm font-medium text-neutral-700 mb-1'
</script>

<template>
  <div class="max-w-3xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('smtp.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('smtp.subtitle') }}</p>
    </div>

    <div v-if="data" class="mb-4 text-sm rounded-md px-3 py-2"
         :class="data.active_source === 'db' ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">
      {{ data.active_source === 'db' ? t('smtp.source_db') : t('smtp.source_cfg') }}
    </div>

    <form v-if="data" @submit.prevent="save" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-6 gap-3">
        <div class="sm:col-span-3">
          <label :class="labelClass">{{ t('smtp.host') }}</label>
          <input v-model="form.host" required placeholder="smtp.example.com" autocomplete="off" :class="inputClass" />
        </div>
        <div class="sm:col-span-2">
          <label :class="labelClass">{{ t('smtp.encryption') }}</label>
          <select v-model="form.encryption" :class="inputClass" @change="onEncryption">
            <option value="tls">STARTTLS</option>
            <option value="ssl">SSL/TLS</option>
            <option value="none">{{ t('smtp.encryption_none') }}</option>
          </select>
        </div>
        <div>
          <label :class="labelClass">{{ t('smtp.port') }}</label>
          <input v-model.number="form.port" type="number" min="1" max="65535" required :class="inputClass" />
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.auth_enabled" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
        {{ t('smtp.auth_enabled') }}
      </label>
      <div v-if="form.auth_enabled" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label :class="labelClass">{{ t('smtp.username') }}</label>
          <input v-model="form.username" required autocomplete="off" :class="inputClass" />
        </div>
        <div>
          <label :class="labelClass">{{ t('smtp.password') }}</label>
          <input v-model="form.password" type="password" autocomplete="new-password"
                 :placeholder="data.settings?.has_password ? t('smtp.password_keep') : ''" :class="inputClass" />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-neutral-200 pt-4">
        <div>
          <label :class="labelClass">{{ t('smtp.from_email') }}</label>
          <input v-model="form.from_email" type="email" required :class="inputClass" />
        </div>
        <div>
          <label :class="labelClass">{{ t('smtp.from_name') }}</label>
          <input v-model="form.from_name" maxlength="120" :class="inputClass" />
        </div>
        <div>
          <label :class="labelClass">{{ t('smtp.reply_to_email') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
          <input v-model="form.reply_to_email" type="email" :class="inputClass" />
        </div>
        <div>
          <label :class="labelClass">{{ t('smtp.reply_to_name') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
          <input v-model="form.reply_to_name" maxlength="120" :class="inputClass" />
        </div>
      </div>

      <div class="space-y-2 border-t border-neutral-200 pt-4">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.verify_peer" type="checkbox" :disabled="form.encryption === 'none'" class="rounded border-neutral-300 text-primary-700" />
          {{ t('smtp.verify_peer') }}
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
          {{ t('smtp.enabled') }}
        </label>
        <p class="text-xs text-neutral-500 ml-6">{{ t('smtp.enabled_hint') }}</p>
      </div>

      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>

      <div class="flex justify-end">
        <button type="submit" :disabled="busy !== null"
                class="cursor-pointer px-4 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ busy === 'save' ? '…' : t('common.save') }}
        </button>
      </div>
    </form>

    <!-- Test -->
    <section v-if="data" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mt-6 space-y-3">
      <div>
        <h2 class="text-lg font-semibold">{{ t('smtp.test_title') }}</h2>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('smtp.test_hint') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <input v-model="testTo" type="email" :placeholder="t('smtp.test_to')" class="flex-1 min-w-56 h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        <button type="button" :disabled="busy !== null || !testTo.trim()" @click="test"
                class="cursor-pointer px-4 h-10 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ busy === 'test' ? '…' : t('smtp.test_send') }}
        </button>
      </div>
      <div v-if="testResult" class="text-sm rounded-md px-3 py-2 break-all"
           :class="testResult.ok ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
        {{ testResult.ok ? t('smtp.test_ok', { to: testTo }) : t('smtp.test_failed') }}
        <span class="block text-xs mt-1 font-mono text-neutral-600">{{ testResult.message }}</span>
      </div>
      <p v-else-if="data.settings?.last_test_at" class="text-xs"
         :class="data.settings.last_test_status === 'ok' ? 'text-neutral-500' : 'text-danger-500'">
        {{ t('smtp.last_test', { at: formatDateTime(data.settings.last_test_at) }) }} — {{ data.settings.last_test_message }}
      </p>
    </section>
  </div>
</template>
