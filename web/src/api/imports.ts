import { api } from './client'
import type { AssetClass } from './catalog'

export type DraftKind = 'buy' | 'sell' | 'reward' | 'dividend' | 'interest' | 'fee' | 'send' | 'receive' | 'split' | 'skip'
export type RowStatus = 'new' | 'duplicate' | 'skip'

export interface ImportSource { key: string; label: string }

export interface ImportAsset {
  key: string
  symbol: string
  isin: string | null
  name: string | null
  asset_class: 'crypto' | 'security'
  currency: string | null
  rows: number
  new_rows: number
  instrument_id: number | null
  candidates: { id: number; symbol: string; name: string; asset_class: AssetClass; quote_currency: string; isin: string | null }[]
  /** Pozice stejného instrumentu na jiných účtech (cíl převodu) */
  positions: { id: number; label: string }[]
}

export interface ImportPreview {
  token: string
  source: ImportSource
  filename: string
  account: { id: number; name: string }
  portfolio: { id: number; name: string }
  accounts: { id: number; name: string }[]
  summary: { rows: number; new: number; duplicate: number; skipped: number; transfers: number }
  assets: ImportAsset[]
  transfers: { index: number; kind: 'send' | 'receive'; executed_at: string; symbol: string; quantity: string | null; asset_key: string; note: string | null }[]
  /** Částky jsou desetinné stringy */
  rows: {
    index: number; line: number; kind: DraftKind; executed_at: string; symbol: string; quantity: string | null; unit_price: string | null
    amount: string | null; currency: string | null; fee: string | null; fee_currency: string | null; status: RowStatus; reason: string | null; note: string | null
  }[]
}

export type AssetDecision =
  | { instrument_id: number }
  | { create: { symbol: string; name: string; asset_class: AssetClass; quote_currency: string; isin?: string | null } }
  | { skip: true }

export type TransferDecision = { position_id: number } | { account_id: number } | null

export interface ImportBatch {
  id: number
  source: string
  filename: string | null
  rows_total: number
  rows_imported: number
  rows_skipped: number
  transactions: number
  reverted_at: string | null
  created_at: string
}

export const importsApi = {
  sources: () => api.get<ImportSource[]>('/imports/sources').then(r => r.data),
  preview: (file: File, accountId: number, portfolioId: number, source: string) => {
    const form = new FormData()
    form.append('file', file)
    form.append('account_id', String(accountId))
    form.append('portfolio_id', String(portfolioId))
    if (source) form.append('source', source)
    return api.post<ImportPreview>('/imports/preview', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120_000 })
      .then(r => r.data)
  },
  get: (token: string) => api.get<ImportPreview>(`/imports/preview/${token}`).then(r => r.data),
  confirm: (token: string, assets: Record<string, AssetDecision>, transfers: Record<number, TransferDecision>) =>
    api.post<{ batch_id: number | null; imported: number; transfers: number; skipped: number }>(
      `/imports/preview/${token}/confirm`, { assets, transfers }, { timeout: 120_000 }).then(r => r.data),
  batches: () => api.get<ImportBatch[]>('/imports').then(r => r.data),
  revert: (id: number) => api.post<{ deleted: number }>(`/imports/${id}/revert`).then(r => r.data),
}
