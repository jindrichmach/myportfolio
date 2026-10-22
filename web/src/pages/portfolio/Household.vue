<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AllocationDonut from '@/components/portfolio/AllocationDonut.vue'
import KpiTile from '@/components/ui/KpiTile.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import Badge from '@/components/ui/Badge.vue'
import { sharesApi, type Household, type HouseholdMember } from '@/api/shares'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatPercent, formatSigned } from '@/composables/useFormat'
import { gainClass } from '@/utils/tone'
import { useOwnerContext } from '@/composables/useOwnerContext'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const owner = useOwnerContext()

const data = ref<Household | null>(null)
const loading = ref(true)
onMounted(async () => {
  try {
    data.value = await sharesApi.household()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
})

const cur = computed(() => data.value?.currency ?? 'CZK')
const money = (v: string | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(Number(v), cur.value, 0))
const signed = (v: string | null | undefined) => formatSigned(v, cur.value, 0)
const categoryLabel = (l: string) => t(`instruments.classes.${l}`)

const tiles = computed(() => {
  const x = data.value?.totals
  if (!x) return []
  return [
    { key: 'value', label: t('net_worth.investments'), text: money(x.value), cls: '' },
    { key: 'return', label: t('valuation.total_return'), text: signed(x.total_return), cls: gainClass(x.total_return) },
    { key: 'assets', label: t('net_worth.assets'), text: money(x.assets), cls: '' },
    { key: 'liab', label: t('net_worth.liabilities'), text: formatSigned(String(-Number(x.liabilities)), cur.value, 0), cls: Number(x.liabilities) > 0 ? 'text-danger-500' : '' },
  ]
})

const columns = computed<DataColumn[]>(() => [
  { key: 'name', label: t('common.name'), primary: true },
  { key: 'value', label: t('net_worth.investments'), align: 'right' },
  { key: 'total_return', label: t('valuation.total_return'), align: 'right' },
  { key: 'net_worth', label: t('net_worth.net_worth'), align: 'right' },
])
const memberKey = (m: HouseholdMember) => m.user_id
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('household.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('household.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:p-5 mb-4">
        <KpiTile bare hero :label="t('net_worth.net_worth')" :value="money(data.totals.net_worth)" />
        <p v-if="data.net_worth_partial" class="mt-1 text-xs text-neutral-500">{{ t('household.partial') }}</p>
        <div class="mt-4 pt-4 border-t border-neutral-200 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-3">
          <KpiTile v-for="k in tiles" :key="k.key" bare :label="k.label" :value="k.text" :value-class="k.cls" />
        </div>
      </section>

      <div class="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <SectionCard :title="t('household.members')" flush>
          <DataTable :columns="columns" :rows="data.members" :row-key="memberKey">
            <template #cell-name="{ row: m }">
              <span class="font-medium">{{ m.me ? t('household.me') : m.name }}</span>
              <Badge v-if="m.scope === 'portfolios'" class="ml-1.5">{{ t('household.some_portfolios') }}</Badge>
              <button v-if="!m.me" type="button" class="cursor-pointer block text-xs text-primary-700 hover:underline" @click="owner.switchTo(m.user_id)">{{ t('sharing.open') }}</button>
            </template>
            <template #cell-value="{ row: m }">{{ money(m.value) }}</template>
            <template #cell-total_return="{ row: m }">
              <span :class="gainClass(m.total_return)">{{ signed(m.total_return) }}<span v-if="m.return_pct !== null" class="block text-xs">{{ formatPercent(m.return_pct, 2, true) }}</span></span>
            </template>
            <template #cell-net_worth="{ row: m }">{{ m.net_worth !== null ? money(m.net_worth) : '—' }}</template>
          </DataTable>
        </SectionCard>
        <SectionCard :title="t('dashboard.allocation')">
          <AllocationDonut :slices="data.allocation" :currency="data.currency" :label-for="categoryLabel" />
        </SectionCard>
      </div>
    </template>
  </div>
</template>
