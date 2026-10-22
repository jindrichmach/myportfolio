import { api } from './client'

export interface PriceAlert {
  id: number
  instrument_id: number
  symbol: string
  instrument_name: string
  quote_currency: string
  direction: 'above' | 'below'
  /** Desetinné stringy */
  price: string
  currency: string
  note: string | null
  triggered_at: string | null
  triggered_price: string | null
}

export interface AlertSettings {
  enabled: boolean
  recipients: string[]
  /** Prahy v % jako desetinné stringy; null = vypnuto */
  move_portfolio_pct: string | null
  move_position_pct: string | null
  holding_days: number | null
  data_issues: boolean
  allocation: boolean
  sent_at: string | null
  email: string
  price_alerts: PriceAlert[]
}

export type AlertSettingsInput = Omit<AlertSettings, 'sent_at' | 'email' | 'price_alerts'>
export type PriceAlertInput = { instrument_id: number; direction: 'above' | 'below'; price: string; currency?: string; note?: string | null }

export const alertsApi = {
  get: () => api.get<AlertSettings>('/settings/alerts').then(r => r.data),
  update: (p: AlertSettingsInput) => api.put<AlertSettings>('/settings/alerts', p).then(r => r.data),
  send: () => api.post<{ sent: boolean; recipients: string[]; counts: Record<string, number> }>('/settings/alerts/send', {}, { timeout: 120_000 }).then(r => r.data),
  createPrice: (p: PriceAlertInput) => api.post<PriceAlert>('/price-alerts', p).then(r => r.data),
  updatePrice: (id: number, p: PriceAlertInput) => api.put<PriceAlert>(`/price-alerts/${id}`, p).then(r => r.data),
  removePrice: (id: number) => api.delete(`/price-alerts/${id}`),
}
