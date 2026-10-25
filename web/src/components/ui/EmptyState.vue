<script setup lang="ts">
import Icon, { type IconName } from '@/components/ui/Icon.vue'

defineProps<{
  title: string
  message?: string
  cta?: string
  to?: string
  icon?: IconName
}>()
</script>

<template>
  <div class="px-6 py-10 sm:p-12 text-center">
    <div class="mx-auto w-12 h-12 rounded-full bg-neutral-100 flex items-center justify-center mb-3">
      <Icon :name="icon ?? 'empty'" class="w-6 h-6 text-neutral-400" />
    </div>
    <p class="text-sm font-medium text-neutral-700">{{ title }}</p>
    <p v-if="message" class="text-xs text-neutral-500 mt-1">{{ message }}</p>
    <RouterLink v-if="cta && to" :to="to"
      class="inline-flex items-center gap-1.5 mt-4 h-9 px-4 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md">
      {{ cta }}
    </RouterLink>
  </div>
</template>
