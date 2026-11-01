<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue'

/**
 * Rozbalovací menu v horní liště (motiv, jazyk, uživatel). Spouštěč ve slotu `trigger`, obsah ve výchozím slotu
 * (dostane `close`). Klávesnice jako RowActionsMenu: šipky mezi položkami, Escape zavře a vrátí fokus na tlačítko,
 * Tab zavře. Zavře se i klikem mimo. `wide` = na telefonu přes celou šířku pod lištou.
 */
defineProps<{ label: string; title?: string; wide?: boolean }>()
const emit = defineEmits<{ open: [] }>()

const root = ref<HTMLElement | null>(null)
const button = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const isOpen = ref(false)

function itemsEls(): HTMLElement[] {
  return menu.value ? Array.from(menu.value.querySelectorAll<HTMLElement>('[role^="menuitem"]')) : []
}
async function open() {
  isOpen.value = true
  emit('open')
  document.addEventListener('click', onOutside, true)
  await nextTick()
  const els = itemsEls()
  ;(els.find(el => el.getAttribute('aria-checked') === 'true') ?? els[0])?.focus()
}
function close() {
  isOpen.value = false
  document.removeEventListener('click', onOutside, true)
}
function toggle() { if (isOpen.value) close(); else open() }
function onOutside(e: Event) {
  if (!root.value?.contains(e.target as Node)) close()
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
onBeforeUnmount(close)
</script>

<template>
  <div ref="root" class="relative">
    <button ref="button" type="button" aria-haspopup="menu" :aria-expanded="isOpen" :aria-label="label" :title="title"
      class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-2 rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
      :class="isOpen ? 'bg-neutral-100 text-neutral-900' : ''"
      @click="toggle">
      <slot name="trigger" />
    </button>
    <div v-if="isOpen" ref="menu" role="menu" :aria-label="label"
      class="absolute right-0 top-full mt-2 z-40 min-w-44 max-w-[calc(100vw-1.5rem)] py-1 rounded-lg border border-neutral-200 bg-surface shadow-lg text-sm"
      :class="wide ? 'max-sm:fixed max-sm:inset-x-3 max-sm:top-14 max-sm:max-w-none' : ''"
      @keydown="onKey">
      <slot :close="close" />
    </div>
  </div>
</template>
