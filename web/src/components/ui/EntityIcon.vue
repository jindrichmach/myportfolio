<script setup lang="ts">
import { computed, ref, watch } from 'vue'

/**
 * Ikona instituce / instrumentu; bez obrázku (nebo když se nenačte) kolečko s iniciálami.
 * Barva kolečka z palety grafů podle jména — stejné jméno má vždy stejnou barvu, různé se rozliší.
 */
const props = withDefaults(defineProps<{ src?: string | null; name: string; size?: number }>(), { src: null, size: 24 })

const failed = ref(false)
watch(() => props.src, () => { failed.value = false })

const initials = computed(() => {
  const words = props.name.replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ').filter(Boolean)
  const text = words.length > 1 ? words.map(w => w[0]).join('') : (words[0] ?? '?')
  return text.slice(0, 2).toUpperCase()
})
const box = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }))
const tint = computed(() => {
  let h = 0
  for (const ch of props.name) h = (h * 31 + ch.charCodeAt(0)) >>> 0
  const c = `var(--color-chart-${(h % 10) + 1})`
  return { background: `color-mix(in srgb, ${c} 22%, var(--color-surface))`, boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${c} 45%, transparent)` }
})
</script>

<template>
  <img v-if="src && !failed" :src="src" alt="" :width="size" :height="size" loading="lazy"
       class="inline-block shrink-0 rounded-full object-contain" :style="box" @error="failed = true" />
  <span v-else aria-hidden="true" :style="{ ...box, ...tint, fontSize: `${Math.round(size * 0.4)}px` }"
        class="inline-flex shrink-0 items-center justify-center rounded-full text-neutral-800 font-semibold leading-none select-none">{{ initials }}</span>
</template>
