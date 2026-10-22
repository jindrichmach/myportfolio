import { api } from './client'

export type SharePermission = 'read' | 'write'
export type ShareScope = 'all' | 'portfolios'
export type ShareSection = 'net_worth' | 'tax' | 'income'
export const SHARE_SECTIONS: ShareSection[] = ['net_worth', 'tax', 'income']

export interface Share {
  id: number
  owner_user_id: number
  grantee_user_id: number | null
  owner_name: string
  grantee_name: string | null
  invite_email?: string
  invite_expires_at?: string | null
  permission: SharePermission
  scope: ShareScope
  sections: ShareSection[]
  portfolios: { id: number; name: string }[]
  accepted_at: string | null
  created_at: string
  /** Jen u udělených: pozvánka čeká na založení účtu */
  pending?: boolean
}

export interface ShareInput {
  email?: string
  permission: SharePermission
  scope: ShareScope
  portfolio_ids: number[]
  sections: ShareSection[]
}

export interface HouseholdMember {
  user_id: number
  name: string
  me: boolean
  scope: ShareScope
  value: string
  invested: string
  total_return: string
  return_pct: string | null
  assets: string | null
  liabilities: string | null
  net_worth: string | null
}

export interface Household {
  currency: string
  members: HouseholdMember[]
  totals: { value: string; invested: string; total_return: string; assets: string; liabilities: string; net_worth: string; incomplete: number }
  allocation: { label: string; value: string }[]
  net_worth_partial: boolean
}

export interface InviteInfo { owner_name: string; email: string; permission: SharePermission; scope: ShareScope; portfolios: string[]; account_exists: boolean }

export const sharesApi = {
  list: () => api.get<{ granted: Share[]; received: Share[] }>('/shares').then(r => r.data),
  create: (p: ShareInput) => api.post<{ share: Share; invited: boolean; mail_sent: boolean }>('/shares', p).then(r => r.data),
  update: (id: number, p: ShareInput) => api.put<Share>(`/shares/${id}`, p).then(r => r.data),
  remove: (id: number) => api.delete(`/shares/${id}`),
  resend: (id: number) => api.post<{ mail_sent: boolean }>(`/shares/${id}/resend`).then(r => r.data),
  activity: () => api.get<{ id: number; created_at: string; actor_name?: string; method?: string; path?: string }[]>('/shares/activity').then(r => r.data),
  household: () => api.get<Household>('/household').then(r => r.data),
  invite: (token: string) => api.get<InviteInfo>(`/public/invite/${token}`).then(r => r.data),
  acceptInvite: (token: string, p: { name: string; password: string; password_confirm: string }) =>
    api.post<{ accepted: boolean }>(`/public/invite/${token}`, p).then(r => r.data),
}
