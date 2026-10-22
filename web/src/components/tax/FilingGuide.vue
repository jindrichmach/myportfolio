<script setup lang="ts">
/**
 * Podklad pro daňové přiznání: zeptá se, jak uživatel přiznání podává (pamatuje si to), a podle toho nabídne
 * řádky k přepisu do EPO / Moje daně, souhrn pro poradce, nebo CSV pro jiný program. Hodnoty počítá backend.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxApi, type DividendMethod, type FilingGuide, type RateMethod } from '@/api/tax'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'

type FilingMode = 'epo' | 'advisor' | 'software'
const MODE_KEY = 'myportfolio-tax-filing'
const DIVIDENDS_KEY = 'myportfolio-tax-dividends'

const props = defineProps<{ year: number; rate: RateMethod; hasDividends: boolean }>()
const { t } = useI18n()

function read<T extends string>(key: string, allowed: readonly T[]): T | null {
  try {
    const v = localStorage.getItem(key)
    return allowed.includes(v as T) ? (v as T) : null
  } catch {
    return null
  }
}
function write(key: string, value: string) {
  try {
    localStorage.setItem(key, value)
  } catch {
    // jen pro tuto návštěvu
  }
}

const mode = ref<FilingMode | null>(read(MODE_KEY, ['epo', 'advisor', 'software'] as const))
const dividends = ref<DividendMethod>(read(DIVIDENDS_KEY, ['16a', '38'] as const) ?? '16a')
const guide = ref<FilingGuide | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
  if (mode.value === null) return
  loading.value = true
  error.value = ''
  try {
    guide.value = await taxApi.filing(props.year, props.rate, dividends.value)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
watch(() => [props.year, props.rate, dividends.value, mode.value], load, { immediate: true })
watch(mode, m => m && write(MODE_KEY, m))
watch(dividends, d => write(DIVIDENDS_KEY, d))

const kc = (v: string) => formatMoney(Number(v), 'CZK', 0)
const csvUrl = computed(() => taxApi.filingCsvUrl(props.year, props.rate, dividends.value))
const detailCsvUrl = computed(() => taxApi.csvUrl(props.year, props.rate))
const hasAttachment2 = computed(() => (guide.value?.attachment2.rows.length ?? 0) > 0)
const d16a = computed(() => (guide.value?.dividends.method === '16a' ? guide.value.dividends : null))
const d38 = computed(() => (guide.value?.dividends.method === '38' ? guide.value.dividends : null))
const hasForeignDividends = computed(() => props.hasDividends && guide.value !== null
  && (d16a.value ? d16a.value.r401a !== '0' : (d38.value?.main_r38 ?? '0') !== '0'))
const p4Rows = computed(() => (d16a.value ? (['401a', '406', '409', '410', '411', '412', '413', '414'] as const).map(r => ({ row: r, value: d16a.value![`r${r}`] })) : []))

const radio = 'mt-0.5 border-neutral-300 text-primary-700'
const th = 'px-3 py-1.5 text-left font-medium'
const td = 'px-3 py-1.5 tabular-nums'
</script>

<template>
  <section class="bg-surface border border-primary-500/30 rounded-lg shadow-sm overflow-hidden mb-4 print:border-neutral-300">
    <h2 class="px-4 py-2.5 text-sm font-semibold border-b border-neutral-200 bg-primary-50 text-primary-700">{{ t('filing.title', { year }) }}</h2>
    <div class="p-4 space-y-4">
      <fieldset class="print:hidden">
        <legend class="text-sm font-medium text-neutral-700 mb-2">{{ t('filing.question') }}</legend>
        <div class="grid sm:grid-cols-3 gap-2">
          <label v-for="m in (['epo', 'advisor', 'software'] as const)" :key="m"
                 class="flex items-start gap-2 text-sm border rounded-md px-3 py-2 cursor-pointer"
                 :class="mode === m ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 hover:bg-neutral-50'">
            <input v-model="mode" type="radio" :value="m" :class="radio" />
            <span>{{ t(`filing.modes.${m}`) }}<span class="block text-xs text-neutral-500">{{ t(`filing.mode_hints.${m}`) }}</span></span>
          </label>
        </div>
      </fieldset>

      <fieldset v-if="mode && hasDividends" class="print:hidden">
        <legend class="text-sm font-medium text-neutral-700 mb-2">{{ t('filing.dividends_question') }}</legend>
        <div class="grid sm:grid-cols-2 gap-2">
          <label v-for="m in (['16a', '38'] as const)" :key="m" class="flex items-start gap-2 text-sm">
            <input v-model="dividends" type="radio" :value="m" :class="radio" />
            <span>{{ t(`filing.dividend_methods.${m}`) }}<span class="block text-xs text-neutral-500">{{ t(`filing.dividend_hints.${m}`) }}</span></span>
          </label>
        </div>
      </fieldset>

      <p v-if="loading && !guide" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>

      <template v-if="mode && guide">
        <ul v-if="guide.warnings.length" class="text-xs rounded-md bg-warning-50 border border-warning-500/40 text-warning-600 px-3 py-2 space-y-1">
          <li v-for="w in guide.warnings" :key="w">{{ t(`filing.warnings.${w}`, { year }) }}</li>
        </ul>

        <!-- EPO / Moje daně: řádky k přepisu -->
        <template v-if="mode === 'epo'">
          <div>
            <h3 class="text-sm font-semibold mb-1">{{ t('filing.attachment2') }}</h3>
            <p v-if="!hasAttachment2" class="text-sm text-neutral-500">{{ t('filing.no_attachment2') }}</p>
            <template v-else>
              <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead class="text-xs text-neutral-500"><tr>
                  <th :class="th">{{ t('filing.col_type') }}</th><th :class="th + ' text-right'">{{ t('filing.col_income') }}</th>
                  <th :class="th + ' text-right'">{{ t('filing.col_expense') }}</th><th :class="th + ' text-right'">{{ t('filing.col_difference') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="r in guide.attachment2.rows" :key="r.code">
                    <td :class="td">{{ t(`filing.types.${r.code}`) }}</td><td :class="td + ' text-right'">{{ kc(r.income) }}</td>
                    <td :class="td + ' text-right'">{{ kc(r.expense) }}</td><td :class="td + ' text-right'">{{ kc(r.difference) }}</td>
                  </tr>
                  <tr><td :class="td">{{ t('filing.rows.207') }}</td><td :class="td + ' text-right font-semibold'" colspan="3">{{ kc(guide.attachment2.r207) }}</td></tr>
                  <tr><td :class="td">{{ t('filing.rows.208') }}</td><td :class="td + ' text-right font-semibold'" colspan="3">{{ kc(guide.attachment2.r208) }}</td></tr>
                  <tr><td :class="td">{{ t('filing.rows.209') }}</td><td :class="td + ' text-right font-semibold'" colspan="3">{{ kc(guide.attachment2.r209) }}</td></tr>
                </tbody>
              </table></div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('filing.attachment2_note') }}</p>
            </template>
          </div>

          <div v-if="hasForeignDividends">
            <template v-if="d16a">
              <h3 class="text-sm font-semibold mb-1">{{ t('filing.attachment4') }}</h3>
              <table class="w-full text-sm">
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="r in p4Rows" :key="r.row"><td :class="td">{{ t(`filing.rows.${r.row}`) }}</td><td :class="td + ' text-right font-semibold'">{{ kc(r.value) }}</td></tr>
                </tbody>
              </table>
              <p class="text-xs text-neutral-500 mt-1">{{ t('filing.attachment4_note') }}</p>
            </template>
            <template v-else-if="d38">
              <h3 class="text-sm font-semibold mb-1">{{ t('filing.attachment3') }}</h3>
              <table class="w-full text-sm">
                <thead class="text-xs text-neutral-500"><tr>
                  <th :class="th">{{ t('filing.col_country') }}</th><th :class="th + ' text-right'">{{ t('filing.rows.321') }}</th>
                  <th :class="th + ' text-right'">{{ t('filing.rows.323') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="c in d38.countries" :key="c.country ?? '??'">
                    <td :class="td">{{ c.country ?? '??' }}</td><td :class="td + ' text-right'">{{ kc(c.r321) }}</td>
                    <td :class="td + ' text-right'">{{ kc(c.r323) }}</td>
                  </tr>
                </tbody>
              </table>
              <p class="text-xs text-neutral-500 mt-1">{{ t('filing.attachment3_note') }}</p>
            </template>
          </div>

          <div>
            <h3 class="text-sm font-semibold mb-1">{{ t('filing.main') }}</h3>
            <table class="w-full text-sm">
              <tbody class="divide-y divide-neutral-100">
                <tr v-if="d38 && hasForeignDividends"><td :class="td">{{ t('filing.rows.38') }}</td><td :class="td + ' text-right font-semibold'">{{ kc(d38.main_r38) }}</td></tr>
                <tr><td :class="td">{{ t('filing.rows.40') }}</td><td :class="td + ' text-right font-semibold'">{{ kc(guide.attachment2.main_r40) }}</td></tr>
                <tr v-if="d16a && hasForeignDividends"><td :class="td">{{ t('filing.rows.74a') }}</td><td :class="td + ' text-right font-semibold'">{{ kc(d16a.main_r74a) }}</td></tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Daňový poradce: souhrn + soubory -->
        <template v-else-if="mode === 'advisor'">
          <p class="text-sm text-neutral-600">{{ t('filing.advisor_intro') }}</p>
          <ul class="text-sm space-y-1">
            <li>{{ t('filing.advisor.securities', { income: kc(guide.attachment2.rows.find(r => r.code === 'D')?.income ?? '0'), expense: kc(guide.attachment2.rows.find(r => r.code === 'D')?.expense ?? '0'), exempt: kc(guide.exempt.securities) }) }}</li>
            <li>{{ t('filing.advisor.crypto', { income: kc(guide.attachment2.rows.find(r => r.code === 'C')?.income ?? '0'), expense: kc(guide.attachment2.rows.find(r => r.code === 'C')?.expense ?? '0'), exempt: kc(guide.exempt.crypto) }) }}</li>
            <li v-if="hasForeignDividends">{{ t('filing.advisor.dividends', { gross: kc(d16a ? d16a.r401a : d38!.main_r38), withheld: kc(d16a ? d16a.r412 : String(d38!.countries.reduce((s, c) => s + Number(c.r323), 0))) }) }}</li>
            <li v-if="guide.interest_total !== '0'">{{ t('filing.advisor.interest', { total: kc(guide.interest_total) }) }}</li>
            <li v-if="guide.domestic_dividends !== '0'">{{ t('filing.advisor.domestic', { total: kc(guide.domestic_dividends) }) }}</li>
          </ul>
          <div class="flex flex-wrap gap-2 print:hidden">
            <a :href="detailCsvUrl" class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('filing.download_detail') }}</a>
            <a :href="csvUrl" class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('filing.download_guide') }}</a>
          </div>
          <p class="text-xs text-neutral-500">{{ t('filing.advisor_note') }}</p>
        </template>

        <!-- Jiný program: CSV -->
        <template v-else>
          <p class="text-sm text-neutral-600">{{ t('filing.software_intro') }}</p>
          <div class="flex flex-wrap gap-2 print:hidden">
            <a :href="csvUrl" class="h-9 px-3 inline-flex items-center bg-action hover:bg-action-hover text-on-action rounded-md text-sm font-medium">{{ t('filing.download_guide') }}</a>
            <a :href="detailCsvUrl" class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">{{ t('filing.download_detail') }}</a>
          </div>
          <p class="text-xs text-neutral-500">{{ t('filing.software_note') }}</p>
        </template>

        <p class="text-xs text-neutral-500 border-t border-neutral-200 pt-3">{{ t('filing.disclaimer', { forms: guide.forms }) }}</p>
      </template>
    </div>
  </section>
</template>
