<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconUploadModal from '@/components/catalog/IconUploadModal.vue'
import IconButton from '@/components/ui/IconButton.vue'
import DataGrid from '@/components/ui/DataGrid.vue'
import type { DataColumn } from '@/components/ui/DataTable.vue'
import type { GridFilter } from '@/components/ui/grid'
import { catalogApi, INSTITUTION_TYPES, type Institution, type InstitutionType } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const auth = useAuthStore()
const toast = useToast()

const rows = ref<Institution[]>([])
const loading = ref(true)
const showForm = ref(false)
const iconFor = ref<Institution | null>(null)
const busy = ref(false)
const error = ref('')
const form = reactive({
  id: null as number | null,
  name: '',
  type: 'exchange' as InstitutionType,
  website: '',
  is_active: true,
})

async function load() {
  loading.value = true
  try { rows.value = await catalogApi.institutions(auth.isAdmin) }
  finally { loading.value = false }
}
onMounted(load)

function openCreate() {
  Object.assign(form, { id: null, name: '', type: 'exchange', website: '', is_active: true })
  error.value = ''
  showForm.value = true
}
function openEdit(row: Institution) {
  Object.assign(form, { id: row.id, name: row.name, type: row.type, website: row.website ?? '', is_active: row.is_active })
  error.value = ''
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  const payload = { name: form.name.trim(), type: form.type, website: form.website.trim() || null, is_active: form.is_active }
  try {
    if (form.id === null) await catalogApi.createInstitution(payload)
    else await catalogApi.updateInstitution(form.id, payload)
    showForm.value = false
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove(row: Institution) {
  if (!confirm(t('common.confirm_delete', { name: row.name }))) return
  try {
    await catalogApi.deleteInstitution(row.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

const columns = computed<DataColumn[]>(() => [
  { key: 'name', label: t('common.name'), primary: true },
  { key: 'type', label: t('common.type') },
  { key: 'website', label: t('institutions.website'), hideBelow: 'lg' },
])
const institutionKey = (i: Institution) => i.id

const filters = computed<GridFilter[]>(() => [
  { key: 'q', type: 'text', label: t('grid.search'), placeholder: t('institutions.search_placeholder'), column: 'name' },
  { key: 'type', type: 'select', label: t('common.type'), allLabel: t('institutions.all_types'), column: 'type',
    options: INSTITUTION_TYPES.map(tp => ({ value: tp, label: t(`institutions.types.${tp}`) })) },
])
const inactiveClass = (i: Institution) => (i.is_active ? '' : 'opacity-50')

const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('institutions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('institutions.subtitle') }}</p>
      </div>
      <button @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">
        {{ t('institutions.new') }}
      </button>
    </div>

    <DataGrid :columns="columns" :rows="rows" :row-key="institutionKey" :row-class="inactiveClass" :filters="filters"
      :loading="loading" :empty-text="t('institutions.empty')">
        <template #cell-name="{ row: r }">
          <span class="inline-flex items-center gap-2 font-medium"><EntityIcon :src="r.icon_url" :name="r.name" :size="28" />{{ r.name }}</span>
        </template>
        <template #cell-type="{ row: r }">{{ t(`institutions.types.${r.type}`) }}</template>
        <template #cell-website="{ row: r }">
          <a v-if="r.website" :href="r.website" target="_blank" rel="noopener" class="text-primary-700 hover:underline break-all">{{ r.website.replace(/^https?:\/\//, '') }}</a>
        </template>
        <template #actions="{ row: r }">
          <span class="inline-flex gap-1">
            <IconButton v-if="r.can_edit_icon" icon="image" :label="t('icons.change')" @click="iconFor = r" />
            <template v-if="auth.isAdmin">
              <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(r)" />
              <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="remove(r)" />
            </template>
          </span>
        </template>
    </DataGrid>

    <IconUploadModal v-if="iconFor" kind="institutions" :id="iconFor.id" :name="iconFor.name" :icon-url="iconFor.icon_url"
                     @close="iconFor = null" @changed="url => { if (iconFor) iconFor.icon_url = url }" />

    <Modal v-if="showForm" :title="form.id === null ? t('institutions.new') : t('institutions.edit_title')" width-class="max-w-md" @close="showForm = false">
      <form id="institution-form" class="space-y-3" @submit.prevent="save">
        <div>
          <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
          <input :id="fid('name')" v-model="form.name" required maxlength="120" :class="inputClass" />
        </div>
        <div>
          <label :for="fid('type')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.type') }}</label>
          <select :id="fid('type')" v-model="form.type" :class="inputClass">
            <option v-for="tp in INSTITUTION_TYPES" :key="tp" :value="tp">{{ t(`institutions.types.${tp}`) }}</option>
          </select>
        </div>
        <div>
          <label :for="fid('website')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('institutions.website') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
          <input :id="fid('website')" v-model="form.website" type="url" placeholder="https://" :class="inputClass" />
        </div>
        <label v-if="form.id !== null" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="rounded border-field text-primary-700" />
          {{ t('common.active') }}
        </label>
        <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="institution-form" :disabled="busy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
