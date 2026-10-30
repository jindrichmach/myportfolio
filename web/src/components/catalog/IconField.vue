<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import IconLibraryModal from '@/components/catalog/IconLibraryModal.vue'
import { catalogApi, type CatalogKind } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'

/**
 * Ikona položky katalogu ve formuláři úprav: náhled, nahrání vlastního obrázku, výběr z knihovny, odebrání.
 * Ukládá se hned (nezávisle na tlačítku Uložit formuláře); změnu hlásí `changed(url)`.
 */
const props = defineProps<{ kind: CatalogKind; id: number; name: string; iconUrl: string | null; website?: string | null; hint?: string }>()
const emit = defineEmits<{ changed: [url: string | null] }>()
const { t } = useI18n()

const MAX_BYTES = 1024 * 1024
const current = ref(props.iconUrl)
const busy = ref(false)
const error = ref('')
const input = ref<HTMLInputElement | null>(null)
const libraryOpen = ref(false)

function set(url: string | null) {
  current.value = url
  emit('changed', url)
}

async function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  error.value = ''
  if (file.size > MAX_BYTES) {
    error.value = t('icons.too_large')
  } else {
    busy.value = true
    try {
      set(await catalogApi.uploadIcon(props.kind, props.id, file))
    } catch (err) {
      error.value = apiErrorMessage(err, t('common.error'))
    } finally {
      busy.value = false
    }
  }
  if (input.value) input.value.value = ''
}

async function remove() {
  busy.value = true
  error.value = ''
  try {
    await catalogApi.removeIcon(props.kind, props.id)
    set(null)
  } catch (err) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    busy.value = false
  }
}

function picked(url: string) {
  libraryOpen.value = false
  set(url)
}
</script>

<template>
  <fieldset class="border border-neutral-200 rounded-md p-3">
    <legend class="text-xs font-medium text-neutral-600 px-1">{{ t('icons.field') }}</legend>
    <div class="flex items-center gap-4">
      <EntityIcon :src="current" :name="name" :size="56" />
      <div class="space-y-2 min-w-0">
        <input ref="input" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" :aria-label="t('icons.upload')" @change="onFile" />
        <div class="flex flex-wrap gap-2">
          <button type="button" :disabled="busy" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="input?.click()">
            {{ current ? t('icons.replace') : t('icons.upload') }}
          </button>
          <button type="button" :disabled="busy" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="libraryOpen = true">
            {{ t('icons.choose_library') }}
          </button>
          <button v-if="current" type="button" :disabled="busy" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 text-danger-500" @click="remove">
            {{ t('icons.remove') }}
          </button>
        </div>
        <p class="text-xs text-neutral-500">{{ t('icons.hint') }}</p>
      </div>
    </div>
    <p v-if="error" role="alert" class="mt-2 text-sm text-danger-500">{{ error }}</p>
    <IconLibraryModal v-if="libraryOpen" :kind="kind" :id="id" :name="name" :website="website" :hint="hint"
      @close="libraryOpen = false" @picked="picked" />
  </fieldset>
</template>
