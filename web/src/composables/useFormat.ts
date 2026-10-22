/**
 * Pomocné formátovací funkce pro peníze, datumy a procentua.
 */

import { i18n } from '@/i18n'

// Per-currency default decimals (ISO 4217). JPY/KRW/HUF = 0, BHD/JOD = 3, ostatní 2.
// Volající může override přes parametr `decimals`.
const CURRENCY_DECIMALS: Record<string, number> = {
  JPY: 0, KRW: 0, HUF: 0, ISK: 0, CLP: 0, VND: 0,
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
}

function defaultDecimals(currency: string): number {
  return CURRENCY_DECIMALS[(currency || '').toUpperCase()] ?? 2
}

/** Intl vrací pro záporná čísla spojovník (U+002D); v UI chceme typografické minus. */
function typographicMinus(text: string): string {
  return text.replace(/^-/, '\u2212')
}

function activeLocale(): string {
  return i18n.global.locale.value === 'en' ? 'en-US' : 'cs-CZ'
}

export function formatMoney(value: number | null | undefined, currency: string = 'CZK', decimals?: number): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  const dec = decimals ?? defaultDecimals(currency)
  const formatter = new Intl.NumberFormat(activeLocale(), {
    style: 'decimal',
    minimumFractionDigits: dec,
    maximumFractionDigits: dec,
  })
  const symbol = currency === 'CZK' ? 'Kč' : currency === 'EUR' ? '€' : currency
  return `${typographicMinus(formatter.format(value))} ${symbol}`
}

export function formatDate(date: string | null | undefined): string {
  if (!date) return '—'
  const d = new Date(date)
  if (Number.isNaN(d.getTime())) return date
  return new Intl.DateTimeFormat(activeLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d)
}

export function formatMonth(yyyymm: string): string {
  const [y, m] = yyyymm.split('-').map(Number)
  if (!y || !m) return yyyymm
  const monthsCs = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']
  const monthsEn = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
  const months = i18n.global.locale.value === 'en' ? monthsEn : monthsCs
  return `${months[m - 1]} ${y}`
}

/** Procenta z API stringu (nebo čísla jen pro zobrazení); `signed` přidá „+" u kladných. */
export function formatPercent(value: string | number | null | undefined, maxFractionDigits = 2, signed = false): string {
  if (value === null || value === undefined || value === '') return '—'
  const text = formatDec(String(value), maxFractionDigits)
  return `${signed && Number(value) > 0 ? '+' : ''}${text} %`
}

/** Částka se znaménkem: „+1 234 Kč" / „−1 234 Kč" / „0 Kč". */
export function formatSigned(value: string | number | null | undefined, currency: string = 'CZK', decimals?: number): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  return `${n > 0 ? '+' : ''}${formatMoney(n, currency, decimals)}`
}

/** Zkrácená částka pro osy grafů na úzkém displeji: „2,5 mil. Kč" / „$2.5M". */
export function formatMoneyCompact(value: number, currency: string = 'CZK'): string {
  const formatted = typographicMinus(new Intl.NumberFormat(activeLocale(), { notation: 'compact', maximumFractionDigits: 1 }).format(value))
  return `${formatted} ${currency === 'CZK' ? 'Kč' : currency === 'EUR' ? '€' : currency}`
}

/**
 * Formát desetinného stringu z API (peníze, množství) pro zobrazení.
 * Number jen pro zobrazení — výpočty dělá backend (BigDecimal).
 */
export function formatDec(value: string | null | undefined, maxFractionDigits = 8, currency?: string | null): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return value
  const formatted = typographicMinus(new Intl.NumberFormat(activeLocale(), {
    minimumFractionDigits: 0,
    maximumFractionDigits: maxFractionDigits,
  }).format(n))
  return currency ? `${formatted} ${currency === 'CZK' ? 'Kč' : currency}` : formatted
}

/** 'YYYY-MM-DD HH:MM:SS' → lokalizované datum + čas (bez sekund, půlnoc bez času). */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const [date, time = ''] = value.split(/[ T]/)
  const d = formatDate(date)
  return time && !time.startsWith('00:00') ? `${d} ${time.slice(0, 5)}` : d
}
