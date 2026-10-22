<script setup lang="ts">
/**
 * Box sekce stránky: záhlaví (titulek, doplňující údaj vpravo, akce) a obsah.
 * `flush` = obsah bez vnitřního odsazení (tabulka, graf přes celou šířku).
 */
withDefaults(defineProps<{ title?: string; meta?: string; subtitle?: string; flush?: boolean }>(), { flush: false })
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <header v-if="title || $slots.title || $slots.actions || meta"
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-4 py-2.5 border-b border-neutral-200 bg-neutral-50">
      <div class="min-w-0">
        <h2 class="text-sm font-semibold"><slot name="title">{{ title }}</slot></h2>
        <p v-if="subtitle" class="text-xs text-neutral-500">{{ subtitle }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span v-if="meta" class="text-sm font-semibold tabular-nums">{{ meta }}</span>
        <slot name="actions" />
      </div>
    </header>
    <div :class="flush ? '' : 'p-4'"><slot /></div>
    <footer v-if="$slots.footer" class="px-4 py-2.5 border-t border-neutral-200"><slot name="footer" /></footer>
  </section>
</template>
