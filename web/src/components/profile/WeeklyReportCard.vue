<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { weeklyReportApi, type WeeklyReportSettings } from '@/api/settings'
import { apiErrorMessage } from '@/api/errors'
import { formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const settings = ref<WeeklyReportSettings | null>(null)
const form = reactive({ enabled: false, detailed: true, monthly: false, yearly: false, recipients: '' })
const busy = ref<'save' | 'send' | null>(null)
const error = ref('')

function fill(s: WeeklyReportSettings) {
  settings.value = s
  form.enabled = s.enabled
  form.detailed = s.detailed
  form.monthly = s.monthly
  form.yearly = s.yearly
  form.recipients = s.recipients.join(', ')
}

onMounted(async () => {
  try {
    fill(await weeklyReportApi.get())
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  }
})

async function save() {
  busy.value = 'save'
  error.value = ''
  try {
    fill(await weeklyReportApi.update({
      enabled: form.enabled,
      detailed: form.detailed,
      monthly: form.monthly,
      yearly: form.yearly,
      recipients: form.recipients.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean),
    }))
    toast.success(t('weekly_report.saved'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}

async function sendNow() {
  busy.value = 'send'
  error.value = ''
  try {
    const r = await weeklyReportApi.send()
    toast.success(t('weekly_report.sent', { to: r.sent_to.join(', ') }))
    fill(await weeklyReportApi.get())
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <form v-if="settings" @submit.prevent="save" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
    <div>
      <h2 class="text-lg font-semibold">{{ t('weekly_report.title') }}</h2>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('weekly_report.subtitle') }}</p>
    </div>
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
      {{ t('weekly_report.enabled') }}
    </label>
    <div>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.detailed" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
        {{ t('weekly_report.detailed') }}
      </label>
      <p class="text-xs text-neutral-500 mt-1 ml-6">{{ form.detailed ? t('weekly_report.detailed_on') : t('weekly_report.detailed_off') }}</p>
    </div>
    <div class="space-y-1">
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.monthly" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
        {{ t('weekly_report.monthly') }}
        <a :href="weeklyReportApi.periodPreviewUrl()" target="_blank" rel="noopener" class="text-xs text-primary-700 hover:underline">{{ t('weekly_report.preview') }}</a>
      </label>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.yearly" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
        {{ t('weekly_report.yearly') }}
        <a :href="weeklyReportApi.periodPreviewUrl(String(new Date().getFullYear() - 1))" target="_blank" rel="noopener" class="text-xs text-primary-700 hover:underline">{{ t('weekly_report.preview') }}</a>
      </label>
    </div>
    <div>
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('weekly_report.recipients') }}</label>
      <input v-model="form.recipients" :placeholder="settings.default_email" autocomplete="off"
             class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
      <p class="text-xs text-neutral-500 mt-1">{{ t('weekly_report.recipients_hint', { email: settings.default_email }) }}</p>
    </div>
    <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
      <p class="text-xs text-neutral-500">
        {{ settings.sent_at ? t('weekly_report.last_sent', { at: formatDateTime(settings.sent_at) }) : t('weekly_report.never_sent') }}
      </p>
      <div class="flex flex-wrap gap-2">
        <a :href="weeklyReportApi.previewUrl" target="_blank" rel="noopener"
           class="inline-flex items-center px-3 h-10 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('weekly_report.preview') }}</a>
        <button type="button" :disabled="busy !== null" @click="sendNow"
                class="cursor-pointer px-3 h-10 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ busy === 'send' ? '…' : t('weekly_report.send_now') }}
        </button>
        <button type="submit" :disabled="busy !== null"
                class="cursor-pointer px-4 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ busy === 'save' ? '…' : t('common.save') }}
        </button>
      </div>
    </div>
  </form>
</template>
