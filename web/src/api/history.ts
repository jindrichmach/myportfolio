import { api } from './client'
import type { Dec } from './portfolio'

export type HistoryScope = 'total' | 'portfolio' | 'position' | 'class'
export type HistoryRange = '1M' | '3M' | 'YTD' | '1Y' | 'ALL'
export const HISTORY_RANGES: HistoryRange[] = ['1M', '3M', 'YTD', '1Y', 'ALL']

export interface HistoryPoint {
  date: string
  value: Dec | null
  invested: Dec | null
  /** Některé pozice ten den nemají cenu nebo kurz — součet je neúplný. */
  incomplete: boolean
  stale: boolean
  /** Hodnota „kdyby stejné toky šly do benchmarku" (jen se zvoleným benchmarkem) */
  benchmark?: Dec | null
}

export interface HistoryBenchmark {
  instrument_id: number
  symbol: string
  name: string
  /** false = benchmark nemá pro období ceny */
  available: boolean
  end_value: Dec | null
  gain: Dec | null
  twr_pct: Dec | null
}

export interface History {
  currency: string
  scope: HistoryScope
  range: HistoryRange
  from: string
  to: string
  points: HistoryPoint[]
  metrics: {
    start_value: Dec | null
    end_value: Dec | null
    net_flow: Dec
    gain: Dec | null
    simple_pct: Dec | null
    twr_pct: Dec | null
    twr_annual_pct: Dec | null
    xirr_pct: Dec | null
    /** Inflace ČR (HICP) mezi měsíci; null = index ještě není */
    inflation_pct: Dec | null
    inflation_from: string | null
    inflation_to: string | null
    twr_real_pct: Dec | null
    twr_annual_real_pct: Dec | null
    xirr_real_pct: Dec | null
  }
  benchmark: HistoryBenchmark | null
}

export const historyApi = {
  get: (p: { scope: HistoryScope; id?: number | string; range: HistoryRange }) =>
    api.get<History>('/history', { params: p }).then(r => r.data),
  setBenchmark: (instrumentId: number | null) =>
    api.put<{ instrument_id: number | null; symbol: string | null; inserted: number; errors: string[] }>(
      '/settings/benchmark', { instrument_id: instrumentId }, { timeout: 120_000 }).then(r => r.data),
}
