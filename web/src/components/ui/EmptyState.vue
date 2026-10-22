<script setup lang="ts">
defineProps<{
  title: string
  message?: string
  cta?: string
  to?: string
}>()
</script>

<template>
  <div class="px-6 py-10 sm:p-12 text-center">
    <div class="mx-auto w-12 h-12 rounded-full bg-neutral-100 flex items-center justify-center mb-3">
      <svg class="w-6 h-6 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2a4 4 0 0 1 4-4h6m0 0l-3-3m3 3l-3 3M3 12a9 9 0 1 1 18 0 9 9 0 0 1-18 0z"/>
      </svg>
    </div>
    <p class="text-sm font-medium text-neutral-700">{{ title }}</p>
    <p v-if="message" class="text-xs text-neutral-500 mt-1">{{ message }}</p>
    <RouterLink v-if="cta && to" :to="to"
      class="inline-flex items-center gap-1.5 mt-4 h-9 px-4 bg-action hover:bg-action-hover text-on-action text-sm font-medium rounded-md">
      {{ cta }}
    </RouterLink>
  </div>
</template>
