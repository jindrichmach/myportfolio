<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import InfoTip from '@/components/ui/InfoTip.vue'

/**
 * Jedno klíčové číslo. `hero` = hlavní číslo stránky (velké), jinak sekundární metrika.
 * `bare` vynechá rámeček karty (dlaždice uvnitř společného boxu). `valueClass` nese barvu zisku/ztráty.
 */
withDefaults(defineProps<{
  label: string
  value: string
  sub?: string
  valueClass?: string
  subClass?: string
  hint?: string
  to?: RouteLocationRaw
  hero?: boolean
  bare?: boolean
}>(), { hero: false, bare: false })
</script>

<template>
  <div class="min-w-0" :class="bare ? '' : 'bg-surface border border-neutral-200 rounded-lg shadow-sm p-4'">
    <div class="flex items-center gap-1 text-xs text-neutral-500 leading-4">
      <RouterLink v-if="to" :to="to" class="text-primary-700 hover:underline">{{ label }}</RouterLink>
      <span v-else>{{ label }}</span>
      <InfoTip v-if="hint" :text="hint" :name="label" />
    </div>
    <div class="font-semibold tabular-nums mt-1 break-words" :class="[hero ? 'text-3xl leading-9 tracking-tight' : 'text-lg leading-7', valueClass]">{{ value }}</div>
    <div v-if="sub" class="tabular-nums" :class="[hero ? 'text-sm font-medium mt-0.5' : 'text-xs', subClass ?? valueClass]">{{ sub }}</div>
    <slot />
  </div>
</template>
