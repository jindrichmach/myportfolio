<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import {
  allocationApi, ALLOCATION_CATEGORIES,
  type Allocation, type AllocationCategory, type AllocationLine, type AllocationTargetsInput,
} from '@/api/allocation'
import { catalogApi, type Instrument } from '@/api/catalog'
import { portfolioApi, type Portfolio } from '@/api/portfolio'
import { apiErrorMessage } from '@/api/errors'
import { formatDec, formatMoney, formatPercent, formatSigned } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import Icon from '@/components/ui/Icon.vue'
import Badge from '@/components/ui/Badge.vue'
import SectionCard from '@/components/ui/SectionCard.vue'

const { t } = useI18n()
const { canManage } = useOwnerContext()
const toast = useToast()

const portfolios = ref<Portfolio[]>([])
const scope = ref<number | null>(null)
const data = ref<Allocation | null>(null)
const loading = ref(true)
const expanded = reactive<Record<string, boolean>>({})

const deposit = ref('')
const depositBusy = ref(false)
const depositError = ref('')

const cur = computed(() => data.value?.currency ?? 'CZK')
const money = (v: string | null | undefined, signed = false) =>
  signed ? formatSigned(v, cur.value, 0) : v === null || v === undefined ? '—' : formatMoney(Number(v), cur.value, 0)
const pct = (v: string | null | undefined, signed = false) => formatPercent(v, 2, signed)
const categoryLabel = (c: string) => t(`allocation.categories.${c}`)
const lineLabel = (l: AllocationLine) => l.label ?? categoryLabel(l.category)

async function load() {
  loading.value = true
  try {
    data.value = await allocationApi.get(scope.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  try {
    portfolios.value = (await portfolioApi.portfolios()).filter(p => !p.archived_at)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
  await load()
})
watch(scope, () => {
  deposit.value = ''
  load()
})

async function planDeposit() {
  depositBusy.value = true
  depositError.value = ''
  try {
    data.value = await allocationApi.get(scope.value, deposit.value.replace(/\s/g, '').replace(',', '.'))
  } catch (e) {
    depositError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    depositBusy.value = false
  }
}

const sells = computed(() => data.value?.rebalance.filter(l => l.action === 'sell') ?? [])
const buys = computed(() => data.value?.rebalance.filter(l => l.action === 'buy') ?? [])
const hasTaxable = computed(() => sells.value.some(l => l.positions?.some(p => p.taxable_quantity && Number(p.taxable_quantity) > 0)))

// ── Formulář cílů ───────────────────────────────────────────────────────
interface ClassForm {
  category: AllocationCategory
  target: string
  band: string
  split: boolean
  instruments: { instrument_id: number | ''; target: string }[]
}
const showForm = ref(false)
const formBusy = ref(false)
const formError = ref('')
const form = ref<ClassForm[]>([])
const catalog = reactive<Partial<Record<AllocationCategory, Instrument[]>>>({})
const SPLITTABLE: AllocationCategory[] = ['crypto', 'etf', 'stock']

const num = (v: string) => Number(v.replace(',', '.')) || 0
const classSum = computed(() => form.value.reduce((s, c) => s + num(c.target), 0))
const innerSum = (c: ClassForm) => c.instruments.reduce((s, i) => s + num(i.target), 0)

function openForm() {
  const a = data.value
  const targets = a?.targets ?? []
  form.value = ALLOCATION_CATEGORIES.map(category => {
    const ct = targets.find(x => x.category === category && x.instrument_id === null)
    const inner = targets.filter(x => x.category === category && x.instrument_id !== null)
    return {
      category,
      target: ct ? String(Number(ct.target_pct)) : '',
      band: ct?.band_pct ? String(Number(ct.band_pct)) : '',
      split: inner.length > 0,
      instruments: inner.map(x => ({ instrument_id: x.instrument_id as number, target: String(Number(x.target_pct)) })),
    }
  })
  // Bez cílů předvyplnit dnešní rozložení
  if (!targets.length && a) {
    for (const c of a.classes) {
      const f = form.value.find(x => x.category === c.category)
      if (f) f.target = String(Math.round(Number(c.pct)))
    }
  }
  for (const f of form.value) if (f.split) loadCatalog(f.category)
  formError.value = ''
  showForm.value = true
}

async function loadCatalog(category: AllocationCategory) {
  if (catalog[category]) return
  catalog[category] = await catalogApi.instruments({ asset_class: category })
}

function toggleSplit(c: ClassForm) {
  if (c.split && !c.instruments.length) {
    const held = data.value?.classes.find(x => x.category === c.category)?.items.filter(i => i.instrument_id !== null) ?? []
    c.instruments = held.map(i => ({ instrument_id: i.instrument_id as number, target: String(Math.round(Number(i.pct))) }))
    if (!c.instruments.length) c.instruments.push({ instrument_id: '', target: '' })
  }
  if (c.split) loadCatalog(c.category)
}

async function saveTargets() {
  formBusy.value = true
  formError.value = ''
  const payload: AllocationTargetsInput = {
    portfolio_id: scope.value,
    classes: form.value.filter(c => c.target.trim() !== '').map(c => ({
      category: c.category,
      target_pct: c.target.replace(',', '.'),
      band_pct: c.band.trim() !== '' ? c.band.replace(',', '.') : null,
      instruments: c.split
        ? c.instruments.filter(i => i.instrument_id !== '').map(i => ({ instrument_id: i.instrument_id as number, target_pct: i.target.replace(',', '.') || '0' }))
        : undefined,
    })),
  }
  try {
    data.value = await allocationApi.save(payload)
    deposit.value = ''
    showForm.value = false
    toast.success(t('allocation.saved'))
  } catch (e) {
    formError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    formBusy.value = false
  }
}

async function clearTargets() {
  if (!confirm(t('allocation.clear_confirm'))) return
  form.value = []
  await saveTargets()
}

const inputClass = 'w-full h-9 px-2 border border-field rounded-md bg-surface text-sm tabular-nums'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between mb-4 gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('allocation.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('allocation.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
        <select v-model="scope" class="flex-1 sm:flex-none min-w-0 h-9 px-2 border border-field rounded-md bg-surface text-sm">
          <option :value="null">{{ t('allocation.scope_total') }}</option>
          <option v-for="p in portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <button v-if="canManage" :disabled="!data" @click="openForm" class="cursor-pointer h-9 px-3 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action text-sm font-medium rounded-md shrink-0">
          {{ t('allocation.edit_targets') }}
        </button>
      </div>
    </div>

    <div v-if="loading && !data" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <div v-if="!data.has_targets" class="mb-4 rounded-md bg-primary-50 border border-primary-500/30 px-3 py-2 text-sm text-primary-700">
        {{ t('allocation.no_targets') }}
      </div>
      <div v-if="data.incomplete.length" class="mb-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
        {{ t('allocation.incomplete') }}
        <RouterLink v-for="(p, i) in data.incomplete" :key="p.position_id" :to="`/positions/${p.position_id}`" class="font-medium hover:underline">{{ i ? ', ' : ' ' }}{{ p.display_name }}</RouterLink>
      </div>

      <!-- Skutečnost vs. cíl -->
      <SectionCard :title="t('allocation.current_title')" :meta="`${t('allocation.total')} ${money(data.total)}`" flush class="mb-4">
        <p v-if="!data.classes.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('allocation.empty') }}</p>
        <div v-else class="divide-y divide-neutral-100">
          <div v-for="c in data.classes" :key="c.category" class="px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
              <button type="button" class="cursor-pointer flex items-center gap-2 font-medium text-left rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" :aria-expanded="c.items.length ? !!expanded[c.category] : undefined" @click="expanded[c.category] = !expanded[c.category]">
                <span class="w-4 shrink-0 text-neutral-500"><Icon v-if="c.items.length" name="chevron-down" class="w-4 h-4 transition-transform" :class="expanded[c.category] ? '' : '-rotate-90'" /></span>
                {{ categoryLabel(c.category) }}
                <Badge v-if="c.out_of_band" tone="warning">{{ t('allocation.out_of_band') }}</Badge>
              </button>
              <div class="flex flex-wrap items-baseline gap-x-4 text-sm tabular-nums">
                <span>{{ money(c.value) }}</span>
                <span class="font-semibold">{{ pct(c.pct) }}</span>
                <span v-if="c.target_pct !== null" class="text-neutral-500">{{ t('allocation.target') }} {{ pct(c.target_pct) }}<span class="text-xs"> ± {{ formatDec(c.band_pct, 2) }}</span></span>
                <span v-if="c.diff_amount !== null" :class="c.out_of_band ? 'text-warning-600 font-medium' : 'text-neutral-500'">{{ money(c.diff_amount, true) }}</span>
              </div>
            </div>
            <div class="relative h-2 mt-2 rounded-full bg-neutral-100">
              <div class="absolute inset-y-0 left-0 rounded-full" :class="c.out_of_band ? 'bg-warning-500' : 'bg-primary-500'" :style="{ width: `${Math.min(100, Number(c.pct))}%` }"></div>
              <div v-if="c.target_pct !== null" class="absolute -top-1 -bottom-1 w-0.5 bg-neutral-800" :style="{ left: `${Math.min(100, Number(c.target_pct))}%` }" :title="t('allocation.target')"></div>
            </div>
            <table v-if="expanded[c.category] && c.items.length" class="w-full text-sm mt-3">
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(i, k) in c.items" :key="k">
                  <td class="py-1.5 pl-5">
                    <span class="font-medium">{{ i.label }}</span>
                    <span v-if="i.name && i.name !== i.label" class="text-neutral-500 text-xs"> · {{ i.name }}</span>
                  </td>
                  <td class="py-1.5 text-right tabular-nums">{{ money(i.value) }}</td>
                  <td class="py-1.5 text-right tabular-nums w-24">{{ pct(i.pct) }}</td>
                  <td class="py-1.5 text-right tabular-nums text-neutral-500 w-28 hidden sm:table-cell">{{ i.target_pct !== null ? `${t('allocation.target')} ${pct(i.target_pct)}` : '' }}</td>
                  <td class="py-1.5 text-right tabular-nums text-neutral-500 w-28 hidden sm:table-cell">{{ i.diff_amount !== null ? money(i.diff_amount, true) : '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <template v-if="data.has_targets" #footer><p class="text-xs text-neutral-500">{{ t('allocation.diff_hint') }}</p></template>
      </SectionCard>

      <div v-if="data.has_targets" class="grid lg:grid-cols-2 gap-4">
        <!-- Návrh vkladu -->
        <SectionCard :title="t('allocation.deposit_title')" :subtitle="t('allocation.deposit_hint')">
          <form class="flex gap-2" @submit.prevent="planDeposit">
            <input v-model="deposit" inputmode="decimal" required :placeholder="t('allocation.deposit_placeholder', { currency: cur })" :class="inputClass" />
            <button type="submit" :disabled="depositBusy" class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 shrink-0">
              {{ t('allocation.deposit_plan') }}
            </button>
          </form>
          <p v-if="depositError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mt-2">{{ depositError }}</p>
          <ul v-if="data.deposit" class="mt-3 divide-y divide-neutral-100 text-sm">
            <li v-for="(l, i) in data.deposit.lines" :key="i" class="py-1.5 flex justify-between gap-3">
              <span><Badge tone="success">{{ t('allocation.buy') }}</Badge> {{ lineLabel(l) }}
                <span v-if="l.label" class="text-xs text-neutral-500">· {{ categoryLabel(l.category) }}</span></span>
              <span class="tabular-nums font-medium">{{ money(l.amount) }}</span>
            </li>
          </ul>
        </SectionCard>

        <!-- Plný rebalancing -->
        <SectionCard :title="t('allocation.rebalance_title')" :subtitle="t('allocation.rebalance_hint')">
          <p v-if="!data.rebalance.length" class="text-sm text-neutral-500">{{ t('allocation.balanced') }}</p>
          <ul v-else class="divide-y divide-neutral-100 text-sm">
            <li v-for="(l, i) in [...sells, ...buys]" :key="i" class="py-1.5">
              <div class="flex justify-between gap-3">
                <span>
                  <Badge :tone="l.action === 'sell' ? 'danger' : 'success'">{{ t(`allocation.${l.action}`) }}</Badge>
                  {{ lineLabel(l) }}
                  <span v-if="l.label" class="text-xs text-neutral-500">· {{ categoryLabel(l.category) }}</span>
                </span>
                <span class="tabular-nums font-medium">{{ money(l.amount) }}</span>
              </div>
              <ul v-if="l.positions?.length" class="mt-1 ml-4 space-y-0.5 text-xs text-neutral-500">
                <li v-for="p in l.positions" :key="p.position_id">
                  <RouterLink :to="`/positions/${p.position_id}`" class="hover:underline">{{ p.label }}</RouterLink>:
                  {{ money(p.amount) }}<template v-if="p.quantity"> · {{ formatDec(p.quantity) }} {{ t('allocation.pcs') }}</template>
                  <span v-if="p.taxable_quantity && Number(p.taxable_quantity) > 0" class="text-warning-600 font-medium">
                    · {{ t('allocation.taxable', { qty: formatDec(p.taxable_quantity) }) }}
                  </span>
                </li>
              </ul>
            </li>
          </ul>
          <p v-if="hasTaxable" class="mt-3 text-xs rounded-md bg-warning-50 border border-warning-500/40 text-warning-600 px-3 py-2">{{ t('allocation.tax_note') }}</p>
        </SectionCard>
      </div>
    </template>

    <Modal v-if="showForm" :title="t('allocation.edit_targets')" width-class="max-w-2xl" @close="showForm = false">
      <form id="allocation-form" class="space-y-3" @submit.prevent="saveTargets">
        <p class="text-xs text-neutral-500">{{ t('allocation.form_hint') }}</p>
        <div class="grid grid-cols-[1fr_5.5rem_5.5rem] gap-2 text-xs text-neutral-500 font-medium">
          <span>{{ t('allocation.class') }}</span><span>{{ t('allocation.target_pct') }}</span><span>{{ t('allocation.band_pct') }}</span>
        </div>
        <div v-for="c in form" :key="c.category" class="border-t border-neutral-100 pt-2">
          <div class="grid grid-cols-[1fr_5.5rem_5.5rem] gap-2 items-center">
            <div class="text-sm">
              <span class="font-medium">{{ categoryLabel(c.category) }}</span>
              <label v-if="SPLITTABLE.includes(c.category)" class="ml-3 inline-flex items-center gap-1 text-xs text-neutral-600">
                <input v-model="c.split" type="checkbox" class="rounded border-field text-primary-700" @change="toggleSplit(c)" />
                {{ t('allocation.split') }}
              </label>
            </div>
            <input v-model="c.target" inputmode="decimal" placeholder="0" :class="inputClass" />
            <input v-model="c.band" inputmode="decimal" placeholder="5" :class="inputClass" />
          </div>
          <div v-if="c.split" class="mt-2 ml-4 space-y-1.5">
            <div v-for="(it, k) in c.instruments" :key="k" class="grid grid-cols-[1fr_5.5rem_2rem] gap-2 items-center">
              <select v-model="it.instrument_id" :class="inputClass">
                <option value="" disabled>—</option>
                <option v-for="ins in catalog[c.category] ?? []" :key="ins.id" :value="ins.id">{{ ins.symbol }} · {{ ins.name }}</option>
              </select>
              <input v-model="it.target" inputmode="decimal" placeholder="0" :class="inputClass" />
              <button type="button" class="cursor-pointer text-neutral-400 hover:text-danger-500" :aria-label="t('common.delete')" @click="c.instruments.splice(k, 1)"><Icon name="x" class="w-4 h-4" /></button>
            </div>
            <div class="flex justify-between text-xs">
              <button type="button" class="cursor-pointer text-primary-700 hover:underline" @click="c.instruments.push({ instrument_id: '', target: '' })">{{ t('allocation.add_instrument') }}</button>
              <span :class="Math.abs(innerSum(c) - 100) < 0.005 ? 'text-neutral-500' : 'text-danger-500'">{{ t('allocation.sum', { sum: formatDec(String(innerSum(c)), 2) }) }}</span>
            </div>
          </div>
        </div>
        <p class="text-sm text-right" :class="Math.abs(classSum - 100) < 0.005 ? 'text-neutral-600' : 'text-danger-500 font-medium'">
          {{ t('allocation.sum', { sum: formatDec(String(classSum), 2) }) }}
        </p>
        <p v-if="formError" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ formError }}</p>
      </form>
      <template #footer>
        <button v-if="data?.has_targets" type="button" class="cursor-pointer mr-auto px-3 h-9 text-sm text-danger-500 hover:underline" @click="clearTargets">{{ t('allocation.clear') }}</button>
        <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="allocation-form" :disabled="formBusy" class="cursor-pointer px-4 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
