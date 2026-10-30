<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'

/**
 * Kontextové menu řádku „⋯“ — akce u položky seznamu (detail, nová transakce, převod…).
 * Menu je teleportované do <body> (neořízne ho overflow tabulky). Klávesnice: šipky mezi položkami,
 * Escape zavře a vrátí fokus na tlačítko, Tab menu zavře. Zavře se i klikem mimo a při scrollu.
 */
export interface RowAction {
  key: string
  label: string
  to?: RouteLocationRaw
  run?: () => void
  danger?: boolean
}

/** `buttonLabel` = textové tlačítko (např. „Přidat“) místo „⋯“. */
defineProps<{ items: RowAction[]; label?: string; buttonLabel?: string }>()
const { t } = useI18n()

const button = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const pos = ref<{ top: number; right: number } | null>(null)

function itemsEls(): HTMLElement[] {
  return menu.value ? Array.from(menu.value.querySelectorAll<HTMLElement>('[role="menuitem"]')) : []
}
async function open() {
  if (!button.value) return
  const r = button.value.getBoundingClientRect()
  pos.value = { top: r.bottom + 4, right: Math.max(8, window.innerWidth - r.right) }
  document.addEventListener('click', onOutside, true)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
  await nextTick()
  itemsEls()[0]?.focus()
}
function close() {
  pos.value = null
  document.removeEventListener('click', onOutside, true)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
}
function toggle() { if (pos.value) close(); else open() }
function onOutside(e: Event) {
  const target = e.target as Node
  if (!menu.value?.contains(target) && !button.value?.contains(target)) close()
}
function onKey(e: KeyboardEvent) {
  const els = itemsEls()
  const i = els.indexOf(document.activeElement as HTMLElement)
  if (e.key === 'ArrowDown') { e.preventDefault(); els[(i + 1) % els.length]?.focus() }
  else if (e.key === 'ArrowUp') { e.preventDefault(); els[(i - 1 + els.length) % els.length]?.focus() }
  else if (e.key === 'Home') { e.preventDefault(); els[0]?.focus() }
  else if (e.key === 'End') { e.preventDefault(); els[els.length - 1]?.focus() }
  else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); button.value?.focus() }
  else if (e.key === 'Tab') close()
}
function pick(item: RowAction) {
  close()
  item.run?.()
}
onBeforeUnmount(close)
</script>

<template>
  <button v-if="buttonLabel" ref="button" type="button" aria-haspopup="menu" :aria-expanded="!!pos"
    class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md shrink-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
    @click.stop="toggle">
    {{ buttonLabel }}
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
  </button>
  <button v-else ref="button" type="button" :aria-label="label ?? t('common.more_actions')" aria-haspopup="menu" :aria-expanded="!!pos"
    class="cursor-pointer inline-flex items-center justify-center shrink-0 w-8 h-8 rounded-md border border-neutral-300 bg-surface text-neutral-600 hover:text-primary-700 hover:bg-primary-50 hover:border-primary-500/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
    @click.stop="toggle">
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <circle cx="5" cy="12" r="1.75" /><circle cx="12" cy="12" r="1.75" /><circle cx="19" cy="12" r="1.75" />
    </svg>
  </button>
  <Teleport to="body">
    <div v-if="pos" ref="menu" role="menu" :aria-label="buttonLabel ?? label ?? t('common.more_actions')" :style="{ top: `${pos.top}px`, right: `${pos.right}px` }"
      class="fixed z-[70] min-w-48 py-1 rounded-md border border-neutral-200 bg-surface shadow-lg text-sm" @keydown="onKey">
      <template v-for="item in items" :key="item.key">
        <RouterLink v-if="item.to" :to="item.to" role="menuitem" tabindex="-1" @click="close"
          class="block px-3 py-2 text-left text-neutral-800 hover:bg-primary-50 focus:bg-primary-50 focus:outline-none">{{ item.label }}</RouterLink>
        <button v-else type="button" role="menuitem" tabindex="-1" @click="pick(item)"
          class="cursor-pointer block w-full px-3 py-2 text-left hover:bg-primary-50 focus:bg-primary-50 focus:outline-none"
          :class="item.danger ? 'text-danger-500' : 'text-neutral-800'">{{ item.label }}</button>
      </template>
    </div>
  </Teleport>
</template>
