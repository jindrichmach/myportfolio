<script setup lang="ts">
/** Krátký štítek stavu nebo typu. Barva text jen doplňuje — význam nese vždy text. */
export type BadgeTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'accent'

withDefaults(defineProps<{ tone?: BadgeTone }>(), { tone: 'neutral' })

const TONES: Record<BadgeTone, string> = {
  neutral: 'bg-neutral-100 text-neutral-600',
  primary: 'bg-primary-50 text-primary-700',
  success: 'bg-success-50 text-success-600',
  warning: 'bg-warning-50 text-warning-600',
  danger:  'bg-danger-50 text-danger-500',
  accent:  'bg-accent-50 text-accent-600',
}
</script>

<template>
  <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="TONES[tone]"><slot /></span>
</template>
