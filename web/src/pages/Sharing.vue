<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import IconButton from '@/components/ui/IconButton.vue'
import { sharesApi, SHARE_SECTIONS, type Share, type SharePermission, type ShareScope, type ShareSection } from '@/api/shares'
import { portfolioApi, type Portfolio } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { useOwnerContext } from '@/composables/useOwnerContext'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const owner = useOwnerContext()

const granted = ref<Share[]>([])
const received = ref<Share[]>([])
const activity = ref<Awaited<ReturnType<typeof sharesApi.activity>>>([])
const portfolios = ref<Portfolio[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const [list, act, pf] = await Promise.all([sharesApi.list(), sharesApi.activity(), portfolioApi.portfolios()])
    granted.value = list.granted
    received.value = list.received
    activity.value = act
    portfolios.value = pf.filter(p => !p.archived_at)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ── Formulář sdílení ────────────────────────────────────────────────────
const showForm = ref(false)
const formBusy = ref(false)
const formError = ref('')
const editId = ref<number | null>(null)
const form = reactive({ email: '', permission: 'read' as SharePermission, scope: 'all' as ShareScope, portfolio_ids: [] as number[], sections: [] as ShareSection[] })

function openCreate() {
  Object.assign(form, { email: '', permission: 'read', scope: 'all', portfolio_ids: [], sections: [] })
  editId.value = null
  formError.value = ''
  showForm.value = true
}
function openEdit(s: Share) {
  Object.assign(form, { email: s.invite_email ?? '', permission: s.permission, scope: s.scope, portfolio_ids: s.portfolios.map(p => p.id), sections: [...s.sections] })
  editId.value = s.id
  formError.value = ''
  showForm.value = true
}
async function save() {
  formBusy.value = true
  formError.value = ''
  const payload = { permission: form.permission, scope: form.scope, portfolio_ids: form.scope === 'portfolios' ? form.portfolio_ids : [],
    sections: form.scope === 'all' ? form.sections : [] }
  try {
    if (editId.value === null) {
      const r = await sharesApi.create({ ...payload, email: form.email.trim() })
      toast.success(t(r.invited ? 'sharing.invited' : 'sharing.shared', { email: form.email.trim() }))
      if (!r.mail_sent) toast.error(t('sharing.mail_failed'))
    } else {
      await sharesApi.update(editId.value, payload)
    }
    showForm.value = false
    await load()
  } catch (e) {
    formError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    formBusy.value = false
  }
}

async function remove(s: Share, leaving: boolean) {
  if (!confirm(t(leaving ? 'sharing.leave_confirm' : 'sharing.revoke_confirm', { name: leaving ? s.owner_name : (s.grantee_name ?? s.invite_email) }))) return
  try {
    await sharesApi.remove(s.id)
    if (leaving && owner.ownerId.value === s.owner_user_id) owner.switchTo(null)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

async function resend(s: Share) {
  try {
    const r = await sharesApi.resend(s.id)
    r.mail_sent ? toast.success(t('sharing.resent')) : toast.error(t('sharing.mail_failed'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

function scopeText(s: Share): string {
  return s.scope === 'all' ? t('sharing.scope_all') : s.portfolios.map(p => p.name).join(', ')
}
function actionText(a: { method?: string; path?: string }): string {
  const entity = (a.path ?? '').match(/^\/api\/(transactions|transfers|positions|valuations|imports)/)?.[1] ?? 'other'
  return t(`sharing.activity_methods.${a.method ?? 'POST'}`) + ' ' + t(`sharing.activity_entities.${entity}`)
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('sharing.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('sharing.subtitle') }}</p>
      </div>
      <button @click="openCreate" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0">{{ t('sharing.new') }}</button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">{{ t('sharing.granted_title') }}</h2>
        <EmptyState v-if="!granted.length" :title="t('sharing.granted_empty')" :message="t('sharing.granted_hint')" />
        <div v-else class="divide-y divide-neutral-100">
          <div v-for="s in granted" :key="s.id" class="px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ s.grantee_name ?? s.invite_email }}</span>
                <span v-if="s.grantee_name" class="text-xs text-neutral-500">{{ s.invite_email }}</span>
                <span v-if="s.pending" class="text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">{{ t('sharing.pending', { date: formatDate(s.invite_expires_at) }) }}</span>
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="s.permission === 'write' ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">{{ t(`sharing.permissions.${s.permission}`) }}</span>
              </div>
              <p class="text-xs text-neutral-500 mt-0.5">
                {{ scopeText(s) }}<template v-if="s.sections.length"> · {{ s.sections.map(x => t(`sharing.sections.${x}`)).join(', ') }}</template>
              </p>
            </div>
            <div class="inline-flex gap-1">
              <IconButton v-if="s.pending" icon="refresh" :label="t('sharing.resend')" @click="resend(s)" />
              <IconButton icon="edit" :label="t('common.edit')" @click="openEdit(s)" />
              <IconButton icon="trash" variant="danger" :label="t('sharing.revoke')" @click="remove(s, false)" />
            </div>
          </div>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">{{ t('sharing.received_title') }}</h2>
        <p v-if="!received.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('sharing.received_empty') }}</p>
        <div v-else class="divide-y divide-neutral-100">
          <div v-for="s in received" :key="s.id" class="px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ s.owner_name }}</span>
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="s.permission === 'write' ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">{{ t(`sharing.permissions.${s.permission}`) }}</span>
              </div>
              <p class="text-xs text-neutral-500 mt-0.5">
                {{ scopeText(s) }}<template v-if="s.sections.length"> · {{ s.sections.map(x => t(`sharing.sections.${x}`)).join(', ') }}</template>
              </p>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50" @click="owner.switchTo(s.owner_user_id)">{{ t('sharing.open') }}</button>
              <IconButton icon="trash" variant="danger" :label="t('sharing.leave')" @click="remove(s, true)" />
            </div>
          </div>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-neutral-50">{{ t('sharing.activity_title') }}</h2>
        <p v-if="!activity.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('sharing.activity_empty') }}</p>
        <ul v-else class="divide-y divide-neutral-100 text-sm">
          <li v-for="a in activity" :key="a.id" class="px-4 py-2 flex flex-wrap justify-between gap-2">
            <span><span class="font-medium">{{ a.actor_name }}</span> {{ actionText(a) }}</span>
            <span class="text-xs text-neutral-500 tabular-nums">{{ formatDateTime(a.created_at) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <Modal v-if="showForm" :title="editId === null ? t('sharing.new') : t('sharing.edit_title')" width-class="max-w-lg" @close="showForm = false">
      <form id="share-form" class="space-y-4" @submit.prevent="save">
        <div v-if="editId === null">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('sharing.email') }}</label>
          <input v-model="form.email" type="email" required maxlength="190" autocomplete="off" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          <p class="text-xs text-neutral-500 mt-1">{{ t('sharing.email_hint') }}</p>
        </div>
        <fieldset class="space-y-2">
          <legend class="text-sm font-medium text-neutral-700 mb-1">{{ t('sharing.permission') }}</legend>
          <label v-for="p in (['read', 'write'] as const)" :key="p" class="flex items-start gap-2 text-sm">
            <input v-model="form.permission" type="radio" :value="p" class="mt-0.5 border-neutral-300 text-primary-700" />
            <span>{{ t(`sharing.permissions.${p}`) }}<span class="block text-xs text-neutral-500">{{ t(`sharing.permission_hints.${p}`) }}</span></span>
          </label>
        </fieldset>
        <fieldset class="space-y-2">
          <legend class="text-sm font-medium text-neutral-700 mb-1">{{ t('sharing.scope') }}</legend>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.scope" type="radio" value="all" class="border-neutral-300 text-primary-700" /> {{ t('sharing.scope_all') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.scope" type="radio" value="portfolios" class="border-neutral-300 text-primary-700" /> {{ t('sharing.scope_portfolios') }}
          </label>
          <div v-if="form.scope === 'portfolios'" class="ml-6 space-y-1">
            <label v-for="p in portfolios" :key="p.id" class="flex items-center gap-2 text-sm">
              <input v-model="form.portfolio_ids" type="checkbox" :value="p.id" class="rounded border-neutral-300 text-primary-700" /> {{ p.name }}
            </label>
          </div>
        </fieldset>
        <fieldset v-if="form.scope === 'all'" class="space-y-2">
          <legend class="text-sm font-medium text-neutral-700 mb-1">{{ t('sharing.sections_title') }}</legend>
          <label v-for="s in SHARE_SECTIONS" :key="s" class="flex items-center gap-2 text-sm">
            <input v-model="form.sections" type="checkbox" :value="s" class="rounded border-neutral-300 text-primary-700" /> {{ t(`sharing.sections.${s}`) }}
          </label>
          <p class="text-xs text-neutral-500">{{ t('sharing.sections_hint') }}</p>
        </fieldset>
        <p v-if="formError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="share-form" :disabled="formBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
