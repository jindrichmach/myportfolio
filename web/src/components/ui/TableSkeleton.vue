<script setup lang="ts">
defineProps<{
  rows?: number
  cols?: number
}>()
</script>

<template>
  <div class="animate-pulse">
    <div class="border-b border-neutral-100 px-4 py-3 flex gap-4 bg-neutral-50">
      <div v-for="c in (cols ?? 5)" :key="`h${c}`" class="h-3 bg-neutral-200 rounded flex-1"></div>
    </div>
    <div v-for="r in (rows ?? 6)" :key="r" class="border-b border-neutral-100 px-4 py-3 flex gap-4">
      <div v-for="c in (cols ?? 5)" :key="`c${c}`" class="h-3 bg-neutral-100 rounded flex-1"></div>
    </div>
  </div>
</template>
