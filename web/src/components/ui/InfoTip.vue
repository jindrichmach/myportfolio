<script setup lang="ts">
import { onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/ui/Icon.vue'

/**
 * Ikona „i" s vysvětlením (TWR vs XIRR, výpočet metriky…). Ukáže se při najetí myší, fokusu i klepnutí
 * (mobil); tooltip je teleportovaný do <body>, aby ho neořízl overflow karty. Text je trvale ve skrytém
 * prvku pro čtečky (aria-describedby); klikací plocha 24 × 24 px při 16px ikoně.
 */
const props = defineProps<{ text: string; name: string }>()
const { t } = useI18n()
const id = useId()

const el = ref<HTMLElement | null>(null)
const tip = ref<{ top: number; left: number } | null>(null)
function show() {
  if (!el.value) return
  const r = el.value.getBoundingClientRect()
  tip.value = { top: r.top - 6, left: Math.min(Math.max(r.left + r.width / 2, 140), window.innerWidth - 140) }
}
// Klepnutí/kliknutí tooltip „připíchne" — zůstane i po odjetí myší, zavře ho další klepnutí nebo klik mimo.
const pinned = ref(false)
function hide() { tip.value = null; pinned.value = false }
function toggle() { if (pinned.value) hide(); else { show(); pinned.value = true } }
// Dotyk emuluje mouseenter těsně před click — najetí proto řeší jen skutečná myš.
function onEnter(e: PointerEvent) { if (e.pointerType === 'mouse') show() }
function onLeave(e: PointerEvent) { if (e.pointerType === 'mouse' && !pinned.value) tip.value = null }
// Fokus z klávesnice ukáže; fokus z klepnutí nechá rozhodnout toggle().
function onFocus() { if (el.value?.matches(':focus-visible')) show() }
function onBlur() { if (!pinned.value) tip.value = null }
function onOutside(e: Event) { if (tip.value && !el.value?.contains(e.target as Node)) hide() }
window.addEventListener('scroll', hide, true)
document.addEventListener('click', onOutside)
onBeforeUnmount(() => {
  window.removeEventListener('scroll', hide, true)
  document.removeEventListener('click', onOutside)
})
void props
</script>

<template>
  <button ref="el" type="button" :aria-label="t('common.explain', { name })" :aria-describedby="id"
          class="cursor-help inline-flex items-center justify-center w-6 h-6 -m-1 align-middle rounded-full text-neutral-400 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
          @pointerenter="onEnter" @pointerleave="onLeave" @focus="onFocus" @blur="onBlur" @click.stop="toggle" @keydown.esc="hide">
    <Icon name="info" class="w-3.5 h-3.5" />
    <span :id="id" class="sr-only">{{ text }}</span>
  </button>
  <Teleport to="body">
    <div v-if="tip" role="tooltip" aria-hidden="true" :style="{ top: `${tip.top}px`, left: `${tip.left}px` }"
         class="fixed z-[100] -translate-x-1/2 -translate-y-full pointer-events-none w-max max-w-64 px-2.5 py-1.5 rounded-md bg-neutral-900 text-surface text-xs leading-snug shadow-md">
      {{ text }}
    </div>
  </Teleport>
</template>
