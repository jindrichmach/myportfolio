<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { appearanceApi } from '@/api/admin'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import ColorSchemePicker from '@/components/ColorSchemePicker.vue'
import type { ColorScheme } from '@/theme/colorSchemes'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const scheme = ref<ColorScheme | null>(null)
const busy = ref(false)

onMounted(async () => {
  try {
    scheme.value = (await appearanceApi.get()).color_scheme
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
})

async function save() {
  if (!scheme.value || busy.value) return
  busy.value = true
  try {
    const saved = (await appearanceApi.save(scheme.value)).color_scheme
    // Uživatel bez vlastní volby vidí schéma instance hned
    if (auth.setupStatus) auth.setupStatus.color_scheme = saved
    toast.success(t('appearance.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl">
    <p class="mb-4 text-sm text-neutral-500">{{ t('appearance.instance_hint') }}</p>

    <form v-if="scheme" @submit.prevent="save" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <ColorSchemePicker v-model="scheme" name="instance-scheme" />
      <div class="flex justify-end pt-2">
        <button type="submit" :disabled="busy"
                class="cursor-pointer px-4 h-10 bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md">
          {{ busy ? '…' : t('common.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
