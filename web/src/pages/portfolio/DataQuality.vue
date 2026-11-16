<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import Badge from '@/components/ui/Badge.vue'
import { dataQualityApi, type DataIssue, type DataQuality } from '@/api/dataQuality'
import { apiErrorMessage } from '@/api/errors'
import { formatDate } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'

/**
 * Kvalita dat: co může zkreslit hodnoty a daně — chybějící ceny a kurzy, neúplné nabývací ceny lotů,
 * chyby bankovních avíz. Ke každému oddílu odkaz, kde se problém opraví. Vždy vlastní data přihlášeného.
 */
const { t, te } = useI18n()
const auth = useAuthStore()

const data = ref<DataQuality | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    data.value = await dataQualityApi.get()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
})

const noticeLabel = (i: DataIssue) => i.kind === 'imap_error'
  ? t('data_quality.imap_error')
  : te(`bank_notices.statuses.${i.kind.replace('notice_', '')}`) ? t(`bank_notices.statuses.${i.kind.replace('notice_', '')}`) : i.kind
const costLabel = (code: string) => te(`tax.cost_warnings.${code}`) ? t(`tax.cost_warnings.${code}`) : t(`data_quality.codes.${code}`)
const rowClass = 'flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm'
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('data_quality.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('data_quality.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-4 py-3 text-sm text-danger-500">{{ error }}</div>
    <div v-else-if="data && data.total === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('data_quality.ok_title')" :message="t('data_quality.ok_hint')" />
    </div>
    <template v-else-if="data">
      <SectionCard v-if="data.costs.length" :title="t('data_quality.costs')" flush class="mb-4">
        <template #actions><Badge tone="warning">{{ data.costs.length }}</Badge></template>
        <p class="px-4 pt-3 text-xs text-neutral-500">{{ t('data_quality.costs_hint') }}</p>
        <ul class="divide-y divide-neutral-200">
          <li v-for="c in data.costs" :key="`${c.transaction_id}-${c.code}`" :class="rowClass">
            <span class="min-w-0">
              <RouterLink :to="`/positions/${c.position_id}`" class="font-medium text-primary-700 hover:underline">{{ c.symbol }}</RouterLink>
              <span class="text-neutral-500"> · {{ t(`tx.types.${c.type}`) }} {{ formatDate(c.executed_at) }}</span>
            </span>
            <span class="text-warning-600">{{ costLabel(c.code) }}</span>
          </li>
        </ul>
      </SectionCard>

      <SectionCard v-if="data.prices.length" :title="t('data_quality.prices')" flush class="mb-4">
        <template #actions><Badge tone="warning">{{ data.prices.length }}</Badge></template>
        <p class="px-4 pt-3 text-xs text-neutral-500">
          {{ t('data_quality.prices_hint') }}
          <RouterLink :to="auth.user?.role === 'admin' ? '/admin/market' : '/catalog/instruments'" class="text-primary-700 hover:underline">{{ auth.user?.role === 'admin' ? t('nav.market') : t('nav.instruments') }} →</RouterLink>
        </p>
        <ul class="divide-y divide-neutral-200">
          <li v-for="p in data.prices" :key="`${p.kind}-${p.symbol}`" :class="rowClass">
            <span class="min-w-0"><span class="font-medium">{{ p.symbol }}</span> <span v-if="p.name" class="text-neutral-500">· {{ p.name }}</span></span>
            <span class="text-warning-600">{{ p.kind === 'missing_price' ? t('data_quality.missing_price') : t('data_quality.stale_price', { date: formatDate(p.date) }) }}</span>
          </li>
        </ul>
      </SectionCard>

      <SectionCard v-if="data.fx.length" :title="t('data_quality.fx')" flush class="mb-4">
        <ul class="divide-y divide-neutral-200">
          <li v-for="f in data.fx" :key="f.date ?? ''" :class="rowClass">
            <span>{{ t('data_quality.stale_fx', { date: formatDate(f.date), currencies: f.symbol }) }}</span>
          </li>
        </ul>
        <p class="px-4 pb-3 text-xs text-neutral-500">{{ t('data_quality.fx_hint') }}</p>
      </SectionCard>

      <SectionCard v-if="data.notices.length" :title="t('data_quality.notices')" flush class="mb-4">
        <template #actions><Badge tone="warning">{{ data.notices.length }}</Badge></template>
        <ul class="divide-y divide-neutral-200">
          <li v-for="(n, i) in data.notices" :key="i" :class="rowClass">
            <span class="min-w-0"><span class="font-medium">{{ n.symbol }}</span> <span v-if="n.name" class="text-neutral-500 break-all">· {{ n.name }}</span></span>
            <span class="text-warning-600 whitespace-nowrap">{{ noticeLabel(n) }}<span v-if="n.date" class="text-neutral-500"> · {{ formatDate(n.date) }}</span></span>
          </li>
        </ul>
        <p class="px-4 pb-3 text-xs text-neutral-500">
          <RouterLink to="/profile/bank-notices" class="text-primary-700 hover:underline">{{ t('nav.bank_notices') }} →</RouterLink>
        </p>
      </SectionCard>
    </template>
  </div>
</template>
