<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/ui/Icon.vue'
import ManualModal from '@/components/ui/ManualModal.vue'

/**
 * Odkaz z obrazovky do příslušného oddílu manuálu (`to` = „07_Prehled#71-jak-se-počítá-hodnota-a-zisk“).
 * Klik otevře oddíl v dialogu, ikonka na konci manuál v novém okně (Ctrl/⌘+klik na text v novém panelu).
 * Kotvy hlídá web/tests/manual-links.test.mjs. Bez `label` text „Jak se to počítá?“; `inline` = bez úvodní
 * ikony a ve velikosti okolního textu.
 */
const props = defineProps<{ to: string; label?: string; inline?: boolean }>()
const { t } = useI18n()
const open = ref(false)

function onClick(e: MouseEvent) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
  e.preventDefault()
  open.value = true
}
void props
</script>

<template>
  <span class="inline-flex items-center gap-1" :class="{ 'text-xs': !inline }">
    <a :href="`/manual/${to}`" class="inline-flex items-center gap-1 text-primary-700 hover:underline" @click="onClick">
      <Icon v-if="!inline" name="info" class="w-3.5 h-3.5 shrink-0" />{{ label ?? t('manual.how_computed') }}
    </a>
    <a :href="`/manual/${to}`" target="_blank" rel="noopener" :title="t('manual.open_new_window')" :aria-label="t('manual.open_new_window')"
       class="inline-flex text-neutral-400 hover:text-primary-700">
      <Icon name="external" class="w-3 h-3" />
    </a>
    <ManualModal v-if="open" :to="to" @close="open = false" />
  </span>
</template>
