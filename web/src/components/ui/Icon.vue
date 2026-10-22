<script setup lang="ts">
/**
 * Stavová ikona (Heroicons outline, stroke 2, currentColor) — uzavřená sada místo znaků ⚠ ✓ ✕ 🔒 apod.
 * Bez `label` je dekorativní (aria-hidden); s `label` ji čtečka přečte.
 */
const ICONS = {
  warning: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
  check:   'M5 13l4 4L19 7',
  x:       'M6 18L18 6M6 6l12 12',
  lock:    'M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z',
  eye:     'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
  'eye-off': 'M13.875 18.825A10.05 10.05 0 0 1 12 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 0 1 1.563-3.029m5.858.908a3 3 0 1 1 4.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0 1 12 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 0 1-4.132 5.411m0 0L21 21',
  info:    'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  'chevron-down': 'M19 9l-7 7-7-7',
} as const

export type IconName = keyof typeof ICONS

defineProps<{ name: IconName; label?: string }>()
</script>

<template>
  <svg class="inline-block shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
       :role="label ? 'img' : undefined" :aria-label="label" :aria-hidden="label ? undefined : 'true'">
    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[name]" />
  </svg>
</template>
