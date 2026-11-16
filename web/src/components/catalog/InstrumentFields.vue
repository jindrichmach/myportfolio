<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ASSET_CLASSES, PRICE_SOURCES } from '@/api/catalog'
import { useFieldId } from '@/composables/useFieldId'
import InfoTip from '@/components/ui/InfoTip.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { PRICE_CHAINS, REF_SOURCES, type InstrumentForm } from '@/utils/instrumentForm'

/**
 * Pole instrumentu (třída, symbol, název, měna, ISIN, zdroj ceny, odkazy na zdroje) — stav drží rodič.
 * `chainRefs`: identifikátory jen pro zdroje z řetězce vybraného zdroje ceny (hlavní + záloha), u ručních cen žádné.
 */
const props = defineProps<{ form: InstrumentForm; chainRefs?: boolean }>()
const { t } = useI18n()
const fid = useFieldId()
const inputClass = 'w-full h-10 px-3 border border-field rounded-md bg-surface text-sm'
// Formulář je reaktivní objekt rodiče — pole ho mění přímo.
const form = props.form
const refSources = computed(() => (props.chainRefs ? PRICE_CHAINS[form.price_source] : REF_SOURCES))
</script>

<template>
  <div class="space-y-3">
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label :for="fid('asset_class')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.asset_class') }}</label>
      <SearchableSelect :input-id="fid('asset_class')" v-model="form.asset_class" :options="ASSET_CLASSES.map(c => ({ value: c, label: t(`instruments.classes.${c}`) }))" />
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
      <label :for="fid('quote_currency')" class="flex items-center gap-1 text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.quote_currency') }}<InfoTip :text="t('instruments.quote_currency_hint')" :name="t('instruments.quote_currency')" /></label>
      <input :id="fid('quote_currency')" v-model="form.quote_currency" required maxlength="3" class="uppercase font-mono" :class="inputClass" />
    </div>
    <div class="col-span-2">
      <label :for="fid('isin')" class="block text-sm font-medium text-neutral-700 mb-1">ISIN <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span></label>
      <input :id="fid('isin')" v-model="form.isin" maxlength="12" class="uppercase font-mono" :class="inputClass" />
    </div>
  </div>
  <div v-if="form.asset_class !== 'crypto'">
    <label :for="fid('tax_country')" class="flex items-center gap-1 text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.tax_country') }} <span class="text-xs font-normal text-neutral-500">{{ t('common.optional') }}</span><InfoTip :text="t('instruments.tax_country_hint')" :name="t('instruments.tax_country')" /></label>
    <input :id="fid('tax_country')" v-model="form.tax_country" maxlength="2" placeholder="TW" class="uppercase font-mono w-24" :class="inputClass" />
  </div>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label :for="fid('price_source')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.price_source') }}</label>
      <SearchableSelect :input-id="fid('price_source')" v-model="form.price_source" :options="PRICE_SOURCES.map(s => ({ value: s, label: t(`instruments.sources.${s}`) }))" />
    </div>
    <div>
      <label :for="fid('decimals')" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('instruments.decimals') }}</label>
      <input :id="fid('decimals')" v-model.number="form.decimals" type="number" min="0" max="18" :class="inputClass" />
    </div>
  </div>
  <fieldset v-if="refSources.length" class="border border-neutral-200 rounded-md p-3 space-y-2">
    <legend class="text-xs font-medium text-neutral-600 px-1">{{ t('instruments.source_refs') }}</legend>
    <div v-for="(s, i) in refSources" :key="s" class="flex items-center gap-2">
      <span class="w-24 text-xs text-neutral-600">{{ t(`instruments.sources.${s}`) }}<span v-if="chainRefs && i > 0" class="block text-neutral-400">{{ t('instruments.ref_fallback') }}</span></span>
      <input v-model="form.refs[s]" maxlength="64" :aria-label="`${t('instruments.source_refs')}: ${t(`instruments.sources.${s}`)}`" :placeholder="t(`instruments.ref_placeholders.${s}`)" class="flex-1 h-9 px-3 border border-field rounded-md bg-surface text-sm font-mono" />
    </div>
  </fieldset>
  </div>
</template>
