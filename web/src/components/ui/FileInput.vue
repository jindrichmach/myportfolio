<script setup lang="ts">
/**
 * Výběr souboru v jazyce aplikace. Nativní input zůstává (vizuálně skrytý — `sr-only`, ne `hidden`),
 * aby fungoval `<label for>`, `required` i validační bublina; tlačítko a název souboru jsou přes i18n.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps<{ id?: string; accept?: string; required?: boolean; disabled?: boolean }>()
const file = defineModel<File | null>({ default: null })

const { t } = useI18n()
const input = ref<HTMLInputElement | null>(null)

function onChange(e: Event) {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

function reset() {
  if (input.value) input.value.value = ''
  file.value = null
}

defineExpose({ reset })
</script>

<template>
  <div class="flex items-center gap-3 min-w-0">
    <input :id="id" ref="input" type="file" class="sr-only" :accept="accept" :required="required" :disabled="disabled" @change="onChange" />
    <button type="button" :disabled="disabled" @click="input?.click()"
      class="cursor-pointer shrink-0 h-9 px-3 text-sm border border-neutral-300 rounded-md bg-surface hover:bg-neutral-50 disabled:opacity-60">
      {{ t('common.choose_file') }}
    </button>
    <span class="text-sm truncate" :class="file ? 'text-neutral-900' : 'text-neutral-500'">
      {{ file ? file.name : t('common.no_file') }}
    </span>
  </div>
</template>
