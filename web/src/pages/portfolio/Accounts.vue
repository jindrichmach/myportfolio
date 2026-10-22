<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Badge from '@/components/ui/Badge.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import { catalogApi, type Institution } from '@/api/catalog'
import { portfolioApi, type Account } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const { canManage } = useOwnerContext()
const toast = useToast()

const rows = ref<Account[]>([])
const institutions = ref<Institution[]>([])
const loading = ref(true)
const showArchived = ref(false)
const showForm = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({
  id: null as number | null,
  institution_id: null as number | null,
  name: '',
  currency: 'CZK',
  note: '',
  archived: false,
})

async function load() {
  loading.value = true
  try {
    ;[rows.value, institutions.value] = await Promise.all([
      portfolioApi.accounts(showArchived.value),
      catalogApi.institutions(),
    ])
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(showArchived, load)

function openCreate() {
  Object.assign(form, { id: null, institution_id: institutions.value[0]?.id ?? null, name: '', currency: 'CZK', note: '', archived: false })
  error.value = ''
  showForm.value = true
}
function openEdit(row: Account) {
  Object.assign(form, { id: row.id, institution_id: row.institution_id, name: row.name, currency: row.currency, note: row.note ?? '', archived: row.archived_at !== null })
  error.value = ''
  showForm.value = true
}

async function save() {
  if (form.institution_id === null) return
  busy.value = true
  error.value = ''
  const payload = { institution_id: form.institution_id, name: form.name.trim(), currency: form.currency.trim(), note: form.note.trim() || null }
  try {
    if (form.id === null) await portfolioApi.createAccount(payload)
    else await portfolioApi.updateAccount(form.id, { ...payload, archived: form.archived })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove(row: Account) {
  if (!confirm(t('common.confirm_delete', { name: row.name }))) return
  try {
    await portfolioApi.deleteAccount(row.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const columns = computed<DataColumn[]>(() => [
  { key: 'name', label: t('common.name'), primary: true },
  { key: 'currency', label: t('common.currency'), mono: true },
  { key: 'note', label: t('common.note'), hideBelow: 'lg' },
])
const accountKey = (a: Account) => a.id
const archivedClass = (a: Account) => (a.archived_at ? 'opacity-50' : '')

const inputClass = 'w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounts.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounts.subtitle') }}</p>
      </div>
      <button v-if="canManage" @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('accounts.new') }}
      </button>
    </div>

    <label class="inline-flex items-center gap-2 text-sm text-neutral-600 mb-3">
      <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
      {{ t('common.show_archived') }}
    </label>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="!rows.length">
        <EmptyState :title="t('accounts.empty')" :message="institutions.length ? undefined : t('accounts.no_institutions')" />
        <p v-if="!institutions.length" class="text-center pb-8 -mt-6">
          <RouterLink to="/catalog/institutions" class="text-sm text-primary-700 hover:underline">{{ t('institutions.new') }} →</RouterLink>
        </p>
      </div>
      <DataTable v-else :columns="columns" :rows="rows" :row-key="accountKey" :row-class="archivedClass">
        <template #cell-name="{ row: r }">
          <span class="flex items-center gap-2.5 min-w-0">
            <EntityIcon :src="r.institution_icon_url" :name="r.institution_name" :size="28" />
            <span class="min-w-0">
              <span class="font-medium">{{ r.name }}</span>
              <Badge v-if="r.archived_at" class="ml-1.5">{{ t('common.archived') }}</Badge>
              <span class="block text-xs text-neutral-500">{{ r.institution_name }}</span>
            </span>
          </span>
        </template>
        <template #cell-note="{ row: r }"><span class="text-neutral-500">{{ r.note ?? '' }}</span></template>
        <template v-if="canManage" #actions="{ row: r }">
          <span class="inline-flex gap-1">
            <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(r)" />
            <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="remove(r)" />
          </span>
        </template>
      </DataTable>
    </div>

    <Modal v-if="showForm" :title="form.id === null ? t('accounts.new') : t('accounts.edit_title')" width-class="max-w-md" @close="showForm = false">
      <form id="account-form" class="space-y-3" @submit.prevent="save">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounts.institution') }}</label>
          <select v-model="form.institution_id" required :class="inputClass">
            <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}</option>
          </select>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
            <input v-model="form.name" required maxlength="120" :placeholder="t('accounts.name_placeholder')" :class="inputClass" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
            <input v-model="form.currency" required maxlength="3" class="uppercase font-mono" :class="inputClass" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.note') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
          <input v-model="form.note" maxlength="500" :class="inputClass" />
        </div>
        <label v-if="form.id !== null" class="flex items-center gap-2 text-sm">
          <input v-model="form.archived" type="checkbox" class="rounded border-neutral-300 text-primary-700" />
          {{ t('common.archived') }}
        </label>
        <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="account-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
