<script lang="ts">
/** `badge` = počet nebo krátký stav vedle názvu záložky. */
export interface TabItem<T extends string = string> {
  key: T
  label: string
  badge?: string | number | null
}
</script>

<script setup lang="ts" generic="K extends string">
/**
 * Záložky stránky. Na úzkém displeji select (dlouhé překlady se nemačkají), jinak řada záložek.
 * Volbu v URL drží `useTabQuery`.
 */
defineProps<{ tabs: TabItem<K>[]; label: string }>()
const model = defineModel<K>({ required: true })
</script>

<template>
  <div>
    <select v-model="model" :aria-label="label"
      class="sm:hidden w-full h-10 px-3 border border-field rounded-md bg-surface text-sm">
      <option v-for="tb in tabs" :key="tb.key" :value="tb.key">
        {{ tb.label }}{{ tb.badge != null && tb.badge !== '' ? ` (${tb.badge})` : '' }}
      </option>
    </select>
    <div role="tablist" :aria-label="label" class="hidden sm:flex gap-1 border-b border-neutral-200">
      <button v-for="tb in tabs" :key="tb.key" type="button" role="tab" :aria-selected="model === tb.key"
        class="cursor-pointer inline-flex items-center gap-2 px-4 h-10 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
        :class="model === tb.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'"
        @click="model = tb.key">
        {{ tb.label }}
        <span v-if="tb.badge != null && tb.badge !== ''"
          class="text-xs tabular-nums px-1.5 rounded bg-neutral-100 text-neutral-600">{{ tb.badge }}</span>
      </button>
    </div>
  </div>
</template>
