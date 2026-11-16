import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import { formatDateTime, formatDec, formatMoney, formatPercent, formatPrice, formatQty, formatSigned, qtyDecimals } from './useFormat'

/** Intl odděluje tisíce nezlomitelnou mezerou — pro porovnání stačí obyčejná */
const plain = (s: string) => s.replace(/[  ]/g, ' ')
const setLocale = (l: 'cs' | 'en') => { i18n.global.locale.value = l }

describe('useFormat', () => {
  afterEach(() => setLocale('cs'))

  it('peníze: desetinná místa podle měny, typografické minus, symbol', () => {
    expect(plain(formatMoney(1234.5))).toBe('1 234,50 Kč')
    expect(plain(formatMoney(-1234.5, 'EUR'))).toBe('−1 234,50 €')
    expect(plain(formatMoney(1234.4, 'JPY'))).toBe('1 234 JPY')
    expect(formatMoney(null)).toBe('—')
    setLocale('en')
    expect(formatMoney(1234.5, 'USD')).toBe('1,234.50 USD')
  })

  it('částka se znaménkem a procenta', () => {
    expect(plain(formatSigned('1500'))).toBe('+1 500,00 Kč')
    expect(plain(formatSigned('-2'))).toBe('−2,00 Kč')
    expect(formatSigned('0')).toBe('0,00 Kč')
    expect(formatPercent('12.345')).toBe('12,35 %')
    expect(formatPercent('3', 2, true)).toBe('+3 %')
    expect(formatPercent('-3', 2, true)).toBe('−3 %')
    expect(formatPercent(null)).toBe('—')
  })

  it('desetinné stringy z API: koncové nuly pryč, nečíselný vstup beze změny', () => {
    expect(formatDec('0.500000000000000000')).toBe('0,5')
    expect(formatDec('1.123456789', 8)).toBe('1,12345679')
    expect(plain(formatDec('1000', 2, 'CZK'))).toBe('1 000 Kč')
    expect(formatDec('n/a')).toBe('n/a')
  })

  it('množství podle třídy aktiva a desetinných míst instrumentu', () => {
    expect(qtyDecimals('crypto')).toBe(8)
    expect(qtyDecimals('etf', 2)).toBe(2)
    expect(formatQty('0.123456789', 'crypto')).toBe('0,12345679')
    expect(formatQty('10.12345', 'stock')).toBe('10,1235')
  })

  it('jednotková cena: pod 1 čtyři platné číslice', () => {
    expect(formatPrice('0.000012345')).toBe('0,00001235')
    expect(plain(formatPrice('104250.129'))).toBe('104 250,13')
  })

  it('datum a čas: půlnoc bez času', () => {
    expect(plain(formatDateTime('2026-11-01 14:30:00'))).toBe('01. 11. 2026 14:30')
    expect(plain(formatDateTime('2026-11-01 00:00:00'))).toBe('01. 11. 2026')
    expect(formatDateTime(null)).toBe('—')
  })
})
