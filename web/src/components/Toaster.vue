<script setup lang="ts">
import { useToast } from '@/composables/useToast'
const { toasts, dismiss } = useToast()

function bgClass(type: string): string {
  return ({
    success: 'bg-success-50 border-success-500/40 text-success-600',
    error:   'bg-danger-50 border-danger-500/40 text-danger-500',
    info:    'bg-primary-50 border-primary-500/40 text-primary-700',
    warning: 'bg-warning-50 border-warning-500/40 text-warning-600',
  } as Record<string, string>)[type] || 'bg-neutral-100'
}
</script>

<template>
  <teleport to="body">
    <div class="fixed top-4 right-4 z-[100] flex flex-col gap-2 max-w-sm">
      <transition-group
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 translate-x-4"
        enter-to-class="opacity-100 translate-x-0"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-for="t in toasts" :key="t.id"
          class="rounded-md border px-4 py-2.5 text-sm shadow-md flex items-start gap-2 cursor-pointer"
          :class="bgClass(t.type)"
          @click="dismiss(t.id)">
          <span class="flex-1 whitespace-pre-line">{{ t.text }}</span>
          <span class="text-lg leading-none opacity-60">×</span>
        </div>
      </transition-group>
    </div>
  </teleport>
</template>
