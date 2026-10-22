<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Badge from '@/components/ui/Badge.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import { portfolioApi, type Portfolio } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const { canManage } = useOwnerContext()
const toast = useToast()

const rows = ref<Portfolio[]>([])
const loading = ref(true)
const showArchived = ref(false)
const showForm = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({ id: null as number | null, name: '', sort: 0, archived: false })

async function load() {
  loading.value = true
  try { rows.value = await portfolioApi.portfolios(showArchived.value) }
  finally { loading.value = false }
}
onMounted(load)
watch(showArchived, load)

function openCreate() {
  Object.assign(form, { id: null, name: '', sort: rows.value.length * 10, archived: false })
  error.value = ''
  showForm.value = true
}
function openEdit(row: Portfolio) {
  Object.assign(form, { id: row.id, name: row.name, sort: row.sort, archived: row.archived_at !== null })
  error.value = ''
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    if (form.id === null) await portfolioApi.createPortfolio({ name: form.name.trim(), sort: Number(form.sort) })
    else await portfolioApi.updatePortfolio(form.id, { name: form.name.trim(), sort: Number(form.sort), archived: form.archived })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove(row: Portfolio) {
  if (!confirm(t('common.confirm_delete', { name: row.name }))) return
  try {
    await portfolioApi.deletePortfolio(row.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const columns = computed<DataColumn[]>(() => [
  { key: 'name', label: t('common.name'), primary: true },
  { key: 'position_count', label: t('portfolios.positions_count'), align: 'right' },
  { key: 'sort', label: t('portfolios.sort'), align: 'right', hideBelow: 'sm' },
])
const portfolioKey = (p: Portfolio) => p.id
const archivedClass = (p: Portfolio) => (p.archived_at ? 'opacity-50' : '')

const inputClass = 'w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('portfolios.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('portfolios.subtitle') }}</p>
      </div>
      <button v-if="canManage" @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('portfolios.new') }}
      </button>
    </div>

    <label class="inline-flex items-center gap-2 text-sm text-neutral-600 mb-3">
      <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
      {{ t('common.show_archived') }}
    </label>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <EmptyState v-if="!rows.length" :title="t('portfolios.empty')" :message="t('portfolios.empty_hint')" />
      <DataTable v-else :columns="columns" :rows="rows" :row-key="portfolioKey" :row-class="archivedClass">
        <template #cell-name="{ row: r }">
          <RouterLink :to="{ path: '/positions', query: { portfolio_id: r.id } }" class="font-medium hover:text-primary-700 hover:underline">{{ r.name }}</RouterLink>
          <Badge v-if="r.archived_at" class="ml-1.5">{{ t('common.archived') }}</Badge>
        </template>
        <template #cell-sort="{ row: r }"><span class="text-neutral-500">{{ r.sort }}</span></template>
        <template v-if="canManage" #actions="{ row: r }">
          <span class="inline-flex gap-1">
            <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(r)" />
            <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="remove(r)" />
          </span>
        </template>
      </DataTable>
    </div>

    <Modal v-if="showForm" :title="form.id === null ? t('portfolios.new') : t('portfolios.edit_title')" width-class="max-w-md" @close="showForm = false">
      <form id="portfolio-form" class="space-y-3" @submit.prevent="save">
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
            <input v-model="form.name" required maxlength="120" :class="inputClass" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('portfolios.sort') }}</label>
            <input v-model.number="form.sort" type="number" :class="inputClass" />
          </div>
        </div>
        <label v-if="form.id !== null" class="flex items-center gap-2 text-sm">
          <input v-model="form.archived" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
          {{ t('common.archived') }}
        </label>
        <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="portfolio-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
