<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { catalogApi, type CatalogKind, type IconLibrary } from '@/api/catalog'
import { apiErrorMessage } from '@/api/errors'
import { useFieldId } from '@/composables/useFieldId'

/**
 * Výběr ikony z připravených: instrument → přibalené ikony kryptoměn (CC0) a loga správců ETF, instituce → logo z webu
 * nebo z webu instituce ze seznamu českých bank, brokerů a burz. Po nastavení vyšle `picked(url)`.
 */
const props = defineProps<{ kind: CatalogKind; id: number; name: string; website?: string | null; hint?: string }>()
const emit = defineEmits<{ close: []; picked: [url: string] }>()
const { t } = useI18n()
const fid = useFieldId()

const library = ref<IconLibrary | null>(null)
const query = ref(props.hint ?? '')
const busy = ref<string | null>(null)
const error = ref('')
onMounted(async () => {
  try { library.value = await catalogApi.iconLibrary() } catch (e) { error.value = apiErrorMessage(e, t('common.error')) }
})

const fold = (s: string) => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
const crypto = computed(() => {
  const q = fold(query.value.trim())
  return (library.value?.crypto ?? []).filter(c => !q || fold(`${c.symbol} ${c.name}`).includes(q))
})
const etf = computed(() => {
  const q = fold(query.value.trim())
  return (library.value?.etf ?? []).filter(e => !q || fold(e.name).includes(q))
})
const institutions = computed(() => {
  const q = fold(query.value.trim())
  return (library.value?.institutions ?? []).filter(i => !q || fold(`${i.name} ${i.website}`).includes(q))
})

async function run(key: string, action: () => Promise<string>) {
  busy.value = key
  error.value = ''
  try {
    emit('picked', await action())
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = null
  }
}
const pickEtf = (slug: string) => run(slug, () => catalogApi.setIconFromLibrary(props.kind, props.id, { etf: slug }))
const pickCrypto = (symbol: string) => run(symbol, () => catalogApi.setIconFromLibrary(props.kind, props.id, { symbol: symbol.toLowerCase() }))
/** Přibalené logo instituce, jinak stažení z jejího webu. */
const pickInstitution = (i: IconLibrary['institutions'][number]) => (i.icon
  ? run(i.website, () => catalogApi.setIconFromLibrary(props.kind, props.id, { institution: i.icon! }))
  : fromWebsite(i.website))
const fromWebsite = (url?: string) => run(url ?? 'own', () => catalogApi.setIconFromWebsite(props.id, url))
</script>

<template>
  <Modal :title="t('icons.library_title', { name })" width-class="max-w-2xl" @close="emit('close')">
    <div class="space-y-4">
      <div v-if="kind === 'institutions'" class="rounded-md bg-neutral-50 border border-neutral-200 p-3 text-sm space-y-2">
        <p class="text-neutral-600">{{ t('icons.website_hint') }}</p>
        <button v-if="website" type="button" :disabled="!!busy"
          class="cursor-pointer px-3 h-9 text-sm bg-action hover:bg-action-hover disabled:bg-neutral-300 text-on-action font-medium rounded-md"
          @click="fromWebsite()">{{ busy === 'own' ? t('icons.fetching') : t('icons.from_own_website', { site: website.replace(/^https?:\/\//, '') }) }}</button>
      </div>

      <div>
        <label :for="fid('q')" class="block text-xs font-medium text-neutral-600 mb-1">{{ t('grid.search') }}</label>
        <input :id="fid('q')" v-model="query" type="search" :placeholder="kind === 'instruments' ? t('icons.search_crypto') : t('icons.search_institution')"
               class="w-full h-10 px-3 border border-field rounded-md bg-surface text-sm" />
      </div>

      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
      <p v-if="!library && !error" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>

      <template v-else-if="kind === 'instruments'">
      <div class="max-h-96 overflow-y-auto scrollbar-slim space-y-3">
      <section v-if="etf.length" :aria-label="t('icons.etf_issuers')">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-500 mb-1.5">{{ t('icons.etf_issuers') }}</h3>
        <ul class="grid grid-cols-3 sm:grid-cols-5 gap-2">
          <li v-for="e in etf" :key="e.slug">
            <button type="button" :disabled="!!busy" :title="e.name"
              class="cursor-pointer w-full flex flex-col items-center gap-1 p-2 rounded-md border border-neutral-200 hover:border-primary-500 hover:bg-primary-50 disabled:opacity-50"
              @click="pickEtf(e.slug)">
              <img :src="e.url" alt="" width="40" height="40" loading="lazy" class="rounded" />
              <span class="text-[11px] text-neutral-600 text-center leading-tight">{{ e.name }}</span>
            </button>
          </li>
        </ul>
      </section>
      <section :aria-label="t('icons.crypto')">
      <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-500 mb-1.5">{{ t('icons.crypto') }}</h3>
      <ul class="grid grid-cols-3 sm:grid-cols-5 gap-2">
        <li v-for="c in crypto" :key="c.symbol">
          <button type="button" :disabled="!!busy" :title="c.name"
            class="cursor-pointer w-full flex flex-col items-center gap-1 p-2 rounded-md border border-neutral-200 hover:border-primary-500 hover:bg-primary-50 disabled:opacity-50"
            @click="pickCrypto(c.symbol)">
            <img :src="c.url" :alt="''" width="40" height="40" loading="lazy" />
            <span class="text-xs font-mono font-medium">{{ c.symbol }}</span>
            <span class="text-[11px] text-neutral-500 truncate max-w-full">{{ c.name }}</span>
          </button>
        </li>
        <li v-if="!crypto.length" class="col-span-full text-sm text-neutral-500">{{ t('icons.no_match') }}</li>
      </ul>
      </section>
      </div>
      </template>

      <ul v-else class="divide-y divide-neutral-100 max-h-80 overflow-y-auto scrollbar-slim border border-neutral-200 rounded-md">
        <li v-for="i in institutions" :key="i.website + i.name" class="flex items-center justify-between gap-3 px-3 py-2">
          <img v-if="i.url" :src="i.url" alt="" width="28" height="28" loading="lazy" class="shrink-0 rounded" />
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium truncate">{{ i.name }}</span>
            <span class="block text-xs text-neutral-500 truncate">{{ t(`institutions.types.${i.type}`) }} · {{ i.website.replace(/^https?:\/\//, '') }}</span>
          </span>
          <button type="button" :disabled="!!busy" class="cursor-pointer shrink-0 px-3 h-8 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50"
            @click="pickInstitution(i)">{{ busy === i.website ? t('icons.fetching') : t('icons.use_logo') }}</button>
        </li>
        <li v-if="!institutions.length" class="px-3 py-2 text-sm text-neutral-500">{{ t('icons.no_match') }}</li>
      </ul>
      <p v-if="kind === 'instruments'" class="text-xs text-neutral-500">{{ t('icons.library_license') }}</p>
    </div>
    <template #footer>
      <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50" @click="emit('close')">{{ t('common.close') }}</button>
    </template>
  </Modal>
</template>
