import { api } from './client'
import type { AssetClass, InstitutionType } from './catalog'
import type { LastPrice } from './market'

/** Peníze a množství jsou v API vždy stringy (desetinná tečka) — nikdy float. */
export type Dec = string

export interface Account {
  id: number
  institution_id: number
  institution_name: string
  institution_type: InstitutionType
  institution_icon_url: string | null
  name: string
  currency: string
  note: string | null
  archived_at: string | null
}

export interface Portfolio {
  id: number
  name: string
  sort: number
  archived_at: string | null
  position_count: number
}

export type ValuationMode = 'market' | 'manual'

export interface Position {
  id: number
  portfolio_id: number
  portfolio_name: string
  account_id: number
  account_name: string
  institution_name: string
  institution_icon_url: string | null
  instrument_icon_url: string | null
  instrument_id: number | null
  instrument_symbol: string | null
  instrument_name: string | null
  asset_class: AssetClass | null
  quote_currency: string | null
  instrument_decimals: number | null
  valuation_mode: ValuationMode
  /** Hotovost na účtu — zůstatek se nastavuje (rozdíl = vklad / výběr) */
  is_cash: boolean
  name: string | null
  display_name: string
  currency: string
  interest_rate_pa: Dec | null
  bank_account: string | null
  bank_notices_from: string | null
  archived_at: string | null
  holding: Dec
  last_price: LastPrice | null
  /** Jen ruční pozice: dnešní hodnota v měně pozice (null hodnota = chybí kurz). */
  current_value?: { value: Dec | null; valuation_date: string | null } | null
}

export type TransactionType =
  | 'buy' | 'sell' | 'deposit' | 'withdrawal' | 'transfer_in' | 'transfer_out'
  | 'reward' | 'dividend' | 'interest' | 'fee' | 'split'

export const MARKET_TYPES: TransactionType[] = ['buy', 'sell', 'reward', 'split', 'dividend', 'interest', 'fee']
export const MANUAL_TYPES: TransactionType[] = ['deposit', 'withdrawal', 'dividend', 'interest', 'fee']
export const ALL_TYPES: TransactionType[] = [
  'buy', 'sell', 'reward', 'transfer_in', 'transfer_out', 'deposit', 'withdrawal', 'dividend', 'interest', 'fee', 'split',
]

export interface Transaction {
  id: number
  position_id: number
  type: TransactionType
  executed_at: string
  quantity: Dec | null
  unit_price: Dec | null
  price_currency: string | null
  amount: Dec | null
  fee: Dec | null
  fee_currency: string | null
  split_ratio: Dec | null
  transfer_group_id: string | null
  note: string | null
  source: string
  /** Kdo transakci zapsal, když to nebyl vlastník (partner se sdílením) */
  created_by_name?: string | null
  portfolio_id: number
  portfolio_name: string
  account_id: number
  account_name: string
  institution_name: string
  valuation_mode: ValuationMode
  position_name: string
  asset_class: AssetClass | null
  institution_icon_url: string | null
  instrument_icon_url: string | null
}

export interface TransactionInput {
  position_id?: number
  type: TransactionType
  executed_at: string
  quantity?: Dec | null
  unit_price?: Dec | null
  price_currency?: string | null
  amount?: Dec | null
  fee?: Dec | null
  fee_currency?: string | null
  split_ratio?: Dec | null
  note?: string | null
}

export interface TransferInput {
  from_position_id: number
  to_position_id: number
  quantity: Dec
  executed_at: string
  fee?: Dec | null
  fee_currency?: string | null
  note?: string | null
}

export interface Paginated<T> {
  data: T[]
  meta: { total: number; page: number; per_page: number; pages: number }
}

export interface TransactionFilter {
  position_id?: number
  portfolio_id?: number
  account_id?: number
  type?: TransactionType
  from?: string
  to?: string
  page?: number
  per_page?: number
  sort?: string
}

export const portfolioApi = {
  accounts: (archived = false) =>
    api.get<Account[]>('/accounts', { params: archived ? { archived: 1 } : {} }).then(r => r.data),
  createAccount: (p: { institution_id: number; name: string; currency: string; note?: string | null }) =>
    api.post<Account>('/accounts', p).then(r => r.data),
  updateAccount: (id: number, p: { institution_id: number; name: string; currency: string; note?: string | null; archived?: boolean }) =>
    api.put<Account>(`/accounts/${id}`, p).then(r => r.data),
  deleteAccount: (id: number) => api.delete(`/accounts/${id}`),

  portfolios: (archived = false) =>
    api.get<Portfolio[]>('/portfolios', { params: archived ? { archived: 1 } : {} }).then(r => r.data),
  createPortfolio: (p: { name: string; sort?: number }) => api.post<Portfolio>('/portfolios', p).then(r => r.data),
  updatePortfolio: (id: number, p: { name: string; sort?: number; archived?: boolean }) =>
    api.put<Portfolio>(`/portfolios/${id}`, p).then(r => r.data),
  deletePortfolio: (id: number) => api.delete(`/portfolios/${id}`),

  positions: (params: { portfolio_id?: number; account_id?: number; archived?: boolean } = {}) =>
    api.get<Position[]>('/positions', {
      params: { portfolio_id: params.portfolio_id, account_id: params.account_id, archived: params.archived ? 1 : undefined },
    }).then(r => r.data),
  position: (id: number) => api.get<Position>(`/positions/${id}`).then(r => r.data),
  createPosition: (p: {
    valuation_mode: ValuationMode; portfolio_id: number; account_id: number
    instrument_id?: number | null; name?: string | null; currency?: string | null; interest_rate_pa?: Dec | null; is_cash?: boolean
  }) => api.post<Position>('/positions', p).then(r => r.data),
  setCashBalance: (id: number, p: { date: string; balance: Dec; note?: string | null }) =>
    api.post<{ balance: Dec; previous: Dec; delta: Dec; transaction_id: number | null; position: Position }>(`/positions/${id}/cash-balance`, p).then(r => r.data),
  updatePosition: (id: number, p: {
    portfolio_id: number; account_id: number; name?: string | null; interest_rate_pa?: Dec | null; archived?: boolean
    bank_account?: string; bank_notices_from?: string | null
  }) => api.put<Position>(`/positions/${id}`, p).then(r => r.data),
  deletePosition: (id: number) => api.delete(`/positions/${id}`),

  transactions: (filter: TransactionFilter = {}) =>
    api.get<Paginated<Transaction>>('/transactions', { params: filter }).then(r => r.data),
  transaction: (id: number) => api.get<Transaction>(`/transactions/${id}`).then(r => r.data),
  createTransaction: (p: TransactionInput & { position_id: number }) =>
    api.post<Transaction>('/transactions', p).then(r => r.data),
  updateTransaction: (id: number, p: TransactionInput) =>
    api.put<Transaction>(`/transactions/${id}`, p).then(r => r.data),
  deleteTransaction: (id: number) => api.delete(`/transactions/${id}`),
  transfer: (p: TransferInput) =>
    api.post<{ transfer_group_id: string; transactions: Transaction[] }>('/transfers', p).then(r => r.data),
}
