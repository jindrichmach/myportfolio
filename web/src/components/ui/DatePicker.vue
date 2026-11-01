<script setup lang="ts">
import { computed, nextTick, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  getLocalTimeZone, now, parseDate, parseDateTime, toCalendarDate, toCalendarDateTime, today, type DateValue,
} from '@internationalized/date'
import {
  DatePickerRoot, DatePickerField, DatePickerInput, DatePickerTrigger, DatePickerContent,
  DatePickerCalendar, DatePickerGrid, DatePickerGridHead, DatePickerGridBody, DatePickerGridRow,
  DatePickerHeadCell, DatePickerCell, DatePickerCellTrigger,
  MonthPickerRoot, MonthPickerGrid, MonthPickerGridBody, MonthPickerGridRow, MonthPickerCell, MonthPickerCellTrigger,
  YearPickerRoot, YearPickerGrid, YearPickerGridBody, YearPickerGridRow, YearPickerCell, YearPickerCellTrigger,
} from 'reka-ui'
import Icon from '@/components/ui/Icon.vue'

/**
 * Datum (volitelně s časem) — segmentované pole + kalendář v popoveru (Reka UI, bez vlastních stylů).
 * v-model je string jako u nativního inputu: `YYYY-MM-DD`, s `withTime` `YYYY-MM-DDTHH:mm`, prázdné = ''.
 * `id` dostane skrytý nativní input (required/min/max) — funguje `<label for>` i validace formuláře.
 * Kalendář se renderuje na místě (bez portálu), aby zůstal uvnitř Modalu kvůli Tab trapu.
 * Klik na měsíc / rok v hlavičce přepne na mřížku měsíců / let; všechny pohledy sdílí `placeholder` (zobrazený měsíc).
 * S `withTime` jsou vedle kalendáře sloupce hodin a minut a výběr dne kalendář nezavírá (zavře „Hotovo“ / klik mimo).
 */
const props = withDefaults(defineProps<{
  modelValue: string
  withTime?: boolean
  id?: string
  required?: boolean
  min?: string
  max?: string
  size?: 'md' | 'sm'
  ariaLabel?: string
}>(), {
  withTime: false,
  required: false,
  size: 'md',
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t, locale } = useI18n()

type View = 'day' | 'month' | 'year'

const open = ref(false)
const view = ref<View>('day')
const panel = ref<HTMLElement | null>(null)
const timeCols = ref<HTMLElement | null>(null)

function parse(value: string | undefined, withTime: boolean): DateValue | undefined {
  if (!value) return undefined
  try {
    return withTime ? parseDateTime(value) : parseDate(value.slice(0, 10))
  } catch {
    return undefined
  }
}

const value = computed(() => parse(props.modelValue, props.withTime))
const minValue = computed(() => parse(props.min, false))
const maxValue = computed(() => parse(props.max, false))
// Stejné locale jako useFormat (en → en-US); čas vždy 24h.
const intlLocale = computed(() => (locale.value === 'en' ? 'en-US' : 'cs-CZ'))

// Typ placeholderu musí odpovídat granularitě (CalendarDateTime s časem).
// Bez hodnoty začne na dnešku, oříznutém do min/max (jinak by se otevřel měsíc se samými zakázanými dny).
const todayDate = today(getLocalTimeZone())
const startDate = maxValue.value && todayDate.compare(maxValue.value) > 0 ? toCalendarDate(maxValue.value)
  : minValue.value && todayDate.compare(minValue.value) < 0 ? toCalendarDate(minValue.value) : todayDate
const placeholder = shallowRef<DateValue>(value.value?.copy() ?? (props.withTime ? toCalendarDateTime(startDate) : startDate))
const pickerDate = computed(() => toCalendarDate(placeholder.value))
const monthLabel = computed(() =>
  new Intl.DateTimeFormat(intlLocale.value, { month: 'long' }).format(placeholder.value.toDate(getLocalTimeZone())))

function onUpdate(v: DateValue | undefined) {
  emit('update:modelValue', v ? v.toString().slice(0, props.withTime ? 16 : 10) : '')
}

// Šipky: den → po měsících, měsíc → po letech, rok → po desetiletích (mřížka let začíná na začátku dekády).
const pageUnit = { day: 'month', month: 'year', year: 'decade' } as const
function page(dir: 1 | -1) {
  const step = view.value === 'day' ? { months: dir } : { years: view.value === 'month' ? dir : dir * 10 }
  placeholder.value = placeholder.value.add(step)
}

function pickMonth(v: DateValue | DateValue[] | undefined) {
  if (Array.isArray(v)) v = v[0]
  if (v) placeholder.value = placeholder.value.set({ year: v.year, month: v.month })
  view.value = 'day'
}

function pickYear(v: DateValue | DateValue[] | undefined) {
  if (Array.isArray(v)) v = v[0]
  if (v) placeholder.value = placeholder.value.set({ year: v.year })
  view.value = 'month'
}

function emitDate(v: DateValue) {
  placeholder.value = v
  onUpdate(v)
}

const tz = getLocalTimeZone()
const todayDisabled = computed(() => {
  const d = today(tz)
  return (!!minValue.value && d.compare(minValue.value) < 0) || (!!maxValue.value && d.compare(maxValue.value) > 0)
})

// Dnes: s časem ponechá zadaný čas (prázdné pole dostane aktuální), bez času kalendář zavře.
function pickToday() {
  const d = today(tz)
  if (!props.withTime) {
    emitDate(d)
    open.value = false
    return
  }
  const base = value.value ?? toCalendarDateTime(now(tz)).set({ second: 0, millisecond: 0 })
  emitDate(base.set({ year: d.year, month: d.month, day: d.day }))
}

const hours = Array.from({ length: 24 }, (_, i) => i)
const minutes = Array.from({ length: 60 }, (_, i) => i)
const pad = (n: number) => String(n).padStart(2, '0')
const timeOf = computed(() => (value.value && 'hour' in value.value ? value.value : undefined))
// Buňky kalendáře nesou čas z placeholderu v době vytvoření mřížky — bez přegenerování (key) by klik na den vrátil starý čas.
const timeKey = computed(() => (timeOf.value ? `${timeOf.value.hour}:${timeOf.value.minute}` : ''))
watch(value, (v) => { if (v) placeholder.value = v })

// Bez zadané hodnoty vezme čas na dni zobrazeném v kalendáři.
function pickTime(part: 'hour' | 'minute', n: number) {
  emitDate((value.value ?? placeholder.value).set({ [part]: n }))
}

// Šipky nahoru/dolů posouvají fokus ve sloupci (roving tabindex — Tabem se neprochází 84 tlačítek).
function onTimeKey(e: KeyboardEvent) {
  if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return
  const items = Array.from((e.currentTarget as HTMLElement).querySelectorAll<HTMLElement>('button'))
  const i = items.indexOf(document.activeElement as HTMLElement)
  if (i < 0) return
  e.preventDefault()
  items[Math.min(items.length - 1, Math.max(0, i + (e.key === 'ArrowDown' ? 1 : -1)))].focus()
}

// Vybraná hodina / minuta doprostřed sloupce (scrollTop, ne scrollIntoView — ten by posunul i Modal).
watch([open, timeOf], async () => {
  await nextTick()
  timeCols.value?.querySelectorAll<HTMLElement>('[aria-pressed="true"]').forEach((el) => {
    const col = el.parentElement!
    col.scrollTop = el.offsetTop - col.offsetTop - (col.clientHeight - el.offsetHeight) / 2
  })
})

watch(open, (o) => { if (o) view.value = 'day' })

// Po přepnutí pohledu fokus na aktivní buňku (jinak by zůstal na odmontovaném tlačítku).
watch(view, async () => {
  await nextTick()
  const cells = panel.value?.querySelectorAll<HTMLElement>('[data-reka-calendar-cell-trigger], [data-reka-month-picker-cell-trigger], [data-reka-year-picker-cell-trigger]')
  const list = Array.from(cells ?? [])
  ;(list.find((el) => el.hasAttribute('data-focused')) ?? list.find((el) => !el.hasAttribute('data-disabled')))?.focus()
})

// Escape zavře jen kalendář — jinak by ho na document zachytil i Modal a zavřel celý dialog.
// Volá se i z pole (fokus může zůstat na tlačítku kalendáře); zavřený kalendář Escape pustí dál.
function onEscape(e: KeyboardEvent) {
  if (!open.value) return
  e.stopPropagation()
  open.value = false
}

const navClass = 'cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40'
const headingClass = 'cursor-pointer px-2 h-8 rounded-md text-sm font-semibold hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 aria-pressed:bg-primary-50 aria-pressed:text-primary-700'
const timeClass = 'cursor-pointer shrink-0 w-11 h-8 rounded-md text-sm tabular-nums hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 aria-pressed:bg-action aria-pressed:text-on-action aria-pressed:hover:bg-action-hover'
const footerClass = 'cursor-pointer inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md text-sm font-medium text-primary-700 hover:bg-primary-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:opacity-40 disabled:pointer-events-none'
const cellClass = 'cursor-pointer inline-flex items-center justify-center rounded-md text-sm tabular-nums hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 data-today:font-semibold not-data-selected:not-data-disabled:data-today:text-primary-700 data-disabled:text-neutral-300 data-disabled:pointer-events-none data-selected:bg-action data-selected:text-on-action data-selected:hover:bg-action-hover'
</script>

<template>
  <DatePickerRoot v-model:open="open" v-model:placeholder="placeholder" :model-value="value" :id="id" :required="required"
    :min-value="minValue" :max-value="maxValue" :locale="intlLocale" :granularity="withTime ? 'minute' : 'day'"
    :hour-cycle="24" :close-on-select="!withTime" prevent-deselect @update:model-value="onUpdate">
    <DatePickerField v-slot="{ segments }" :aria-label="ariaLabel" @keydown.esc="onEscape"
      class="w-full flex items-center px-3 border border-field rounded-md bg-surface text-sm tabular-nums focus-within:ring-2 focus-within:ring-primary-500/20 focus-within:border-primary-500 data-invalid:border-danger-500"
      :class="size === 'sm' ? 'h-9' : 'h-10'">
      <template v-for="item in segments" :key="item.part">
        <DatePickerInput v-if="item.part === 'literal'" :part="item.part" class="text-neutral-400">{{ item.value }}</DatePickerInput>
        <DatePickerInput v-else :part="item.part"
          class="rounded-sm px-0.5 focus:outline-none focus:bg-primary-100 focus:text-primary-900 data-placeholder:text-neutral-400">{{ item.value }}</DatePickerInput>
      </template>
      <DatePickerTrigger :aria-label="t('date_picker.open')"
        class="cursor-pointer ml-auto -mr-1.5 w-7 h-7 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
        <Icon name="calendar" class="w-4 h-4" />
      </DatePickerTrigger>
    </DatePickerField>

    <DatePickerContent :portal="{ disabled: true }" :side-offset="4" align="end"
      class="z-50 rounded-lg border border-neutral-200 bg-surface p-3 shadow-lg" @keydown.esc="onEscape">
      <div class="flex">
      <div ref="panel" class="w-[252px]">
        <div class="flex items-center justify-between mb-2">
          <button type="button" :aria-label="t(`date_picker.prev_${pageUnit[view]}`)" :class="navClass" @click="page(-1)">
            <Icon name="chevron-left" class="w-4 h-4" />
          </button>
          <div class="flex items-center gap-0.5">
            <button type="button" :aria-pressed="view === 'month'" :aria-label="t('date_picker.choose_month')"
              :class="headingClass" class="capitalize" @click="view = view === 'month' ? 'day' : 'month'">{{ monthLabel }}</button>
            <button type="button" :aria-pressed="view === 'year'" :aria-label="t('date_picker.choose_year')"
              :class="headingClass" class="tabular-nums" @click="view = view === 'year' ? 'day' : 'year'">{{ placeholder.year }}</button>
          </div>
          <button type="button" :aria-label="t(`date_picker.next_${pageUnit[view]}`)" :class="navClass" @click="page(1)">
            <Icon name="chevron-right" class="w-4 h-4" />
          </button>
        </div>

        <DatePickerCalendar v-if="view === 'day'" :key="timeKey" v-slot="{ weekDays, grid }">
          <DatePickerGrid v-for="month in grid" :key="month.value.toString()" class="border-collapse select-none">
            <DatePickerGridHead>
              <DatePickerGridRow class="flex">
                <DatePickerHeadCell v-for="day in weekDays" :key="day" class="w-9 pb-1 text-xs font-normal text-neutral-500">{{ day }}</DatePickerHeadCell>
              </DatePickerGridRow>
            </DatePickerGridHead>
            <DatePickerGridBody>
              <DatePickerGridRow v-for="(week, i) in month.rows" :key="i" class="flex">
                <DatePickerCell v-for="date in week" :key="date.toString()" :date="date">
                  <DatePickerCellTrigger :day="date" :month="month.value" :class="cellClass" class="w-9 h-9 data-outside-view:text-neutral-400" />
                </DatePickerCell>
              </DatePickerGridRow>
            </DatePickerGridBody>
          </DatePickerGrid>
        </DatePickerCalendar>

        <MonthPickerRoot v-else-if="view === 'month'" v-slot="{ grid }" :placeholder="pickerDate" :model-value="pickerDate"
          :min-value="minValue" :max-value="maxValue" :locale="intlLocale" @update:model-value="pickMonth">
          <MonthPickerGrid class="w-full select-none">
            <MonthPickerGridBody>
              <MonthPickerGridRow v-for="(row, i) in grid.rows" :key="i" class="grid grid-cols-4 gap-1 mb-1">
                <MonthPickerCell v-for="m in row" :key="m.toString()" :date="m">
                  <MonthPickerCellTrigger :month="m" :class="cellClass" class="w-full h-10 capitalize" />
                </MonthPickerCell>
              </MonthPickerGridRow>
            </MonthPickerGridBody>
          </MonthPickerGrid>
        </MonthPickerRoot>

        <YearPickerRoot v-else v-slot="{ grid }" :placeholder="pickerDate" :model-value="pickerDate"
          :min-value="minValue" :max-value="maxValue" :locale="intlLocale" @update:model-value="pickYear">
          <YearPickerGrid class="w-full select-none">
            <YearPickerGridBody>
              <YearPickerGridRow v-for="(row, i) in grid.rows" :key="i" class="grid grid-cols-4 gap-1 mb-1">
                <YearPickerCell v-for="y in row" :key="y.toString()" :date="y">
                  <YearPickerCellTrigger :year="y" :class="cellClass" class="w-full h-10" />
                </YearPickerCell>
              </YearPickerGridRow>
            </YearPickerGridBody>
          </YearPickerGrid>
        </YearPickerRoot>
      </div>

      <div v-if="withTime" ref="timeCols" class="flex gap-1 ml-3 pl-3 border-l border-neutral-200">
        <div v-for="col in ([['hour', hours], ['minute', minutes]] as const)" :key="col[0]" class="flex flex-col">
          <span class="h-8 mb-2 inline-flex items-center justify-center text-xs text-neutral-500">{{ t(`date_picker.${col[0]}_short`) }}</span>
          <div role="group" :aria-label="t(`date_picker.${col[0]}s`)" class="flex flex-col gap-0.5 h-64 overflow-y-auto pr-0.5 [scrollbar-width:thin]"
            @keydown="onTimeKey">
            <button v-for="n in col[1]" :key="n" type="button" :class="timeClass"
              :aria-pressed="timeOf?.[col[0]] === n" :tabindex="(timeOf ? timeOf[col[0]] === n : n === 0) ? 0 : -1"
              @click="pickTime(col[0], n)">{{ pad(n) }}</button>
          </div>
        </div>
      </div>
      </div>

      <div class="flex items-center justify-between mt-2 pt-2 border-t border-neutral-200">
        <button type="button" :disabled="todayDisabled" :class="footerClass" @click="pickToday">
          <Icon name="calendar" class="w-4 h-4" />{{ t('date_picker.today') }}
        </button>
        <button v-if="withTime" type="button" :class="footerClass" @click="open = false">{{ t('date_picker.done') }}</button>
      </div>
    </DatePickerContent>
  </DatePickerRoot>
</template>
