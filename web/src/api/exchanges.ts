import { api } from './client'

export interface ExchangeField { key: string; label: string; secret: boolean; multiline?: boolean }
export interface ExchangeProvider { provider: string; label: string; fields: ExchangeField[] }

export type ExchangeStatus = 'ok' | 'pending' | 'error'

export interface ExchangeConnection {
  id: number
  provider: string
  account_id: number
  portfolio_id: number
  account_name: string
  institution_name: string
  portfolio_name: string
  key_hint: string | null
  enabled: boolean
  sync_from: string | null
  synced_until: string | null
  pending_token: string | null
  last_sync_at: string | null
  last_status: ExchangeStatus | null
  last_message: string | null
}

export type ExchangeStart = 'continue' | 'date' | 'all'

export interface ExchangeInput {
  provider?: string
  account_id: number
  portfolio_id: number
  enabled: boolean
  /** Při úpravě prázdné = beze změny klíče */
  credentials: Record<string, string>
  start?: ExchangeStart
  start_date?: string
}

export interface ExchangeTest { ok: boolean; message: string; warnings: string[] }
export interface ExchangeSaved { connection: ExchangeConnection; warnings: string[] }
export interface ExchangeSyncResult { status: ExchangeStatus; message: string; imported: number; pending_token: string | null; connection: ExchangeConnection }

export const exchangesApi = {
  list: () => api.get<{ providers: ExchangeProvider[]; connections: ExchangeConnection[] }>('/settings/exchanges').then(r => r.data),
  test: (provider: string, credentials: Record<string, string>) =>
    api.post<ExchangeTest>('/settings/exchanges/test', { provider, credentials }, { timeout: 60_000 }).then(r => r.data),
  create: (p: ExchangeInput) => api.post<ExchangeSaved>('/settings/exchanges', p, { timeout: 60_000 }).then(r => r.data),
  update: (id: number, p: ExchangeInput) => api.put<ExchangeSaved>(`/settings/exchanges/${id}`, p, { timeout: 60_000 }).then(r => r.data),
  remove: (id: number) => api.delete(`/settings/exchanges/${id}`),
  sync: (id: number) => api.post<ExchangeSyncResult>(`/settings/exchanges/${id}/sync`, {}, { timeout: 600_000 }).then(r => r.data),
}
