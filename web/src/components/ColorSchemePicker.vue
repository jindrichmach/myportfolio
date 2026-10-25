<script setup lang="ts">
/**
 * Výběr barevného schématu — mřížka s logem v barvách schématu.
 * `inherit` = navíc volba „podle instance" (hodnota null) s náhledem schématu instance.
 */
import { useI18n } from 'vue-i18n'
import AppLogo from '@/components/layout/AppLogo.vue'
import { COLOR_SCHEMES, COLOR_SCHEME_KEYS, type ColorScheme } from '@/theme/colorSchemes'

defineProps<{ inherit?: ColorScheme; name: string }>()
const model = defineModel<ColorScheme | null>({ required: true })
const { t } = useI18n()
</script>

<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2" role="radiogroup" :aria-label="t('appearance.scheme')">
    <label v-if="inherit"
      class="cursor-pointer flex items-center gap-3 p-2.5 border rounded-lg has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
      :class="model === null ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 hover:bg-neutral-50'">
      <input v-model="model" type="radio" :name="name" :value="null" class="sr-only" />
      <AppLogo :colors="COLOR_SCHEMES[inherit]" class="w-8 h-8 shrink-0" />
      <span class="min-w-0 text-sm leading-tight">
        <span class="block font-medium text-neutral-900">{{ t('appearance.inherit') }}</span>
        <span class="block text-xs text-neutral-500">{{ t(`appearance.names.${inherit}`) }}</span>
      </span>
    </label>
    <label v-for="key in COLOR_SCHEME_KEYS" :key="key"
      class="cursor-pointer flex items-center gap-3 p-2.5 border rounded-lg has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
      :class="model === key ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 hover:bg-neutral-50'">
      <input v-model="model" type="radio" :name="name" :value="key" class="sr-only" />
      <AppLogo :colors="COLOR_SCHEMES[key]" class="w-8 h-8 shrink-0" />
      <span class="text-sm font-medium text-neutral-900">{{ t(`appearance.names.${key}`) }}</span>
    </label>
  </div>
</template>
