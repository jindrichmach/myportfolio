<script setup lang="ts">
/** Systém → Nastavení: nastavení instance po záložkách; každá záložka se ukládá samostatně. */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTabQuery } from '@/composables/useTabQuery'
import { systemApi, type HealthResponse } from '@/api/client'
import Tabs, { type TabItem } from '@/components/ui/Tabs.vue'
import SettingsSmtp from '@/components/admin/settings/SettingsSmtp.vue'
import SettingsAppearance from '@/components/admin/settings/SettingsAppearance.vue'

const { t, te } = useI18n()

const TAB_KEYS = ['smtp', 'appearance'] as const
type SettingsTab = (typeof TAB_KEYS)[number]
const tab = useTabQuery<SettingsTab>(TAB_KEYS, 'smtp')
const tabs = computed<TabItem<SettingsTab>[]>(() => [
  { key: 'smtp', label: t('admin_settings.tab_smtp') },
  { key: 'appearance', label: t('admin_settings.tab_appearance') },
])

// Diagnostická varování z /api/health; známé kódy přeložené, ostatní serverovou zprávou
const healthWarnings = ref<NonNullable<HealthResponse['warnings']>>([])
const warningText = (w: { code: string; message: string }) =>
  te(`admin_settings.health.${w.code}`) ? t(`admin_settings.health.${w.code}`) : w.message
onMounted(async () => {
  try { healthWarnings.value = (await systemApi.health()).warnings ?? [] } catch {}
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('admin_settings.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('admin_settings.subtitle') }}</p>
    </div>

    <div v-if="healthWarnings.length" role="status" class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
      <p class="font-medium">{{ t('admin_settings.health_title') }}</p>
      <ul class="mt-1 list-disc pl-5 space-y-0.5">
        <li v-for="w in healthWarnings" :key="w.code">{{ warningText(w) }}</li>
      </ul>
    </div>

    <Tabs v-model="tab" :tabs="tabs" :label="t('admin_settings.tabs_label')" />

    <SettingsSmtp v-if="tab === 'smtp'" />
    <SettingsAppearance v-else-if="tab === 'appearance'" />
  </div>
</template>
