import { api } from './client'

/** Částky jsou desetinné stringy v měně zobrazení; gain_pct v % (Modified Dietz) */
export interface PeriodRow {
  period: string
  partial: boolean
  value_from: string
  value_to: string
  flow: string
  /** null = neúplné období (chybí cena / kurz) — výnos se nepočítá */
  change: string | null
  gain: string | null
  gain_pct: string | null
  /** Inflace ČR za období (u probíhajícího do posledního zveřejněného měsíce `inflation_to`); null = index není */
  inflation_pct: string | null
  inflation_to: string | null
  /** Reálný výnos % = (1 + výnos) / (1 + inflace) − 1 */
  real_pct: string | null
  incomplete: boolean
  dividends: string
  interest: string
  fees: string
  withheld: string
}

export interface PeriodSummary {
  currency: string
  months: PeriodRow[]
  years: PeriodRow[]
  missing_fx: string[]
}

export interface IncomeSums { dividends: string; interest: string; withheld: string; net: string }

export interface IncomeOverview {
  currency: string
  year: number | null
  years: (IncomeSums & { year: number })[]
  total: IncomeSums
  months: (IncomeSums & { month: string })[]
  positions: (IncomeSums & { position: string; symbol: string | null; account: string; count: number })[]
  payments: {
    date: string; type: 'dividend' | 'interest'; position: string; symbol: string | null; account: string
    amount: string; amount_currency: string; gross: string; withheld: string; net: string
  }[]
  missing_fx: string[]
}

export const summaryApi = {
  summary: (p: { portfolio_id?: number; account_id?: number } = {}) => api.get<PeriodSummary>('/summary', { params: p }).then(r => r.data),
  income: (year: number | null) => api.get<IncomeOverview>('/income', { params: year === null ? {} : { year } }).then(r => r.data),
}
