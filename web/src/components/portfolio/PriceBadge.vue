<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { LastPrice } from '@/api/market'
import Badge from '@/components/ui/Badge.vue'
import { formatDate, formatDec } from '@/composables/useFormat'

/** `mono` = cena v mono (kurzy kryptoměn). */
defineProps<{ price: LastPrice | null | undefined; showSource?: boolean; mono?: boolean }>()
const { t } = useI18n()
</script>

<template>
  <span v-if="!price" class="text-neutral-400">—</span>
  <span v-else class="inline-flex flex-col items-start md:items-end leading-tight">
    <span class="tabular-nums whitespace-nowrap" :class="{ 'font-mono': mono }">{{ formatDec(price.close, 4, price.currency) }}</span>
    <span class="text-xs text-neutral-500 whitespace-nowrap">
      {{ formatDate(price.date) }}<template v-if="showSource"> · {{ t(`instruments.sources.${price.source}`) }}</template>
      <Badge v-if="price.stale" tone="warning" class="ml-1" :title="t('prices.stale_hint')">{{ t('prices.stale') }}</Badge>
    </span>
  </span>
</template>
