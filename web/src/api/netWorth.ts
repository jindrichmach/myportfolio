import { api } from './client'
import type { HistoryRange } from './history'

export type NetWorthKind = 'real_estate' | 'other' | 'liability'
export const NET_WORTH_KINDS: NetWorthKind[] = ['real_estate', 'other', 'liability']

/** Částky jsou desetinné stringy; value v měně položky, value_display v měně zobrazení. */
export interface NetWorthItem {
  id: number
  kind: NetWorthKind
  name: string
  currency: string
  note: string | null
  archived_at: string | null
  value: string | null
  value_display: string | null
  last_date: string | null
  entries: number
  rate_pct?: string | null
  payment?: string | null
  payoff?: { date: string; payments_left: number } | null
}

export interface NetWorthOverview {
  currency: string
  as_of: string
  investments: string
  investments_incomplete: number
  assets: string
  liabilities: string
  net_worth: string
  items: NetWorthItem[]
  warnings: { item_id: number; code: string }[]
}

export interface NetWorthPoint { date: string; investments: string | null; assets: string; liabilities: string; net_worth: string | null; incomplete: boolean }

export interface NetWorthEntry { id: number; date: string; value: string; rate_pct: string | null; payment: string | null; note: string | null }

export interface NetWorthItemInput {
  kind?: NetWorthKind
  name: string
  currency: string
  note: string | null
  archived?: boolean
  /** Při založení: první ocenění / počáteční zůstatek úvěru */
  date?: string
  value?: string
  rate_pct?: string
  payment?: string
}

export const netWorthApi = {
  overview: (archived = false) => api.get<NetWorthOverview>('/net-worth', { params: archived ? { archived: 1 } : {} }).then(r => r.data),
  history: (range: HistoryRange) =>
    api.get<{ currency: string; points: NetWorthPoint[] }>('/net-worth/history', { params: { range } }).then(r => r.data),
  createItem: (p: NetWorthItemInput) => api.post<NetWorthItem>('/net-worth/items', p).then(r => r.data),
  updateItem: (id: number, p: NetWorthItemInput) => api.put<NetWorthItem>(`/net-worth/items/${id}`, p).then(r => r.data),
  removeItem: (id: number) => api.delete(`/net-worth/items/${id}`),
  entries: (id: number) =>
    api.get<{ item: NetWorthItem; entries: NetWorthEntry[]; schedule?: { date: string; balance: string }[] }>(`/net-worth/items/${id}/entries`).then(r => r.data),
  saveEntry: (id: number, p: { date: string; value: string; rate_pct?: string; payment?: string; note?: string | null }) =>
    api.post<{ entries: NetWorthEntry[] }>(`/net-worth/items/${id}/entries`, p).then(r => r.data),
  removeEntry: (id: number) => api.delete(`/net-worth/entries/${id}`),
}
