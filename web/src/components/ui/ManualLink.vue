<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Icon from '@/components/ui/Icon.vue'

/**
 * Odkaz z obrazovky do příslušného oddílu manuálu (`to` = „07_Prehled#71-jak-se-počítá-hodnota-a-zisk“).
 * Kotvy hlídá web/tests/manual-links.test.mjs. Bez `label` text „Jak se to počítá?“.
 */
defineProps<{ to: string; label?: string }>()
const { t } = useI18n()
</script>

<template>
  <RouterLink :to="`/manual/${to}`" class="inline-flex items-center gap-1 text-xs text-primary-700 hover:underline">
    <Icon name="info" class="w-3.5 h-3.5 shrink-0" />{{ label ?? t('manual.how_computed') }}
  </RouterLink>
</template>
