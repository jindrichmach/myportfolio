<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatPercent } from '@/composables/useFormat'

/**
 * Skutečný podíl vs. cílová alokace: pruh (výplň = skutečnost, svislá značka = cíl).
 * Mimo toleranci výplň ve warning — vedle jsou vždy čísla, barva je jen doplněk.
 */
/** `color` = barevný čtvereček třídy aktiv před popiskem (rizikovost). */
const props = defineProps<{ label: string; pct: string; target?: string | null; outOfBand?: boolean; detail?: string; color?: string }>()
const { t } = useI18n()
const width = computed(() => `${Math.min(100, Math.max(0, Number(props.pct)))}%`)
const targetLeft = computed(() => (props.target === null || props.target === undefined ? null : `${Math.min(100, Math.max(0, Number(props.target)))}%`))
</script>

<template>
  <div class="min-w-0">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
      <span class="inline-flex items-center gap-1.5 min-w-0"><span v-if="color" class="w-2.5 h-2.5 rounded-sm shrink-0" :style="{ background: color }" aria-hidden="true"></span><span class="truncate">{{ label }}</span></span>
      <span class="tabular-nums whitespace-nowrap">
        <span class="font-semibold">{{ formatPercent(pct, 1) }}</span>
        <span v-if="target !== null && target !== undefined" class="text-neutral-500"> / {{ t('allocation.target') }} {{ formatPercent(target, 1) }}</span>
      </span>
    </div>
    <div class="relative h-2 mt-1.5 rounded-full bg-neutral-100">
      <div class="absolute inset-y-0 left-0 rounded-full" :class="outOfBand ? 'bg-warning-500' : 'bg-primary-500'" :style="{ width }"></div>
      <div v-if="targetLeft" class="absolute -top-1 -bottom-1 w-0.5 bg-neutral-800" :style="{ left: targetLeft }"></div>
    </div>
    <p v-if="detail" class="mt-1 text-xs tabular-nums" :class="outOfBand ? 'text-warning-600' : 'text-neutral-500'">{{ detail }}</p>
  </div>
</template>
