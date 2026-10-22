<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { activeWebAuthnCeremony, cancelActiveWebAuthnCeremony } from '@/security/webauthn'

/**
 * Systémový dialog pro přístupový klíč se umí neukázat — otevře se za oknem
 * prohlížeče, na druhém monitoru, nebo si volání převezme správce hesel a spadne.
 * Stránka pak jen visí s neaktivním tlačítkem, než doběhne timeout ceremonie
 * (~2 minuty), a uživateli to připadá jako zatuhlá aplikace. Tenhle prompt po
 * pár sekundách řekne, na co se čeká, a hlavně dá cestu ven.
 */
const HINT_AFTER_MS = 6_000

const { t } = useI18n()
const visible = ref(false)
const patched = ref(false)
let timer = 0

watch(activeWebAuthnCeremony, (ceremony) => {
  window.clearTimeout(timer)
  if (ceremony === null) {
    visible.value = false
    return
  }
  patched.value = ceremony.patched
  timer = window.setTimeout(() => { visible.value = true }, HINT_AFTER_MS)
}, { immediate: true })

onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <div v-if="visible" role="status" aria-live="polite"
       class="fixed inset-x-0 bottom-0 z-50 flex justify-center p-4 pointer-events-none">
    <div class="pointer-events-auto w-full max-w-md rounded-lg border border-warning-300 bg-surface shadow-lg p-4">
      <p class="text-sm font-medium text-neutral-800">{{ t('webauthn.waiting_title') }}</p>
      <p class="mt-1 text-xs text-neutral-600">
        {{ patched ? t('webauthn.waiting_hint_extension') : t('webauthn.waiting_hint') }}
      </p>
      <div class="mt-3 flex justify-end">
        <button type="button" @click="cancelActiveWebAuthnCeremony()"
                class="h-9 px-3 rounded-md border border-neutral-300 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
          {{ t('webauthn.waiting_cancel') }}
        </button>
      </div>
    </div>
  </div>
</template>
