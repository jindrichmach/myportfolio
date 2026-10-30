<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { useAuthStore } from '@/stores/auth'
import { authApi, type SetupPayload, type SetupSmtp } from '@/api/auth'
import { useFieldId } from '@/composables/useFieldId'

/**
 * Úvodní průvodce: admin a předvolby → zabezpečení → e-mail (SMTP, volitelně)
 * → ukázková data → shrnutí. Vše se odešle najednou na konci (POST /api/auth/setup); SMTP jde předem
 * vyzkoušet (POST /api/auth/setup/smtp-test, jen dokud neexistuje žádný uživatel).
 */
const { t, locale } = useI18n()
const fid = useFieldId()
const router = useRouter()
const auth = useAuthStore()

const STEPS = ['admin', 'security', 'email', 'data', 'summary'] as const
type Step = (typeof STEPS)[number] | 'done'
const step = ref<Step>('admin')
const stepIndex = computed(() => STEPS.indexOf(step.value as (typeof STEPS)[number]))

const admin = reactive({ name: '', email: '', password: '', password_confirm: '', locale: (locale.value === 'en' ? 'en' : 'cs') as 'cs' | 'en', display_currency: 'CZK' as 'CZK' | 'EUR' | 'USD' })
const requireMfa = ref(false)
const useSmtp = ref(false)
const smtp = reactive<SetupSmtp>({ host: '', port: 587, encryption: 'tls', verify_peer: true, auth_enabled: true, username: '', password: '', from_email: '', from_name: 'MyPortfolio' })
const demoData = ref(false)

const submitting = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const smtpTest = ref<{ ok: boolean; message: string } | null>(null)
const smtpTesting = ref(false)
const result = ref<{ demo: { ok: boolean; transactions?: number; backfill_started?: boolean; message?: string } | null } | null>(null)

const passwordOk = computed(() => admin.password.length >= 12)
const passwordMatch = computed(() => admin.password === admin.password_confirm)
const adminValid = computed(() => admin.name.trim().length > 0 && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(admin.email) && passwordOk.value && passwordMatch.value)
const smtpValid = computed(() => !useSmtp.value || (smtp.host.trim() !== '' && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(smtp.from_email)
  && (!smtp.auth_enabled || (smtp.username.trim() !== '' && smtp.password !== ''))))

onMounted(async () => {
  await auth.fetchSetupStatus()
  if (!auth.needsSetup) router.replace('/login')
})

// Jazyk průvodce se přepne hned podle volby admina
watch(() => admin.locale, l => {
  locale.value = l
  try { localStorage.setItem('locale', l) } catch { /* privátní režim */ }
})
// Odesílatel e-mailů = admin, dokud ho uživatel nezmění
watch(() => admin.email, (now, before) => {
  if (smtp.from_email === '' || smtp.from_email === before) smtp.from_email = now
})

function next() {
  error.value = ''
  const i = stepIndex.value
  if (i >= 0 && i < STEPS.length - 1) step.value = STEPS[i + 1]
}
function back() {
  const i = stepIndex.value
  if (i > 0) step.value = STEPS[i - 1]
}

async function testSmtp() {
  smtpTesting.value = true
  smtpTest.value = null
  try {
    smtpTest.value = await authApi.setupSmtpTest({ ...smtp }, admin.email, admin.locale)
  } catch (e: any) {
    const fields = e?.response?.data?.error?.fields as Record<string, string[]> | undefined
    smtpTest.value = { ok: false, message: fields ? Object.values(fields).flat().join(' ') : (e?.response?.data?.error?.message ?? t('setup.failed')) }
  } finally {
    smtpTesting.value = false
  }
}

const FIELD_STEP: Record<string, Step> = { admin: 'admin', smtp: 'email' }
async function submit() {
  submitting.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    const payload: SetupPayload = {
      admin: { name: admin.name.trim(), email: admin.email.trim(), password: admin.password, locale: admin.locale, display_currency: admin.display_currency },
      require_mfa: requireMfa.value,
      require_totp: false,
      smtp: useSmtp.value ? { ...smtp } : null,
      demo_data: demoData.value,
    }
    const r = await authApi.setup(payload)
    auth.setSessionCsrfToken(r.csrf_token)
    result.value = { demo: r.demo }
    step.value = 'done'
  } catch (e: any) {
    const data = e?.response?.data?.error
    fieldErrors.value = data?.fields ?? {}
    const keys = Object.keys(fieldErrors.value)
    if (keys.length > 0) {
      error.value = t('setup.fix_fields', { fields: keys.map(k => t(`setup.fields.${k.replace('.', '_')}`, k)).join(', ') })
      const target = FIELD_STEP[keys[0].split('.')[0]]
      if (target) step.value = target
    } else {
      error.value = data?.message || t('setup.failed')
    }
  } finally {
    submitting.value = false
  }
}

async function goToApp() {
  // Po setupu je session vytvořená (auto-login). Refresh auth store + hard reload na / (při MFA přesměruje router).
  await auth.refresh()
  window.location.href = '/'
}

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none'
const labelClass = 'block text-sm font-medium text-neutral-700 mb-1'
</script>

<template>
  <AppShell :title="t('setup.title')">
    <div class="w-full max-w-xl">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6">
        <!-- Kroky -->
        <ol v-if="step !== 'done'" class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-6 text-xs">
          <li v-for="(s, i) in STEPS" :key="s" class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[11px] font-semibold"
              :class="i < stepIndex ? 'bg-action text-on-action' : i === stepIndex ? 'bg-primary-100 text-primary-700 ring-1 ring-primary-600' : 'bg-neutral-100 text-neutral-500'">{{ i + 1 }}</span>
            <span :class="i === stepIndex ? 'text-primary-700 font-medium' : 'text-neutral-500'">{{ t(`setup.steps.${s}`) }}</span>
            <span v-if="i < STEPS.length - 1" class="w-4 h-px bg-neutral-200"></span>
          </li>
        </ol>

        <!-- 1. Admin a předvolby -->
        <form v-if="step === 'admin'" class="space-y-4" @submit.prevent="adminValid && next()">
          <div>
            <h1 class="text-xl font-semibold mb-1">{{ t('setup.create_admin') }}</h1>
            <p class="text-sm text-neutral-500">{{ t('setup.admin_hint') }}</p>
          </div>
          <div>
            <label :for="fid('name')" :class="labelClass">{{ t('users.name') }}</label>
            <input :id="fid('name')" v-model="admin.name" required autofocus :class="inputClass" />
          </div>
          <div>
            <label :for="fid('email')" :class="labelClass">{{ t('auth.email') }}</label>
            <input :id="fid('email')" v-model="admin.email" type="email" required autocomplete="email" :class="inputClass" />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label :for="fid('password')" :class="labelClass">{{ t('auth.password') }}</label>
              <input :id="fid('password')" v-model="admin.password" type="password" required autocomplete="new-password" :class="inputClass" />
              <p class="text-xs mt-1" :class="admin.password && !passwordOk ? 'text-danger-500' : 'text-neutral-500'">{{ t('auth.min_chars', { n: 12 }) }}</p>
            </div>
            <div>
              <label :for="fid('password_confirm')" :class="labelClass">{{ t('auth.new_password_confirm') }}</label>
              <input :id="fid('password_confirm')" v-model="admin.password_confirm" type="password" required autocomplete="new-password" :class="inputClass" />
              <p v-if="admin.password_confirm && !passwordMatch" class="text-xs text-danger-500 mt-1">{{ t('auth.passwords_dont_match') }}</p>
            </div>
          </div>
          <div class="grid gap-4 sm:grid-cols-2 pt-2 border-t border-neutral-200">
            <div>
              <label :for="fid('locale')" :class="labelClass">{{ t('setup.language') }}</label>
              <select :id="fid('locale')" v-model="admin.locale" :class="inputClass">
                <option value="cs">Čeština</option>
                <option value="en">English</option>
              </select>
            </div>
            <div>
              <label :for="fid('display_currency')" :class="labelClass">{{ t('setup.display_currency') }}</label>
              <select :id="fid('display_currency')" v-model="admin.display_currency" :class="inputClass">
                <option v-for="c in ['CZK', 'EUR', 'USD']" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>
          <p role="alert" v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
          <div class="flex justify-end">
            <button type="submit" :disabled="!adminValid" class="cursor-pointer px-5 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action font-medium rounded-md">{{ t('setup.next') }}</button>
          </div>
        </form>

        <!-- 2. Zabezpečení -->
        <div v-else-if="step === 'security'" class="space-y-4">
          <div>
            <h1 class="text-xl font-semibold mb-1">{{ t('setup.steps.security') }}</h1>
            <p class="text-sm text-neutral-500">{{ t('setup.security_hint') }}</p>
          </div>
          <label class="flex items-start gap-3 cursor-pointer">
            <input v-model="requireMfa" type="checkbox" class="mt-1 h-4 w-4 rounded border-field text-primary-700 focus:ring-primary-500" />
            <span class="text-sm">
              <span class="font-medium text-neutral-800">{{ t('setup.require_mfa_label') }}</span>
              <span class="block text-xs text-neutral-500 mt-0.5">{{ t('setup.require_mfa_hint') }}</span>
            </span>
          </label>
          <p v-if="requireMfa" class="ml-7 text-xs text-neutral-500">{{ t('setup.require_mfa_methods_hint') }}</p>
          <div class="flex justify-between">
            <button type="button" @click="back" class="cursor-pointer px-4 h-10 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('setup.back') }}</button>
            <button type="button" @click="next" class="cursor-pointer px-5 h-10 bg-action hover:bg-action-hover text-on-action font-medium rounded-md">{{ t('setup.next') }}</button>
          </div>
        </div>

        <!-- 3. E-mail (SMTP) -->
        <div v-else-if="step === 'email'" class="space-y-4">
          <div>
            <h1 class="text-xl font-semibold mb-1">{{ t('setup.steps.email') }}</h1>
            <p class="text-sm text-neutral-500">{{ t('setup.email_hint') }}</p>
          </div>
          <label class="flex items-center gap-3 cursor-pointer text-sm">
            <input v-model="useSmtp" type="checkbox" class="h-4 w-4 rounded border-field text-primary-700 focus:ring-primary-500" />
            <span class="font-medium text-neutral-800">{{ t('setup.use_smtp') }}</span>
          </label>
          <template v-if="useSmtp">
            <div class="grid gap-4 sm:grid-cols-[1fr_6rem_8rem]">
              <div>
                <label :for="fid('host')" :class="labelClass">{{ t('smtp.host') }}</label>
                <input :id="fid('host')" v-model="smtp.host" placeholder="smtp.example.com" autocomplete="off" :class="inputClass" />
              </div>
              <div>
                <label :for="fid('port')" :class="labelClass">{{ t('smtp.port') }}</label>
                <input :id="fid('port')" v-model.number="smtp.port" type="number" min="1" max="65535" :class="inputClass" />
              </div>
              <div>
                <label :for="fid('encryption')" :class="labelClass">{{ t('smtp.encryption') }}</label>
                <select :id="fid('encryption')" v-model="smtp.encryption" :class="inputClass">
                  <option value="tls">STARTTLS</option>
                  <option value="ssl">SSL/TLS</option>
                  <option value="none">{{ t('setup.none') }}</option>
                </select>
              </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="smtp.auth_enabled" type="checkbox" class="rounded border-field text-primary-700" />
              {{ t('smtp.auth_enabled') }}
            </label>
            <div v-if="smtp.auth_enabled" class="grid gap-4 sm:grid-cols-2">
              <div>
                <label :for="fid('username')" :class="labelClass">{{ t('smtp.username') }}</label>
                <input :id="fid('username')" v-model="smtp.username" autocomplete="off" :class="inputClass" />
              </div>
              <div>
                <label :for="fid('password2')" :class="labelClass">{{ t('smtp.password') }}</label>
                <input :id="fid('password2')" v-model="smtp.password" type="password" autocomplete="new-password" :class="inputClass" />
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label :for="fid('from_email')" :class="labelClass">{{ t('smtp.from_email') }}</label>
                <input :id="fid('from_email')" v-model="smtp.from_email" type="email" :class="inputClass" />
              </div>
              <div>
                <label :for="fid('from_name')" :class="labelClass">{{ t('smtp.from_name') }}</label>
                <input :id="fid('from_name')" v-model="smtp.from_name" :class="inputClass" />
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <button type="button" :disabled="!smtpValid || smtpTesting" @click="testSmtp"
                class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
                {{ smtpTesting ? '…' : t('setup.smtp_test', { email: admin.email }) }}
              </button>
              <span v-if="smtpTest" class="text-sm" :class="smtpTest.ok ? 'text-success-600' : 'text-danger-500'">
                {{ smtpTest.ok ? t('setup.smtp_test_ok') : smtpTest.message }}
              </span>
            </div>
          </template>
          <p v-else class="text-xs text-neutral-500">{{ t('setup.smtp_later') }}</p>
          <p role="alert" v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
          <div class="flex justify-between">
            <button type="button" @click="back" class="cursor-pointer px-4 h-10 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('setup.back') }}</button>
            <button type="button" :disabled="!smtpValid" @click="next" class="cursor-pointer px-5 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 disabled:cursor-not-allowed text-on-action font-medium rounded-md">{{ t('setup.next') }}</button>
          </div>
        </div>

        <!-- 4. Ukázková data -->
        <div v-else-if="step === 'data'" class="space-y-4">
          <div>
            <h1 class="text-xl font-semibold mb-1">{{ t('setup.steps.data') }}</h1>
            <p class="text-sm text-neutral-500">{{ t('setup.data_hint') }}</p>
          </div>
          <label class="flex items-start gap-3 cursor-pointer">
            <input v-model="demoData" type="checkbox" class="mt-1 h-4 w-4 rounded border-field text-primary-700 focus:ring-primary-500" />
            <span class="text-sm">
              <span class="font-medium text-neutral-800">{{ t('setup.demo_label') }}</span>
              <span class="block text-xs text-neutral-500 mt-0.5">{{ t('setup.demo_hint') }}</span>
            </span>
          </label>
          <p v-if="demoData" class="ml-7 text-xs text-warning-600">{{ t('setup.demo_warning') }}</p>
          <div class="flex justify-between">
            <button type="button" @click="back" class="cursor-pointer px-4 h-10 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('setup.back') }}</button>
            <button type="button" @click="next" class="cursor-pointer px-5 h-10 bg-action hover:bg-action-hover text-on-action font-medium rounded-md">{{ t('setup.next') }}</button>
          </div>
        </div>

        <!-- 5. Shrnutí -->
        <div v-else-if="step === 'summary'" class="space-y-4">
          <h1 class="text-xl font-semibold">{{ t('setup.steps.summary') }}</h1>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-neutral-500">{{ t('setup.summary_admin') }}</dt><dd>{{ admin.name }} · {{ admin.email }}</dd>
            <dt class="text-neutral-500">{{ t('setup.language') }}</dt><dd>{{ admin.locale === 'en' ? 'English' : 'Čeština' }} · {{ admin.display_currency }}</dd>
            <dt class="text-neutral-500">MFA</dt><dd>{{ requireMfa ? t('setup.mfa_required') : t('setup.mfa_optional') }}</dd>
            <dt class="text-neutral-500">{{ t('setup.steps.email') }}</dt><dd>{{ useSmtp ? `${smtp.host}:${smtp.port}` : t('setup.smtp_skipped') }}</dd>
            <dt class="text-neutral-500">{{ t('setup.steps.data') }}</dt><dd>{{ demoData ? t('setup.demo_yes') : t('setup.demo_no') }}</dd>
          </dl>
          <p role="alert" v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
          <div class="flex justify-between">
            <button type="button" @click="back" class="cursor-pointer px-4 h-10 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('setup.back') }}</button>
            <button type="button" :disabled="submitting" @click="submit" class="cursor-pointer px-5 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
              {{ submitting ? (demoData ? t('setup.creating_demo') : '…') : t('setup.finish') }}
            </button>
          </div>
        </div>

        <!-- Hotovo -->
        <div v-else class="text-center py-8">
          <div class="w-16 h-16 bg-primary-100 rounded-full mx-auto mb-4 flex items-center justify-center">
            <svg class="w-8 h-8 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h1 class="text-xl font-semibold mb-2">{{ t('common.success') }}</h1>
          <p class="text-neutral-500 mb-2">{{ t('setup.done_signed_in') }}</p>
          <p v-if="result?.demo?.ok" class="text-sm text-neutral-600 mb-2">
            {{ t('setup.demo_done', { n: result.demo.transactions ?? 0 }) }}
            {{ result.demo.backfill_started ? t('setup.demo_backfill') : t('setup.demo_backfill_manual') }}
          </p>
          <p v-else-if="result?.demo" class="text-sm text-danger-500 mb-2">{{ t('setup.demo_failed', { message: result.demo.message ?? '' }) }}</p>
          <div v-if="requireMfa" class="mb-6 inline-block bg-warning-50 border border-warning-500/40 rounded-md px-4 py-2 text-sm text-warning-600 text-left">
            {{ t('setup.mfa_setup_next') }}
          </div>
          <div>
            <button @click="goToApp" class="cursor-pointer inline-block px-6 h-10 leading-10 bg-action hover:bg-action-hover text-on-action font-medium rounded-md">
              {{ t('setup.continue_to_app') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>
