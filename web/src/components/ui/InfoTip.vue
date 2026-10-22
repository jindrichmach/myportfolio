<script setup lang="ts">
import { onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/ui/Icon.vue'

/**
 * Ikona „i" s vysvětlením (TWR vs XIRR, výpočet metriky…). Ukáže se při najetí, fokusu i klepnutí
 * (mobil); tooltip je teleportovaný do <body>, aby ho neořízl overflow karty.
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
function hide() { tip.value = null }
function toggle() { if (tip.value) hide(); else show() }
window.addEventListener('scroll', hide, true)
onBeforeUnmount(() => window.removeEventListener('scroll', hide, true))
void props
</script>

<template>
  <button ref="el" type="button" :aria-label="t('common.explain', { name })" :aria-describedby="tip ? id : undefined"
          class="cursor-help inline-flex items-center justify-center w-4 h-4 rounded-full text-neutral-400 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
          @mouseenter="show" @mouseleave="hide" @focus="show" @blur="hide" @click.stop="toggle">
    <Icon name="info" class="w-3.5 h-3.5" />
  </button>
  <Teleport to="body">
    <div v-if="tip" :id="id" role="tooltip" :style="{ top: `${tip.top}px`, left: `${tip.left}px` }"
         class="fixed z-[100] -translate-x-1/2 -translate-y-full pointer-events-none w-max max-w-64 px-2.5 py-1.5 rounded-md bg-neutral-900 text-surface text-xs leading-snug shadow-md">
      {{ text }}
    </div>
  </Teleport>
</template>
