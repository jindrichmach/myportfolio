import { api } from './client'

export interface ApiToken {
  id: number
  name: string
  prefix: string
  scope: 'read' | 'read_write'
  last_used_at: string | null
  last_used_ip: string | null
  expires_at: string | null
  revoked_at: string | null
  created_at: string
  is_revoked: boolean
  is_expired: boolean
}

export interface CreateTokenPayload {
  name: string
  scope: 'read' | 'read_write'
  expires_at?: string | null
  totp_code?: string
  step_up_token?: string
}

export interface CreateTokenResult {
  token: string
  id: number
  prefix: string
  warning: string
}

export const tokensApi = {
  list: () => api.get<{ tokens: ApiToken[] }>('/auth/tokens').then((r) => r.data.tokens),

  create: (payload: CreateTokenPayload) =>
    api.post<CreateTokenResult>('/auth/tokens', payload).then((r) => r.data),

  revoke: (id: number) => api.delete(`/auth/tokens/${id}`),
}
