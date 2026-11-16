import { api } from './client'

export interface DataIssue {
  kind: 'missing_price' | 'stale_price' | 'stale_fx' | 'imap_error' | `notice_${string}`
  /** Symbol instrumentu, měny (kurzy), parser avíza nebo název schránky */
  symbol: string
  name: string | null
  date: string | null
}

export interface CostIssue {
  code: 'reward_without_value' | 'transfer_without_pair' | 'fee_not_converted' | 'insufficient_quantity' | 'fee_exceeds_quantity'
  transaction_id: number
  type: string
  executed_at: string
  position_id: number
  symbol: string
  instrument_name: string | null
}

export interface DataQuality {
  prices: DataIssue[]
  fx: DataIssue[]
  costs: CostIssue[]
  notices: DataIssue[]
  total: number
}

export const dataQualityApi = {
  get: () => api.get<DataQuality>('/data-quality').then(r => r.data),
}
