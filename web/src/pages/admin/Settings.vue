<script setup lang="ts">
/** Systém → Nastavení: nastavení instance po záložkách; každá záložka se ukládá samostatně. */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTabQuery } from '@/composables/useTabQuery'
import Tabs, { type TabItem } from '@/components/ui/Tabs.vue'
import SettingsSmtp from '@/components/admin/settings/SettingsSmtp.vue'
import SettingsAppearance from '@/components/admin/settings/SettingsAppearance.vue'

const { t } = useI18n()

const TAB_KEYS = ['smtp', 'appearance'] as const
type SettingsTab = (typeof TAB_KEYS)[number]
const tab = useTabQuery<SettingsTab>(TAB_KEYS, 'smtp')
const tabs = computed<TabItem<SettingsTab>[]>(() => [
  { key: 'smtp', label: t('admin_settings.tab_smtp') },
  { key: 'appearance', label: t('admin_settings.tab_appearance') },
])
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('admin_settings.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('admin_settings.subtitle') }}</p>
    </div>

    <Tabs v-model="tab" :tabs="tabs" :label="t('admin_settings.tabs_label')" />

    <SettingsSmtp v-if="tab === 'smtp'" />
    <SettingsAppearance v-else-if="tab === 'appearance'" />
  </div>
</template>
