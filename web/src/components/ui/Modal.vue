<script lang="ts">
// Otevřené dialogy (vnořený dialog = výběr ikony ve formuláři): Escape a past Tab řeší jen ten nejvrchnější.
const openStack: symbol[] = []
</script>

<script setup lang="ts">
import { nextTick, onMounted, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Generic modal — backdrop, ESC close, click-outside close, sticky header.
 * Tělo se scrolluje, hlavička i patička zůstávají. Šířka přes `widthClass`
 * (Tailwind utility). Volitelný slot `footer` drží akční tlačítka dialogu.
 * Přístupnost: role dialog, název z titulku, fokus do prvního pole, Tab zůstává
 * uvnitř a po zavření se fokus vrátí na prvek, který dialog otevřel.
 */
const props = withDefaults(defineProps<{
  title: string
  widthClass?: string
}>(), {
  widthClass: 'max-w-3xl',
})

const emit = defineEmits<{
  (e: 'close'): void
}>()

const { t } = useI18n()
const titleId = useId()
const dialog = ref<HTMLElement | null>(null)
const body = ref<HTMLElement | null>(null)
let opener: HTMLElement | null = null
const self = Symbol('modal')

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(root: HTMLElement | null): HTMLElement[] {
  if (!root) return []
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(el => el.offsetParent !== null || el === document.activeElement)
}

function onKey(e: KeyboardEvent) {
  if (openStack[openStack.length - 1] !== self) return
  if (e.key === 'Escape') emit('close')
  if (e.key !== 'Tab' || !dialog.value) return
  const items = focusables(dialog.value)
  if (!items.length) { e.preventDefault(); dialog.value.focus(); return }
  const first = items[0]
  const last = items[items.length - 1]
  const active = document.activeElement as HTMLElement | null
  const inside = !!active && dialog.value.contains(active)
  if (e.shiftKey && (!inside || active === first || active === dialog.value)) { e.preventDefault(); last.focus() }
  else if (!e.shiftKey && (!inside || active === last)) { e.preventDefault(); first.focus() }
}

onMounted(async () => {
  opener = document.activeElement as HTMLElement | null
  openStack.push(self)
  document.addEventListener('keydown', onKey)
  // Zamkni body scroll dokud je modal otevřený.
  document.body.style.overflow = 'hidden'
  await nextTick()
  const field = body.value?.querySelector<HTMLElement>('input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])')
  ;(field ?? focusables(body.value)[0] ?? dialog.value)?.focus()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  openStack.splice(openStack.indexOf(self), 1)
  if (!openStack.length) document.body.style.overflow = ''
  if (opener?.isConnected) opener.focus()
})

void props
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-backdrop"
      @click.self="emit('close')">
      <div ref="dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1"
        class="bg-surface rounded-lg shadow-xl w-full flex flex-col max-h-[90vh] focus:outline-none" :class="widthClass">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between shrink-0">
          <h2 :id="titleId" class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" @click="emit('close')" :aria-label="t('common.close')"
            class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>
        <div ref="body" class="overflow-y-auto flex-1 p-5">
          <slot />
        </div>
        <footer v-if="$slots.footer"
          class="px-5 py-3 border-t border-neutral-200 flex items-center justify-end gap-2 shrink-0">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>
