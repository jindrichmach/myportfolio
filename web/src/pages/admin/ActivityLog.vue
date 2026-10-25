<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type ActivityLogEntry } from '@/api/admin'
import DataGrid from '@/components/ui/DataGrid.vue'
import type { DataColumn } from '@/components/ui/DataTable.vue'
import type { GridFilter, GridDateRange, GridOption, GridQuery } from '@/components/ui/grid'

const { t } = useI18n()

const entries = ref<ActivityLogEntry[]>([])
const total = ref(0)
const actionOptions = ref<GridOption[]>([])
const loading = ref(true)

const ENTITY_OPTIONS: GridOption[] = ['user', 'api_token', 'share', 'exchange_connection', 'smtp_settings'].map((value) => ({ value, label: value }))

/** Filtry jdou na server: hledání, akce, typ entity a období. */
const filters = computed<GridFilter[]>(() => [
  { key: 'q', type: 'text', label: t('grid.search'), placeholder: t('activity_log.search_placeholder') },
  { key: 'action', type: 'select', label: t('activity_log.action'), allLabel: t('activity_log.all_actions'), options: actionOptions.value },
  { key: 'entity_type', type: 'select', label: t('activity_log.entity'), allLabel: t('activity_log.all_entities'), options: ENTITY_OPTIONS },
  { key: 'period', type: 'daterange', label: t('grid.period') },
])

const columns = computed((): DataColumn[] => [
  { key: 'created_at', label: t('activity_log.time'), mono: true },
  { key: 'user', label: t('activity_log.user') },
  { key: 'action', label: t('activity_log.action'), primary: true },
  { key: 'entity', label: t('activity_log.entity') },
  { key: 'payload', label: t('activity_log.payload') },
  { key: 'ip', label: 'IP', mono: true, hideBelow: 'xl' },
])

function actionBadgeClass(a: string): string {
  if (a.startsWith('auth.') || a.startsWith('login')) return 'bg-success-50 text-success-600'
  if (a.includes('delete') || a.includes('failed') || a.includes('cancel') || a.includes('locked')) return 'bg-danger-50 text-danger-500'
  if (a.includes('warning') || a.includes('force')) return 'bg-warning-50 text-warning-600'
  return 'bg-neutral-100 text-neutral-600'
}

function fmtPayload(p: Record<string, unknown> | null): string {
  if (!p) return '—'
  return Object.entries(p).map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(' · ')
}

function fmtTime(iso: string): string {
  return iso.replace('T', ' ').slice(0, 19)
}

/** Převod stavu gridu na parametry API (prázdné hodnoty se neposílají). */
async function load(query: GridQuery) {
  const f = query.filterValues
  const text = (key: string) => (typeof f[key] === 'string' ? (f[key] as string).trim() : '')
  const range = (f.period ?? { from: '', to: '' }) as GridDateRange
  const params: Parameters<typeof adminApi.activityLog>[0] = {
    limit: query.pageSize,
    offset: (query.page - 1) * query.pageSize,
  }
  if (text('q')) params.q = text('q')
  if (text('action')) params.action = text('action')
  if (text('entity_type')) params.entity_type = text('entity_type')
  if (range.from) params.from = range.from
  if (range.to) params.to = range.to

  loading.value = true
  try {
    const r = await adminApi.activityLog(params)
    entries.value = r.data
    total.value = r.total
    actionOptions.value = r.actions.map((a) => ({ value: a.action, label: `${a.action} (${a.cnt})` }))
  } finally {
    loading.value = false
  }
}

</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('activity_log.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('activity_log.subtitle') }}</p>
    </div>

    <DataGrid :columns="columns" :rows="entries" :row-key="(e: ActivityLogEntry) => e.id" :filters="filters"
      :total="total" :loading="loading" @change="load">
      <template #cell-created_at="{ row }">{{ fmtTime(row.created_at) }}</template>

      <template #cell-user="{ row }">
        <span v-if="row.user_email" class="text-xs break-words">{{ row.user_name || row.user_email }}</span>
        <span v-else class="text-neutral-400">—</span>
      </template>

      <template #cell-action="{ row }">
        <span class="text-xs px-2 py-0.5 rounded font-medium" :class="actionBadgeClass(row.action)">{{ row.action }}</span>
      </template>

      <template #cell-entity="{ row }">
        <span v-if="row.entity_type" class="text-xs whitespace-nowrap">{{ row.entity_type }} #{{ row.entity_id }}</span>
        <span v-else class="text-neutral-400">—</span>
      </template>

      <template #cell-payload="{ row }">
        <span class="text-xs text-neutral-600 break-all whitespace-pre-wrap leading-snug">{{ fmtPayload(row.payload) }}</span>
      </template>
    </DataGrid>
  </div>
</template>
