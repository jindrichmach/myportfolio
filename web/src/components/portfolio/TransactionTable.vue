<script setup lang="ts">
import { useOwnerContext } from '@/composables/useOwnerContext'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Badge, { type BadgeTone } from '@/components/ui/Badge.vue'
import DataTable, { type DataColumn } from '@/components/ui/DataTable.vue'
import type { Transaction, TransactionType } from '@/api/portfolio'
import { formatDec, formatDateTime } from '@/composables/useFormat'

const props = defineProps<{
  rows: Transaction[]
  /** Zobrazit sloupec pozice (globální seznam). */
  showPosition?: boolean
}>()
const emit = defineEmits<{ (e: 'edit', tx: Transaction): void; (e: 'delete', tx: Transaction): void }>()
const { t } = useI18n()
const { canWrite } = useOwnerContext()

const TONES: Record<TransactionType, BadgeTone> = {
  buy: 'success',
  sell: 'danger',
  reward: 'accent',
  transfer_in: 'neutral',
  transfer_out: 'neutral',
  deposit: 'success',
  withdrawal: 'danger',
  dividend: 'primary',
  interest: 'primary',
  fee: 'warning',
  split: 'neutral',
}

const columns = computed<DataColumn[]>(() => [
  ...(props.showPosition ? [{ key: 'position', label: t('tx.position'), primary: true }] : []),
  // typ je pod datem a poplatek pod částkou — tabulka se vejde i s pozicí na 1280 px
  { key: 'executed_at', label: t('tx.executed_at'), primary: !props.showPosition },
  { key: 'quantity', label: t('tx.quantity'), align: 'right' },
  { key: 'unit_price', label: t('tx.unit_price'), align: 'right' },
  { key: 'amount', label: t('tx.amount'), align: 'right' },
  { key: 'note', label: t('common.note'), hideBelow: '2xl' },
])
const txKey = (tx: Transaction) => tx.id
/** Množství a kurzy kryptoměn v mono. */
const mono = (tx: Transaction) => ({ 'font-mono': tx.asset_class === 'crypto' })
</script>

<template>
  <DataTable :columns="columns" :rows="rows" :row-key="txKey" sticky-first :cards-below="showPosition ? 'lg' : 'md'">
    <template #cell-position="{ row: tx }">
      <div class="flex items-center gap-2 min-w-0">
        <EntityIcon :src="tx.valuation_mode === 'market' ? tx.instrument_icon_url : (tx.instrument_icon_url ?? tx.institution_icon_url)" :name="tx.position_name" />
        <div class="min-w-0 max-w-52">
          <RouterLink :to="`/positions/${tx.position_id}`" class="font-medium hover:text-primary-700 hover:underline">{{ tx.position_name }}</RouterLink>
          <span class="block text-xs text-neutral-500 truncate">{{ tx.institution_name }} / {{ tx.account_name }} · {{ tx.portfolio_name }}</span>
        </div>
      </div>
    </template>
    <template #cell-executed_at="{ row: tx }">
      <span class="block whitespace-nowrap tabular-nums">{{ formatDateTime(tx.executed_at) }}</span>
      <Badge :tone="TONES[tx.type]" class="mt-0.5">{{ t(`tx.types.${tx.type}`) }}</Badge>
    </template>
    <template #cell-quantity="{ row: tx }">
      <span :class="mono(tx)">
        <template v-if="tx.type === 'split'">× {{ formatDec(tx.split_ratio, 10) }}</template>
        <template v-else>{{ formatDec(tx.quantity, 18) }}</template>
      </span>
    </template>
    <template #cell-unit_price="{ row: tx }"><span :class="mono(tx)">{{ formatDec(tx.unit_price, 10) }}</span></template>
    <template #cell-amount="{ row: tx }">
      {{ tx.amount ? formatDec(tx.amount, 4, tx.price_currency) : '—' }}
      <span v-if="tx.fee && Number(tx.fee) !== 0" class="block text-xs text-neutral-500">{{ t('tx.fee') }} {{ formatDec(tx.fee, 4, tx.fee_currency) }}</span>
    </template>
    <template #cell-note="{ row: tx }">
      <span class="block max-w-64 truncate text-neutral-500" :title="tx.note ?? undefined">
        <Badge v-if="tx.created_by_name" tone="primary" class="mr-1">{{ t('sharing.created_by', { name: tx.created_by_name }) }}</Badge>{{ tx.note }}
      </span>
    </template>
    <template v-if="canWrite" #actions="{ row: tx }">
      <span class="inline-flex gap-1">
        <IconButton v-if="!tx.transfer_group_id" icon="edit" :label="t('common.edit')" @click="emit('edit', tx)" />
        <IconButton icon="trash" variant="danger" :label="t('common.delete')" @click="emit('delete', tx)" />
      </span>
    </template>
  </DataTable>
</template>
