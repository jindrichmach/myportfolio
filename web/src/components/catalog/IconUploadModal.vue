<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import EntityIcon from '@/components/ui/EntityIcon.vue'
import { catalogApi, type CatalogKind } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'

const props = defineProps<{ kind: CatalogKind; id: number; name: string; iconUrl: string | null }>()
const emit = defineEmits<{ close: []; changed: [url: string | null] }>()
const { t } = useI18n()

const MAX_BYTES = 1024 * 1024
const current = ref(props.iconUrl)
const busy = ref(false)
const error = ref('')
const input = ref<HTMLInputElement | null>(null)

async function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  error.value = ''
  if (file.size > MAX_BYTES) {
    error.value = t('icons.too_large')
  } else {
    busy.value = true
    try {
      current.value = await catalogApi.uploadIcon(props.kind, props.id, file)
      emit('changed', current.value)
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
    current.value = null
    emit('changed', null)
  } catch (err) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="t('icons.title_for', { name })" width-class="max-w-sm" @close="emit('close')">
    <div class="flex items-center gap-4">
      <EntityIcon :src="current" :name="name" :size="64" />
      <div class="space-y-2 min-w-0">
        <input ref="input" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="onFile" />
        <div class="flex flex-wrap gap-2">
          <button type="button" :disabled="busy" class="cursor-pointer px-3 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md" @click="input?.click()">
            {{ current ? t('icons.replace') : t('icons.upload') }}
          </button>
          <button v-if="current" type="button" :disabled="busy" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 text-danger-500" @click="remove">
            {{ t('icons.remove') }}
          </button>
        </div>
        <p class="text-xs text-neutral-500">{{ t('icons.hint') }}</p>
      </div>
    </div>
    <p v-if="error" class="mt-3 text-sm text-danger-500">{{ error }}</p>
    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">{{ t('common.close') }}</button>
    </template>
  </Modal>
</template>
