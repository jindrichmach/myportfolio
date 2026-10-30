import { api } from './client'
import type { AssetClass } from './catalog'
import type { LastPrice } from './market'
import type { Dec, ValuationMode } from './portfolio'

/** Kategorie alokace: třída aktiva, u ručních pozic banka → cash, jinak fund. */
export type Category = AssetClass

/** Ocenění pozice v měně zobrazení (`currency`). Null = chybí cena nebo kurz (viz `warnings`). */
export interface PositionValuation {
  position_id: number
  display_name: string
  instrument_id: number | null
  instrument_name: string | null
  asset_class: AssetClass | null
  category: Category
  valuation_mode: ValuationMode
  is_cash: boolean
  portfolio_id: number
  portfolio_name: string
  account_id: number
  account_name: string
  institution_name: string
  institution_icon_url: string | null
  instrument_icon_url: string | null
  archived: boolean
  currency: string
  quantity: Dec | null
  price: LastPrice | null
  /** Poslední cena dnešním kurzem v měně zobrazení. */
  price_display: Dec | null
  value: Dec | null
  invested: Dec
  cost_basis: Dec
  avg_cost: Dec | null
  unrealized: Dec | null
  realized: Dec
  income: Dec
  fees: Dec
  total_return: Dec | null
  return_pct: Dec | null
  valuation: ManualValuation | null
  warnings: string[]
}

export interface Lot {
  id: number
  position_id: number
  source_transaction_id: number
  source_type: string | null
  origin_lot_id: number | null
  acquired_at: string
  quantity: Dec
  remaining_quantity: Dec
  cost_amount: Dec
  cost_currency: string
  remaining_cost: Dec
  remaining_cost_display: Dec | null
}

export interface Disposal {
  id: number
  lot_id: number
  transaction_id: number
  kind: 'sell' | 'fee'
  disposed_at: string
  acquired_at: string
  quantity: Dec
  cost_amount: Dec
  cost_currency: string
  proceeds_amount: Dec
  proceeds_currency: string
  holding_days: number
  cost_display: Dec | null
  proceeds_display: Dec | null
  gain_display: Dec | null
}

export interface PositionValuationDetail extends PositionValuation {
  lots: Lot[]
  disposals: Disposal[]
}

export interface ManualValuation {
  id: number
  position_id: number
  date: string
  value: Dec
  currency: string
  note: string | null
}

export interface AllocationSlice { label: string; value: Dec }

export interface CryptoAcross {
  instrument_id: number
  symbol: string
  name: string
  icon_url: string | null
  quantity: Dec
  value: Dec | null
  invested: Dec
  cost_basis: Dec
  /** Celkový výnos vč. prodejů, příjmů a poplatků — stejný pojem jako u pozic. */
  total_return: Dec | null
  return_pct: Dec | null
  accounts: { position_id: number; account: string; quantity: Dec; value: Dec | null }[]
}

export interface Dashboard {
  currency: string
  as_of: string
  totals: {
    value: Dec
    invested: Dec
    unrealized: Dec
    realized: Dec
    income: Dec
    fees: Dec
    total_return: Dec
    return_pct: Dec | null
    incomplete: number
  }
  allocation: { category: AllocationSlice[]; portfolio: AllocationSlice[]; institution: AllocationSlice[] }
  positions: PositionValuation[]
  crypto: CryptoAcross[]
  warnings: { position_id: number; display_name: string; code: string }[]
}

export const valuationApi = {
  dashboard: () => api.get<Dashboard>('/dashboard').then(r => r.data),
  position: (id: number) => api.get<PositionValuationDetail>(`/positions/${id}/valuation`).then(r => r.data),
  valuations: (positionId: number) => api.get<ManualValuation[]>(`/positions/${positionId}/valuations`).then(r => r.data),
  saveValuation: (positionId: number, p: { date: string; value: Dec; currency?: string | null; note?: string | null }) =>
    api.post<ManualValuation[]>(`/positions/${positionId}/valuations`, p).then(r => r.data),
  deleteValuation: (id: number) => api.delete(`/valuations/${id}`),
}

/** Kód upozornění z API → i18n klíč + parametry (`fx_missing:USD`). */
export function warningKey(code: string): { key: string; params: Record<string, string> } {
  const [name, arg = ''] = code.split(':')
  return { key: `valuation.warnings.${name}`, params: { arg } }
}
