<script setup lang="ts">
/** Hláška validace pod polem; `id` odkazuje pole přes `aria-describedby` (viz useFieldErrors). */
defineProps<{ id: string; messages?: string[] }>()
</script>

<template>
  <p v-if="messages?.length" :id="id" role="alert" class="mt-1 text-xs text-danger-500">{{ messages.join(' ') }}</p>
</template>
