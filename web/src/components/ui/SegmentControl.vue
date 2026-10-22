<script setup lang="ts" generic="T extends string">
/** Přepínač několika voleb (období grafu, režim zobrazení). Aktivní volba ve výplni značky. */
defineProps<{ options: { value: T; label: string }[]; label?: string }>()
const model = defineModel<T>({ required: true })
</script>

<template>
  <div role="group" :aria-label="label" class="inline-flex rounded-md border border-neutral-300 overflow-hidden text-xs bg-surface">
    <button v-for="o in options" :key="o.value" type="button" :aria-pressed="model === o.value" @click="model = o.value"
            class="cursor-pointer px-2.5 h-7 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500"
            :class="model === o.value ? 'bg-action text-on-action font-medium' : 'text-neutral-700 hover:bg-neutral-50'">
      {{ o.label }}
    </button>
  </div>
</template>
