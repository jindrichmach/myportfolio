import { api } from './client'

export type AllocationCategory = 'crypto' | 'etf' | 'stock' | 'fund' | 'cash'
export const ALLOCATION_CATEGORIES: AllocationCategory[] = ['crypto', 'etf', 'stock', 'fund', 'cash']

/** Částky a procenta jsou desetinné stringy (2 místa) v měně zobrazení. */
export interface AllocationItem {
  instrument_id: number | null
  label: string | null
  name: string | null
  value: string
  /** Podíl uvnitř třídy */
  pct: string
  target_pct: string | null
  diff_amount: string | null
}

export interface AllocationClass {
  category: AllocationCategory
  value: string
  pct: string
  target_pct: string | null
  band_pct: string | null
  /** Procentní body; + nad cílem */
  diff_pct: string | null
  diff_amount: string | null
  out_of_band: boolean
  instrument_targets: boolean
  items: AllocationItem[]
}

export interface AllocationLine {
  action: 'buy' | 'sell'
  category: AllocationCategory
  instrument_id: number | null
  label: string | null
  amount: string
  positions?: { position_id: number; label: string; amount: string; quantity: string | null; taxable_quantity: string | null }[]
}

export interface AllocationTarget { category: AllocationCategory; instrument_id: number | null; target_pct: string; band_pct: string | null }

export interface Allocation {
  currency: string
  portfolio_id: number | null
  has_targets: boolean
  total: string
  out_of_band: number
  classes: AllocationClass[]
  targets: AllocationTarget[]
  deposit: { amount: string; lines: AllocationLine[] } | null
  rebalance: AllocationLine[]
  incomplete: { position_id: number; display_name: string }[]
}

export interface AllocationTargetsInput {
  portfolio_id: number | null
  classes: { category: AllocationCategory; target_pct: string; band_pct: string | null; instruments?: { instrument_id: number; target_pct: string }[] }[]
}

export const allocationApi = {
  get: (portfolioId: number | null, deposit?: string) =>
    api.get<Allocation>('/allocation', { params: { portfolio_id: portfolioId ?? undefined, deposit: deposit || undefined } }).then(r => r.data),
  save: (p: AllocationTargetsInput) => api.put<Allocation>('/allocation/targets', p).then(r => r.data),
}
