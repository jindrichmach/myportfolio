import { api } from './client'
import type { AssetClass, PriceSource } from './catalog'

export interface LastPrice {
  date: string
  close: string
  currency: string
  source: PriceSource
  stale: boolean
}

export interface PricePoint {
  date: string
  close: string
  currency: string
  source: PriceSource
}

export interface FxRate {
  currency: string
  amount: number
  rate: string
}

export interface MarketStatusRow {
  id: number
  symbol: string
  name: string
  asset_class: AssetClass
  price_source: PriceSource
  chain: PriceSource[]
  last_price: LastPrice | null
  history_from: string | null
  history_days: number
}

export const marketApi = {
  prices: (instrumentId: number, from?: string, to?: string) =>
    api.get<{ instrument_id: number; last_price: LastPrice | null; data: PricePoint[] }>('/prices', {
      params: { instrument_id: instrumentId, from, to },
    }).then(r => r.data),
  setManualPrice: (p: { instrument_id: number; date: string; close: string; currency?: string }) =>
    api.post<{ instrument_id: number; last_price: LastPrice }>('/prices', p).then(r => r.data),
  fxRates: (date?: string) =>
    api.get<{ requested_date: string; date: string | null; rates: FxRate[] }>('/fx-rates', { params: { date } }).then(r => r.data),
  status: () =>
    api.get<{ instruments: MarketStatusRow[]; fx: { date: string | null; rates: FxRate[] }; cpi: { latest_month: string | null } }>('/admin/market-status').then(r => r.data),
}
