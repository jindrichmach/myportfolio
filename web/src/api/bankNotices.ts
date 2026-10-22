import { api } from './client'

export type ImapEncryption = 'ssl' | 'tls' | 'none'

export interface ImapAccount {
  id: number
  name: string
  enabled: boolean
  host: string
  port: number
  encryption: ImapEncryption
  validate_cert: boolean
  username: string
  folder: string
  max_messages_per_run: number
  require_email_auth: boolean
  auth_serv_id: string | null
  last_scan_at: string | null
  last_scan_status: 'ok' | 'error' | null
  last_scan_message: string | null
}

/** Heslo jen při zápisu; prázdné při úpravě = beze změny. */
export type ImapAccountInput = Omit<ImapAccount, 'id' | 'last_scan_at' | 'last_scan_status' | 'last_scan_message'> & { password: string }

export type NoticeStatus = 'imported' | 'skipped_old' | 'unmapped' | 'parse_failed' | 'security_rejected' | 'import_failed'

export interface BankNotice {
  id: number
  imap_account_name: string
  message_date: string | null
  sender: string | null
  subject: string | null
  parser: string | null
  status: NoticeStatus
  position_id: number | null
  position_name: string | null
  transaction_id: number | null
  /** Částky jsou desetinné stringy; amount se znaménkem (záporná = odchozí). */
  payload: { amount?: string; currency?: string; postedAt?: string; balance?: string | null; account?: string } | null
  error: string | null
  created_at: string
}

export interface ScanSummary {
  fetched?: number
  imported?: number
  known?: number
  ignored?: number
  failed?: number
  error?: string
}

export const bankNoticesApi = {
  accounts: () => api.get<ImapAccount[]>('/settings/imap-accounts').then(r => r.data),
  create: (p: ImapAccountInput) => api.post<ImapAccount>('/settings/imap-accounts', p).then(r => r.data),
  update: (id: number, p: ImapAccountInput) => api.put<ImapAccount>(`/settings/imap-accounts/${id}`, p).then(r => r.data),
  remove: (id: number) => api.delete(`/settings/imap-accounts/${id}`),
  test: (id: number) => api.post<{ ok: boolean; message: string; folders?: string[] }>(`/settings/imap-accounts/${id}/test`).then(r => r.data),
  scan: (id: number) => api.post<ScanSummary>(`/settings/imap-accounts/${id}/scan`, {}, { timeout: 120_000 }).then(r => r.data),
  notices: () => api.get<BankNotice[]>('/bank-notices').then(r => r.data),
}
