<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Sbalitelná lišta filtrů.
 * - `primary` slot: vždy viditelné prvky (typicky vyhledávání).
 * - default slot: ostatní filtry — na desktopu (md+) inline v jednom flex-wrap řádku,
 *   na mobilu schované za tlačítko „Filtry (N)".
 * - `actions` slot: akční tlačítka zarovnaná vpravo (ml-auto), vždy viditelná.
 * `activeCount` = počet aktivních filtrů zobrazený jako odznáček na tlačítku.
 */
defineProps<{ activeCount?: number }>()

const { t } = useI18n()
const open = ref(false)
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
    <div class="flex flex-wrap items-center gap-2">
      <slot name="primary" />

      <button
        v-if="$slots.default"
        type="button"
        @click="open = !open"
        class="md:hidden cursor-pointer h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm inline-flex items-center gap-1.5 text-neutral-700"
        :aria-expanded="open"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M6 12h12M10 20h4" />
        </svg>
        {{ t('common.filters') }}
        <span
          v-if="activeCount"
          class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-action text-on-action text-xs font-medium"
        >{{ activeCount }}</span>
        <svg
          class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <!-- display:contents → děti se na md+ chovají jako přímé flex-položky řádku;
           na mobilu se celá skupina skryje, dokud uživatel nerozbalí -->
      <div :class="open ? 'contents' : 'hidden md:contents'">
        <slot />
      </div>

      <div v-if="$slots.actions" class="ml-auto flex flex-wrap items-center gap-2">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>
