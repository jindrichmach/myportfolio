<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type AdminUser } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Modal from '@/components/ui/Modal.vue'
import DataGrid from '@/components/ui/DataGrid.vue'
import type { DataColumn } from '@/components/ui/DataTable.vue'
import type { GridFilter, GridFilterValues } from '@/components/ui/grid'
import { useFieldId } from '@/composables/useFieldId'

const { t } = useI18n()
const fid = useFieldId()
const auth = useAuthStore()
const toast = useToast()

const users = ref<AdminUser[]>([])
const loading = ref(false)
const error = ref('')

const activeAdminCount = computed(() => users.value.filter(u => u.role === 'admin' && u.is_active).length)
function isLastAdmin(u: AdminUser): boolean {
  return u.role === 'admin' && u.is_active && activeAdminCount.value <= 1
}

const showForm = ref(false)
useHotkey('escape', () => { if (showForm.value) showForm.value = false })

const form = reactive({
  id: null as number | null,
  email: '',
  name: '',
  role: 'user' as AdminUser['role'],
  locale: 'cs' as 'cs' | 'en',
  is_active: true,
  password: '',
})

async function load() {
  loading.value = true
  try { users.value = await adminApi.listUsers() }
  finally { loading.value = false }
}
onMounted(load)

function openCreate() {
  Object.assign(form, { id: null, email: '', name: '', role: 'user', locale: 'cs', is_active: true, password: '' })
  showForm.value = true
}
function openEdit(u: AdminUser) {
  Object.assign(form, { id: u.id, email: u.email, name: u.name, role: u.role, locale: u.locale, is_active: u.is_active, password: '' })
  showForm.value = true
}

async function save() {
  error.value = ''
  // Client-side guard: nesmí jít odebrat poslední admin
  if (form.id !== null) {
    const original = users.value.find(u => u.id === form.id)
    if (original && original.role === 'admin' && original.is_active) {
      const losingAdmin = form.role !== 'admin' || !form.is_active
      if (losingAdmin && activeAdminCount.value <= 1) {
        error.value = t('users.last_admin_form')
        return
      }
    }
  }
  try {
    if (form.id === null) {
      if (!form.password) { error.value = t('users.password_required'); return }
      await adminApi.createUser({
        email: form.email, name: form.name, role: form.role, locale: form.locale, password: form.password,
      })
    } else {
      const payload: Record<string, unknown> = {
        name: form.name, role: form.role, locale: form.locale, is_active: form.is_active,
      }
      if (form.password) payload.password = form.password
      await adminApi.updateUser(form.id, payload)
    }
    showForm.value = false
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

async function deactivate(u: AdminUser) {
  if (isLastAdmin(u)) {
    toast.warning(t('users.last_admin_alert'))
    return
  }
  if (!confirm(t('users.deactivate_confirm', { email: u.email }))) return
  try {
    await adminApi.deleteUser(u.id)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

const columns = computed((): DataColumn[] => [
  { key: 'email', label: t('settings.email'), mono: true },
  { key: 'name', label: t('users.name'), primary: true },
  { key: 'role', label: t('users.role'), align: 'center' },
  { key: 'locale', label: t('users.locale'), align: 'center', mono: true },
  { key: 'is_active', label: t('users.active'), align: 'center' },
  { key: 'last_login_at', label: t('users.last_login'), hideBelow: 'lg' },
])

const filters = computed<GridFilter[]>(() => [
  { key: 'q', type: 'text', label: t('grid.search'), placeholder: t('users.search_placeholder') },
  { key: 'role', type: 'select', label: t('users.role'), allLabel: t('users.all_roles'), options: [{ value: 'admin', label: 'admin' }, { value: 'user', label: 'user' }] },
  { key: 'status', type: 'select', label: t('users.status'), allLabel: t('users.all_statuses'), options: [{ value: 'active', label: t('users.active') }, { value: 'inactive', label: t('users.inactive') }] },
])

/** Klientské filtrování: hledání v e-mailu a jméně, role a stav dohromady. */
function matchesUser(u: AdminUser, v: GridFilterValues): boolean {
  const q = String(v.q ?? '').trim().toLowerCase()
  if (q && !u.email.toLowerCase().includes(q) && !u.name.toLowerCase().includes(q)) return false
  if (v.role && u.role !== v.role) return false
  if (v.status === 'active' && !u.is_active) return false
  if (v.status === 'inactive' && u.is_active) return false
  return true
}

function roleBadge(role: string): string {
  if (role === 'admin') return 'bg-primary-100 text-primary-700'
  return 'bg-neutral-100 text-neutral-600'
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('users.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('users.subtitle') }}</p>
      </div>
      <button @click="openCreate"
        class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0 whitespace-nowrap">
        {{ t('users.new') }}
      </button>
    </div>

    <DataGrid :columns="columns" :rows="users" :row-key="(u: AdminUser) => u.id" :filters="filters"
      :filter-fn="matchesUser" :loading="loading" empty-icon="users" :row-class="(u: AdminUser) => (u.is_active ? '' : 'text-neutral-400')">
      <template #cell-role="{ row }">
        <span class="text-xs px-2 py-0.5 rounded font-medium" :class="roleBadge(row.role)">{{ row.role }}</span>
        <span v-if="isLastAdmin(row)" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-600" :title="t('users.is_last_admin_lock')"><Icon name="lock" class="w-3.5 h-3.5 align-[-2px]" /></span>
      </template>
      <template #cell-is_active="{ row }">
        <span v-if="row.is_active" class="text-success-600"><Icon name="check" class="w-4 h-4" :label="t('users.active')" /></span>
        <span v-else class="text-neutral-400">—</span>
      </template>
      <template #cell-last_login_at="{ row }">
        <span class="text-xs text-neutral-500">{{ row.last_login_at?.replace('T', ' ').slice(0, 19) || '—' }}</span>
      </template>
      <template #actions="{ row }">
        <div class="inline-flex gap-1">
          <IconButton v-if="auth.user?.id === row.id" icon="shield" :label="t('auth.totp_2fa')" to="/profile/totp" />
          <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(row)" />
          <IconButton v-if="row.is_active" icon="ban" show-label variant="danger" :label="t('users.deactivate')" :disabled="isLastAdmin(row)"
            :tooltip="isLastAdmin(row) ? t('users.is_last_admin_lock') : undefined" @click="deactivate(row)" />
        </div>
      </template>
    </DataGrid>

    <!-- Modal -->
    <Modal v-if="showForm" :title="form.id === null ? t('users.new_title') : t('users.edit_title', { email: form.email })" width-class="max-w-md" @close="showForm = false">
        <form id="user-form" class="space-y-3" @submit.prevent="save">
          <div v-if="form.id === null">
            <label :for="fid('email')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.email_required') }}</label>
            <input :id="fid('email')" v-model="form.email" type="email" class="w-full h-10 px-3 border border-field rounded-md bg-surface text-sm" />
          </div>
          <div>
            <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.name_required') }}</label>
            <input :id="fid('name')" v-model="form.name" type="text" class="w-full h-10 px-3 border border-field rounded-md bg-surface text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label :for="fid('role')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.role') }}</label>
              <select :id="fid('role')" v-model="form.role" class="w-full h-10 px-3 border border-field rounded-md text-sm bg-surface">
                <option value="admin">admin</option>
                <option value="user">user</option>
              </select>
            </div>
            <div>
              <label :for="fid('locale')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.language') }}</label>
              <select :id="fid('locale')" v-model="form.locale" class="w-full h-10 px-3 border border-field rounded-md text-sm bg-surface">
                <option value="cs">cs</option>
                <option value="en">en</option>
              </select>
            </div>
          </div>
          <div v-if="form.id !== null">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" class="rounded border-field text-primary-700" />
              {{ t('common.active') }}
            </label>
          </div>
          <div>
            <label :for="fid('password')" class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('auth.password') }} {{ form.id === null ? '*' : t('users.password_change_hint') }}
            </label>
            <input :id="fid('password')" v-model="form.password" type="password" autocomplete="new-password"
              class="w-full h-10 px-3 border border-field rounded-md bg-surface text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('users.password_min') }}</p>
          </div>
          <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
        </form>
      <template #footer>
            <button type="button" @click="showForm = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button type="submit" form="user-form" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover text-on-action font-medium rounded-md">
              {{ form.id === null ? t('common.create') : t('common.save') }}
            </button>
      </template>
    </Modal>
  </div>
</template>
