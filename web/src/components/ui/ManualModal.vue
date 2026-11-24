<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import { manualSection, renderManual } from '@/utils/manual'

/** Oddíl manuálu v dialogu (`to` jako u ManualLink). Odkazy na jiné oddíly manuálu se otevřou ve stejném dialogu. */
const props = defineProps<{ to: string }>()
const emit = defineEmits<{ (e: 'close'): void }>()
const { t, locale } = useI18n()

const current = ref(props.to)
const section = computed(() => manualSection(locale.value, current.value))
// Kotvy v rámci kapitoly (#…) míří mimo výřez → na kapitolu, aby fungovaly i ve vlastním okně
const html = computed(() => section.value
  ? renderManual(section.value.source).replace(/href="#/g, `href="/manual/${section.value.slug}#`)
  : '')

const content = ref<HTMLElement | null>(null)
function onClick(e: MouseEvent) {
  const a = (e.target as HTMLElement).closest('a')
  const href = a?.getAttribute('href') ?? ''
  if (!a || !href.startsWith('/manual/') || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') return
  e.preventDefault()
  current.value = decodeURIComponent(href.slice('/manual/'.length))
  content.value?.scrollIntoView({ block: 'start' })
}
</script>

<template>
  <Modal :title="section?.title ?? t('manual.title')" @close="emit('close')">
    <!-- Obsah je markdown z repozitáře (manual/*.md), HTML v něm vypnuté -->
    <div ref="content" class="manual-prose" @click="onClick" v-html="html" />
    <template #footer>
      <a :href="`/manual/${current}`" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-primary-700 hover:underline mr-auto">
        {{ t('manual.open_new_window') }}<Icon name="external" class="w-3.5 h-3.5" />
      </a>
      <button type="button" class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50" @click="emit('close')">{{ t('common.close') }}</button>
    </template>
  </Modal>
</template>
