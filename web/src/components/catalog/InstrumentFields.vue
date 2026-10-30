<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ASSET_CLASSES, PRICE_SOURCES } from '@/api/catalog'
import { useFieldId } from '@/composables/useFieldId'
import { REF_SOURCES, type InstrumentForm } from '@/utils/instrumentForm'

/** Pole instrumentu (třída, symbol, název, měna, ISIN, zdroj ceny, odkazy na zdroje) — stav drží rodič. */
const props = defineProps<{ form: InstrumentForm }>()
const { t } = useI18n()
const fid = useFieldId()
const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
// Formulář je reaktivní objekt rodiče — pole ho mění přímo.
const form = props.form
</script>

<template>
  <div class="space-y-3">
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label :for="fid('asset_class')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.asset_class') }}</label>
      <select :id="fid('asset_class')" v-model="form.asset_class" :class="inputClass">
        <option v-for="c in ASSET_CLASSES" :key="c" :value="c">{{ t(`instruments.classes.${c}`) }}</option>
      </select>
    </div>
    <div>
      <label :for="fid('symbol')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.symbol') }}</label>
      <input :id="fid('symbol')" v-model="form.symbol" required maxlength="40" class="uppercase font-mono" :class="inputClass" />
    </div>
  </div>
  <div>
    <label :for="fid('name')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.name') }}</label>
    <input :id="fid('name')" v-model="form.name" required maxlength="190" :class="inputClass" />
  </div>
  <div class="grid grid-cols-3 gap-3">
    <div>
      <label :for="fid('quote_currency')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.quote_currency') }}</label>
      <input :id="fid('quote_currency')" v-model="form.quote_currency" required maxlength="3" class="uppercase font-mono" :class="inputClass" />
    </div>
    <div class="col-span-2">
      <label :for="fid('isin')" class="block text-sm font-medium text-neutral-700 mb-1">ISIN <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
      <input :id="fid('isin')" v-model="form.isin" maxlength="12" class="uppercase font-mono" :class="inputClass" />
    </div>
  </div>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label :for="fid('price_source')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.price_source') }}</label>
      <select :id="fid('price_source')" v-model="form.price_source" :class="inputClass">
        <option v-for="s in PRICE_SOURCES" :key="s" :value="s">{{ t(`instruments.sources.${s}`) }}</option>
      </select>
    </div>
    <div>
      <label :for="fid('decimals')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.decimals') }}</label>
      <input :id="fid('decimals')" v-model.number="form.decimals" type="number" min="0" max="18" :class="inputClass" />
    </div>
  </div>
  <fieldset class="border border-neutral-200 rounded-md p-3 space-y-2">
    <legend class="text-xs font-medium text-neutral-600 px-1">{{ t('instruments.source_refs') }}</legend>
    <div v-for="s in REF_SOURCES" :key="s" class="flex items-center gap-2">
      <span class="w-24 text-xs text-neutral-600">{{ t(`instruments.sources.${s}`) }}</span>
      <input v-model="form.refs[s]" maxlength="64" :aria-label="`${t('instruments.source_refs')}: ${t(`instruments.sources.${s}`)}`" :placeholder="t(`instruments.ref_placeholders.${s}`)" class="flex-1 h-9 px-3 border border-field rounded-md bg-surface text-sm font-mono" />
    </div>
  </fieldset>
  </div>
</template>
