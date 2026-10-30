<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'

/**
 * Jednotné akční tlačítko u položky (Upravit, Smazat, Detail, …): čtverec s ikonou a tooltipem.
 * Tooltip je teleportovaný do <body> (neořízne ho overflow tabulky) a ukáže se při najetí
 * i při fokusu z klávesnice. `label` je zároveň aria-label.
 */
export type IconName = keyof typeof ICONS

const props = withDefaults(defineProps<{
  icon: IconName
  label: string
  /** RouterLink místo tlačítka */
  to?: RouteLocationRaw
  variant?: 'default' | 'danger'
  disabled?: boolean
  loading?: boolean
  /** Text tooltipu, když se liší od label (např. důvod, proč je akce zakázaná) */
  tooltip?: string
  /** Popisek i viditelně vedle ikony — pro vzácné a nevratné akce (Vrátit import, Deaktivovat…) */
  showLabel?: boolean
}>(), { variant: 'default', disabled: false, loading: false, showLabel: false })

defineEmits<{ click: [event: MouseEvent] }>()
defineOptions({ inheritAttrs: false })

// Heroicons outline (stroke 2, viewBox 24)
const ICONS = {
  edit:     'M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
  trash:    'M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16',
  detail:   'M9 5l7 7-7 7',
  price:    'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 0 1 0 2.828l-7 7a2 2 0 0 1-2.828 0l-7-7A1.994 1.994 0 0 1 3 12V7a4 4 0 0 1 4-4z',
  transfer: 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
  plug:     'M13 10V3L4 14h7v7l9-11h-7z',
  refresh:  'M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15',
  play:     'M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664zM21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  ban:      'M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636',
  undo:     'M3 10h10a8 8 0 0 1 8 8v2M3 10l6 6m-6-6l6-6',
  up:       'M5 15l7-7 7 7',
  down:     'M19 9l-7 7-7-7',
  shield:   'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
} as const

const classes = computed(() => [
  'cursor-pointer inline-flex items-center justify-center shrink-0 h-8 rounded-md border border-neutral-300 bg-surface transition-colors',
  props.showLabel ? 'gap-1.5 px-2.5 text-sm whitespace-nowrap' : 'w-8',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
  'disabled:opacity-40 disabled:cursor-not-allowed',
  props.variant === 'danger'
    ? 'text-danger-500 hover:bg-danger-50 hover:border-danger-500/40'
    : 'text-neutral-600 hover:text-primary-700 hover:bg-primary-50 hover:border-primary-500/40',
])

// ── Tooltip ────────────────────────────────────────────────────────────
const el = ref<HTMLElement | null>(null)
const tip = ref<{ top: number; left: number } | null>(null)
function show() {
  const target = el.value && '$el' in el.value ? (el.value as unknown as { $el: HTMLElement }).$el : el.value
  if (!target) return
  const r = target.getBoundingClientRect()
  tip.value = { top: r.top - 6, left: r.left + r.width / 2 }
}
function hide() { tip.value = null }
window.addEventListener('scroll', hide, true)
onBeforeUnmount(() => window.removeEventListener('scroll', hide, true))
</script>

<template>
  <RouterLink v-if="to && !disabled" ref="el" v-bind="$attrs" :to="to" :aria-label="label" :class="classes"
              @mouseenter="show" @mouseleave="hide" @focus="show" @blur="hide" @click="hide">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[icon]" />
    </svg>
    <span v-if="showLabel">{{ label }}</span>
  </RouterLink>
  <button v-else ref="el" v-bind="$attrs" type="button" :aria-label="label" :disabled="disabled || loading" :class="classes"
          @mouseenter="show" @mouseleave="hide" @focus="show" @blur="hide" @click="hide(); $emit('click', $event)">
    <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" class="opacity-25" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </svg>
    <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[icon]" />
    </svg>
    <span v-if="showLabel">{{ label }}</span>
  </button>
  <Teleport to="body">
    <div v-if="tip && (!showLabel || tooltip)" role="tooltip" :style="{ top: `${tip.top}px`, left: `${tip.left}px` }"
         class="fixed z-[100] -translate-x-1/2 -translate-y-full pointer-events-none px-2 py-1 rounded bg-neutral-900 text-surface text-xs whitespace-nowrap shadow-md">
      {{ tooltip ?? label }}
    </div>
  </Teleport>
</template>
