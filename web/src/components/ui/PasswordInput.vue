<script setup lang="ts">
/**
 * Pole pro heslo s tlačítkem oka uvnitř (zobrazit / skrýt). Atributy (id, autocomplete, required, class …)
 * jdou na `<input>`.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/ui/Icon.vue'

defineOptions({ inheritAttrs: false })
const value = defineModel<string>({ default: '' })

const { t } = useI18n()
const shown = ref(false)
</script>

<template>
  <div class="relative">
    <input v-bind="$attrs" v-model="value" :type="shown ? 'text' : 'password'" class="pr-10" />
    <button type="button" @click="shown = !shown"
            :aria-label="shown ? t('auth.hide_password') : t('auth.show_password')"
            :title="shown ? t('auth.hide_password') : t('auth.show_password')"
            class="cursor-pointer absolute inset-y-0 right-0 flex items-center px-3 text-neutral-500 hover:text-neutral-800">
      <Icon :name="shown ? 'eye-off' : 'eye'" class="w-4 h-4" />
    </button>
  </div>
</template>
