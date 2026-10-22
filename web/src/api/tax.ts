import { api } from './client'

export type RateMethod = 'daily' | 'unified'
export type Exemption = 'limit' | 'time_test' | 'time_test_capped' | 'before_crypto_exemption' | 'none'

/** Částky jsou desetinné stringy v CZK (2 desetinná místa) */
export interface TaxLotRow {
  transaction_id: number
  disposed_at: string
  acquired_at: string | null
  symbol: string
  name: string
  isin: string | null
  account: string
  currency: string
  quantity: string
  income: string
  expense: string
  gain: string
  exemption: Exemption
  /** Kusy spotřebované na poplatek prodeje (příjem 0, nabývací cena = výdaj) */
  fee_lot: boolean
  /** Poplatek v jiném kryptu (BNB…) — kurz ČNB ho neocení, ve výdajích není */
  fee_skipped: string | null
}

export interface TaxSection {
  eligible_income: string
  under_limit: boolean
  exemption_year: boolean
  income: string
  expense: string
  gain: string
  exempt_income: string
  taxable_income: string
  taxable_expense: string
  result: string
  tax_base: string
  rows: TaxLotRow[]
}

export interface DividendRow {
  date: string; symbol: string | null; name: string | null; isin: string | null; account: string
  amount: string; currency: string; gross_czk: string; withheld_czk: string
}

export interface TaxReport {
  year: number
  rate: RateMethod
  cap_exceeded: boolean
  securities: TaxSection
  crypto: TaxSection
  dividends: {
    countries: { country: string | null; gross: string; withheld: string; creditable: string; cz_tax: string; to_pay: string; rows: DividendRow[] }[]
    total: { gross: string; withheld: string; creditable: string; cz_tax: string; to_pay: string }
    domestic: { gross: string; withheld: string; rows: DividendRow[] }
  }
  interest: { positions: { name: string; account: string; count: number; total: string; withheld: string }[]; total: string }
  unified_rates?: { currency: string; year: number; rate: string }[]
}

export type DividendMethod = '16a' | '38'
export type FilingWarning = 'unverified_year' | 'crypto_code' | 'dividend_country_unknown' | 'interest' | 'cap_exceeded'

/** Podklad k přepisu do přiznání — částky v celých Kč jako stringy */
export interface FilingGuide {
  year: number
  rate: RateMethod
  verified: boolean
  forms: string
  attachment2: {
    rows: { code: 'D' | 'C'; class: 'securities' | 'crypto'; income: string; expense: string; difference: string }[]
    r207: string
    r208: string
    r209: string
    main_r40: string
  }
  dividends:
    | { method: '16a'; r401a: string; r406: string; r409: string; r410: string; r411: string; r412: string; r413: string; r414: string; main_r74a: string }
    | { method: '38'; main_r38: string; countries: { country: string | null; r321: string; r323: string }[] }
  interest_total: string
  domestic_dividends: string
  exempt: { securities: string; crypto: string }
  warnings: FilingWarning[]
}

export const taxApi = {
  filing: (year: number, rate: RateMethod, dividends: DividendMethod) =>
    api.get<FilingGuide>('/tax-report/filing', { params: { year, rate, dividends } }).then(r => r.data),
  filingCsvUrl: (year: number, rate: RateMethod, dividends: DividendMethod) =>
    `/api/tax-report/filing.csv?year=${year}&rate=${rate}&dividends=${dividends}`,
  years: () => api.get<number[]>('/tax-report/years').then(r => r.data),
  report: (year: number, rate: RateMethod) => api.get<TaxReport>('/tax-report', { params: { year, rate } }).then(r => r.data),
  csvUrl: (year: number, rate: RateMethod) => `/api/tax-report/export.csv?year=${year}&rate=${rate}`,
}
