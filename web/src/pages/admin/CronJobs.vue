<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type CronJob, type CronJobHealth } from '@/api/admin'
import { useToast } from '@/composables/useToast'
import { formatDec } from '@/composables/useFormat'
import { useSessionAwarePolling } from '@/composables/useSessionAwarePolling'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'

const { t } = useI18n()
const toast = useToast()

const jobs = ref<CronJob[]>([])
const serverTime = ref<string>('')
const loading = ref(false)
const expanded = ref<Record<string, boolean>>({})
const running = ref<Record<string, boolean>>({})

async function load(signal?: AbortSignal) {
  loading.value = true
  try {
    const r = await adminApi.cronJobs(signal)
    jobs.value = r.jobs
    serverTime.value = r.server_time
  } finally {
    loading.value = false
  }
}

const polling = useSessionAwarePolling(signal => load(signal), 60_000)

function toggle(script: string) {
  expanded.value[script] = !expanded.value[script]
}

async function runNow(script: string) {
  if (running.value[script]) return
  if (!window.confirm(t('cron_jobs.run_now_confirm', { script }))) return
  running.value[script] = true
  try {
    await adminApi.runCronJob(script)
    toast.success(t('cron_jobs.run_now_started', { script }))
    // Refresh hned a pak ještě několikrát, aby se chytl finish běhu.
    setTimeout(() => { if (polling.active.value) void load() }, 1500)
    setTimeout(() => { if (polling.active.value) void load() }, 5000)
    setTimeout(() => { if (polling.active.value) void load() }, 15000)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('cron_jobs.run_now_failed', { script }))
  } finally {
    running.value[script] = false
  }
}

function fmtTime(iso: string | null): string {
  if (!iso) return '—'
  return iso.replace('T', ' ').slice(0, 19)
}

function fmtAge(seconds: number | null): string {
  if (seconds === null) return '—'
  if (seconds < 90) return t('cron_jobs.ago_seconds', { n: seconds })
  const minutes = Math.floor(seconds / 60)
  if (minutes < 90) return t('cron_jobs.ago_minutes', { n: minutes })
  const hours = Math.floor(minutes / 60)
  if (hours < 48) return t('cron_jobs.ago_hours', { n: hours })
  return t('cron_jobs.ago_days', { n: Math.floor(hours / 24) })
}

function fmtFreq(recommended: string): string {
  const key = `cron_jobs.freq_${recommended}`
  const translated = t(key)
  return translated === key ? recommended : translated
}

function fmtDuration(ms: number | null): string {
  if (ms === null) return '—'
  if (ms < 1000) return `${ms} ms`
  return `${formatDec(String(ms / 1000), 1)} s`
}

function reportSummary(report: unknown): string {
  if (!report || typeof report !== 'object') return String(report ?? '')
  return JSON.stringify(report)
}

function healthBadgeClass(h: CronJobHealth): string {
  switch (h) {
    case 'ok': return 'bg-success-50 text-success-600'
    case 'overdue': return 'bg-warning-50 text-warning-600'
    case 'failing':
    case 'overdue_and_failing':
    case 'never_ran': return 'bg-danger-50 text-danger-500'
  }
}

function healthLabel(h: CronJobHealth): string {
  return t(`cron_jobs.health_${h}`)
}

function healthTooltip(j: CronJob): string {
  if (j.health === 'overdue' || j.health === 'overdue_and_failing') {
    return t('cron_jobs.tooltip_overdue', { hours: j.max_age_hours })
  }
  if (j.health === 'failing') return t('cron_jobs.tooltip_failing')
  if (j.health === 'never_ran') return t('cron_jobs.tooltip_never_ran')
  return ''
}

const hasProblems = computed(() => jobs.value.some(j => j.health !== 'ok'))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('cron_jobs.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('cron_jobs.subtitle') }}</p>
      <p class="text-xs text-neutral-500 mt-2">
        {{ t('cron_jobs.setup_hint') }}
      </p>
    </div>

    <div v-if="loading && !jobs.length" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop tabulka -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.script') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.recommended') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.last_run') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.duration') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.last_24h') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.health') }}</th>
              <th class="px-3 py-2 text-right font-medium"></th>
              <th class="px-3 py-2 text-right font-medium w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="j in jobs" :key="j.script">
              <tr class="hover:bg-neutral-50 align-top cursor-pointer" @click="toggle(j.script)">
                <td class="px-3 py-2">
                  <div class="font-mono text-xs font-medium text-neutral-900">{{ j.script }}</div>
                  <div class="flex gap-1.5 mt-0.5">
                    <span v-if="j.critical" class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 leading-none">{{ t('cron_jobs.critical_hint') }}</span>
                    <span v-if="j.weekdays_only" class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 leading-none">{{ t('cron_jobs.weekdays_only_hint') }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600">
                  <div>{{ fmtFreq(j.recommended) }}</div>
                  <code class="text-[10px] text-neutral-400 font-mono">{{ j.linux_cron }}</code>
                </td>
                <td class="px-3 py-2 text-xs whitespace-nowrap">
                  <div v-if="j.last_started_at" class="text-neutral-900 font-mono">{{ fmtTime(j.last_started_at) }}</div>
                  <div v-else class="text-neutral-400 italic">{{ t('cron_jobs.no_runs_yet') }}</div>
                  <div v-if="j.age_sec_since_ok !== null" class="text-[10px] text-neutral-500 mt-0.5">{{ fmtAge(j.age_sec_since_ok) }}</div>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">{{ fmtDuration(j.last_duration_ms) }}</td>
                <td class="px-3 py-2 text-xs whitespace-nowrap">
                  <span v-if="j.counts_24h.total === 0" class="text-neutral-400">—</span>
                  <template v-else>
                    <span class="text-success-600 font-mono">{{ j.counts_24h.ok }} <Icon name="check" class="w-3.5 h-3.5 align-[-2px]" /></span>
                    <span v-if="j.counts_24h.error > 0" class="text-danger-500 font-mono ml-1.5">{{ j.counts_24h.error }} <Icon name="x" class="w-3.5 h-3.5 align-[-2px]" /></span>
                  </template>
                </td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="text-xs px-2 py-0.5 rounded font-medium" :class="healthBadgeClass(j.health)" :title="healthTooltip(j)">
                    {{ healthLabel(j.health) }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap" @click.stop>
                  <IconButton icon="play" :label="t('cron_jobs.run_now')" :loading="running[j.script]" @click="runNow(j.script)" />
                </td>
                <td class="px-3 py-2 text-right">
                  <svg class="w-4 h-4 text-neutral-400 inline-block transition" :class="{ 'rotate-180': expanded[j.script] }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                  </svg>
                </td>
              </tr>
              <tr v-if="expanded[j.script]" class="bg-neutral-50/60">
                <td colspan="8" class="px-3 py-3">
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs">
                    <div>
                      <span class="text-neutral-500">Linux cron: </span>
                      <code class="font-mono text-neutral-700">{{ j.linux_cron }}</code>
                    </div>
                    <div v-if="j.last_host">
                      <span class="text-neutral-500">{{ t('cron_jobs.host') }}: </span>
                      <code class="font-mono text-neutral-700">{{ j.last_host }}</code>
                    </div>
                    <div v-if="j.last_ok_started_at">
                      <span class="text-neutral-500">{{ t('cron_jobs.last_ok') }}: </span>
                      <span class="font-mono text-neutral-700">{{ fmtTime(j.last_ok_started_at) }}</span>
                    </div>
                  </div>
                  <div v-if="j.last_message" class="mt-2 text-xs">
                    <span class="text-neutral-500">{{ t('cron_jobs.message_label') }}: </span>
                    <span class="font-mono text-danger-600 break-all">{{ j.last_message }}</span>
                  </div>
                  <div v-if="j.last_report" class="mt-2 text-xs">
                    <span class="text-neutral-500">{{ t('cron_jobs.report_label') }}: </span>
                    <code class="font-mono text-neutral-700 break-all">{{ reportSummary(j.last_report) }}</code>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="j in jobs" :key="`m-${j.script}`" class="p-3 space-y-1.5">
          <div class="flex items-start justify-between gap-2">
            <div class="font-mono text-xs font-medium break-all">{{ j.script }}</div>
            <span class="text-xs px-2 py-0.5 rounded font-medium shrink-0" :class="healthBadgeClass(j.health)">{{ healthLabel(j.health) }}</span>
          </div>
          <div class="text-xs text-neutral-600">{{ fmtFreq(j.recommended) }}</div>
          <div class="flex items-baseline justify-between gap-2 text-xs">
            <span class="text-neutral-500">{{ t('cron_jobs.last_run') }}</span>
            <span v-if="j.last_started_at" class="font-mono">{{ fmtTime(j.last_started_at) }}</span>
            <span v-else class="text-neutral-400 italic">{{ t('cron_jobs.no_runs_yet') }}</span>
          </div>
          <div v-if="j.age_sec_since_ok !== null" class="flex items-baseline justify-between gap-2 text-xs">
            <span class="text-neutral-500">{{ t('cron_jobs.last_ok') }}</span>
            <span>{{ fmtAge(j.age_sec_since_ok) }}</span>
          </div>
          <div v-if="j.counts_24h.total > 0" class="flex items-baseline justify-between gap-2 text-xs">
            <span class="text-neutral-500">{{ t('cron_jobs.last_24h') }}</span>
            <span class="font-mono">
              <span class="text-success-600">{{ j.counts_24h.ok }} <Icon name="check" class="w-3.5 h-3.5 align-[-2px]" /></span>
              <span v-if="j.counts_24h.error > 0" class="text-danger-500 ml-1.5">{{ j.counts_24h.error }} <Icon name="x" class="w-3.5 h-3.5 align-[-2px]" /></span>
            </span>
          </div>
          <div v-if="j.last_message" class="text-xs text-danger-600 font-mono break-all">{{ j.last_message }}</div>
          <div class="flex justify-end pt-1">
            <IconButton icon="play" :label="t('cron_jobs.run_now')" :loading="running[j.script]" @click="runNow(j.script)" />
          </div>
        </div>
      </div>
    </div>

    <p v-if="!hasProblems && jobs.length" class="mt-3 text-xs text-success-600"><Icon name="check" class="w-3.5 h-3.5 align-[-2px]" /> {{ t('cron_jobs.all_ok') }}</p>
  </div>
</template>
