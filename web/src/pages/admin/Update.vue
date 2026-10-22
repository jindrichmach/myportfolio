<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import SectionCard from '@/components/ui/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import Icon from '@/components/ui/Icon.vue'
import SegmentControl from '@/components/ui/SegmentControl.vue'
import { adminApi, type UpdateStatus } from '@/api/admin'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { renderManual } from '@/utils/manual'

/**
 * Systém → Aktualizace: nainstalovaná vs. nejnovější vydaná verze (GitHub releases) a příkazy pro aktualizaci
 * podle typu instalace. Aktualizaci spouští správce na serveru — aplikace nemá práva měnit své soubory ani image.
 */
const { t } = useI18n()
const toast = useToast()

const data = ref<UpdateStatus | null>(null)
const loading = ref(true)
const checking = ref(false)
const install = ref<'docker' | 'native'>('native')

async function load(refresh = false) {
  checking.value = refresh
  try {
    data.value = await adminApi.update(refresh)
    if (!refresh) install.value = data.value.install
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
    checking.value = false
  }
}
onMounted(() => load())

const target = computed(() => data.value?.latest?.version ?? data.value?.current ?? '')
const status = computed(() => {
  const d = data.value
  if (!d?.latest) return { tone: 'neutral' as const, text: t('update.unknown') }
  return d.update_available
    ? { tone: 'warning' as const, text: t('update.available', { version: d.latest.version }) }
    : { tone: 'success' as const, text: t('update.up_to_date') }
})
const notesHtml = computed(() => (data.value?.latest?.notes ? renderManual(data.value.latest.notes) : ''))
const installOptions = computed(() => (['docker', 'native'] as const).map(v => ({ value: v, label: t(`update.install_${v}`) })))

const commands = computed(() => {
  const d = data.value
  if (!d) return ''
  if (install.value === 'docker') {
    return [
      `# docker-compose.production.yml → image: ghcr.io/${d.repo.toLowerCase()}:${target.value}`,
      'docker compose -f docker-compose.production.yml pull',
      'docker compose -f docker-compose.production.yml up -d',
    ].join('\n')
  }
  return [
    `cd ${d.app_dir}`,
    'git pull',
    'cd api && composer install --no-dev --optimize-autoloader && cd ..',
    `${d.php} api/bin/migrate.php`,
    'cd web && pnpm install --frozen-lockfile && pnpm build && cd ..',
  ].join('\n')
})

const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(commands.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    toast.error(t('common.error'))
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('update.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('update.subtitle') }}</p>
      </div>
      <button type="button" :disabled="checking || loading" @click="load(true)"
        class="cursor-pointer h-9 px-3 inline-flex items-center gap-1.5 border border-neutral-300 rounded-md bg-surface text-sm hover:bg-neutral-50 disabled:opacity-60 shrink-0 whitespace-nowrap">
        {{ checking ? t('update.checking') : t('update.check_now') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <SectionCard :title="t('update.versions')" class="mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3 items-start">
          <div class="min-w-0">
            <div class="text-xs text-neutral-500">{{ t('update.current') }}</div>
            <div class="mt-1 font-mono font-semibold text-xl sm:text-3xl tracking-tight break-all">{{ data.current }}</div>
          </div>
          <div class="min-w-0">
            <div class="text-xs text-neutral-500">{{ t('update.latest') }}</div>
            <div class="mt-1 font-mono font-semibold text-xl sm:text-3xl tracking-tight break-all">{{ data.latest?.version ?? '—' }}</div>
            <div v-if="data.latest?.published_at" class="text-sm text-neutral-500 tabular-nums">{{ formatDate(data.latest.published_at) }}</div>
          </div>
          <div class="col-span-2 sm:col-span-1 flex flex-col gap-1.5 sm:items-end">
            <Badge :tone="status.tone">{{ status.text }}</Badge>
            <span v-if="data.checked_at" class="text-xs text-neutral-500">{{ t('update.checked_at', { at: formatDateTime(data.checked_at) }) }}</span>
          </div>
        </div>
        <p v-if="data.error" class="mt-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
          <Icon name="warning" class="w-4 h-4 align-[-3px] mr-1" />{{ t(`update.err_${data.error}`, { repo: data.repo }) }}
        </p>
      </SectionCard>

      <SectionCard v-if="data.latest && data.update_available" :title="t('update.notes_title', { version: data.latest.version })" class="mb-4">
        <template #actions>
          <Badge v-if="data.latest.prerelease">{{ t('update.prerelease') }}</Badge>
          <a v-if="data.latest.url" :href="data.latest.url" target="_blank" rel="noopener" class="text-xs text-primary-700 hover:underline">{{ t('update.open_github') }}</a>
        </template>
        <!-- markdown-it s html: false — HTML v popisu vydání se escapuje -->
        <div v-if="notesHtml" class="manual-prose max-h-[28rem] overflow-y-auto [&>:first-child]:mt-0" v-html="notesHtml" />
        <p v-else class="text-sm text-neutral-500">{{ t('update.no_notes') }}</p>
      </SectionCard>

      <SectionCard :title="t('update.howto_title')">
        <template #actions>
          <SegmentControl v-model="install" :options="installOptions" :label="t('update.howto_title')" />
        </template>
        <ol class="space-y-4 text-sm">
          <li class="flex gap-3">
            <span class="w-6 h-6 shrink-0 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold inline-flex items-center justify-center">1</span>
            <p class="pt-0.5">{{ t('update.step_backup') }}
              <RouterLink to="/admin/cron-jobs" class="text-primary-700 hover:underline whitespace-nowrap">{{ t('update.step_backup_link') }} →</RouterLink>
            </p>
          </li>
          <li class="flex gap-3">
            <span class="w-6 h-6 shrink-0 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold inline-flex items-center justify-center">2</span>
            <div class="min-w-0 flex-1 space-y-2">
              <p class="pt-0.5">
                {{ t('update.step_run') }}
                <span v-if="install === data.install" class="text-xs text-neutral-500">({{ t('update.detected') }})</span>
              </p>
              <div class="relative">
                <pre class="bg-neutral-900 text-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 rounded-md p-3 pr-24 font-mono text-xs leading-relaxed overflow-x-auto">{{ commands }}</pre>
                <button type="button" @click="copy"
                  class="cursor-pointer absolute top-2 right-2 h-7 px-2 rounded-md text-xs font-medium bg-surface text-neutral-700 border border-neutral-300 hover:bg-neutral-50">
                  <Icon v-if="copied" name="check" class="w-3.5 h-3.5 align-[-2px]" /> {{ copied ? t('update.copied') : t('update.copy') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500">{{ install === 'docker' ? t('update.docker_hint', { version: target }) : t('update.native_hint') }}</p>
            </div>
          </li>
        </ol>
        <template #footer>
          <p class="text-xs text-neutral-500">
            {{ t('update.manual_only') }}
            <RouterLink to="/manual/02_Instalace#24-aktualizace" class="text-primary-700 hover:underline">{{ t('update.manual_link') }}</RouterLink>
          </p>
        </template>
      </SectionCard>
    </template>
  </div>
</template>
