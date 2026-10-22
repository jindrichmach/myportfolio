import { api } from './client'

export interface ActivityLogEntry {
  id: number
  user_id: number | null
  user_email: string | null
  user_name: string | null
  action: string
  entity_type: string | null
  entity_id: number | null
  payload: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface ActivityLogResponse {
  data: ActivityLogEntry[]
  total: number
  limit: number
  offset: number
  actions: Array<{ action: string; cnt: number }>
}

export interface AdminUser {
  id: number
  email: string
  name: string
  role: 'admin' | 'user'
  locale: 'cs' | 'en'
  is_active: boolean
  created_at: string
  last_login_at: string | null
}

export interface ReleaseInfo {
  version: string
  name: string
  published_at: string | null
  url: string
  notes: string
  prerelease: boolean
}

export interface UpdateStatus {
  current: string
  latest: ReleaseInfo | null
  update_available: boolean
  checked_at: string | null
  error: 'unavailable' | 'not_configured' | null
  repo: string
  install: 'docker' | 'native'
  app_dir: string
  php: string
}

export const adminApi = {
  update: (refresh = false) => api.get<UpdateStatus>('/admin/update', { params: refresh ? { refresh: 1 } : {} }).then(r => r.data),
  activityLog: (params: { action?: string; user_id?: number; entity_type?: string; entity_id?: number; limit?: number; offset?: number } = {}) =>
    api.get<ActivityLogResponse>('/admin/activity-log', { params }).then(r => r.data),

  // Users
  listUsers: () => api.get<AdminUser[]>('/admin/users').then(r => r.data),
  createUser: (payload: { email: string; name: string; role: AdminUser['role']; locale?: 'cs' | 'en'; password: string }) =>
    api.post<AdminUser>('/admin/users', payload).then(r => r.data),
  updateUser: (id: number, payload: Partial<{ name: string; role: AdminUser['role']; locale: 'cs' | 'en'; is_active: boolean; password: string }>) =>
    api.put<AdminUser>(`/admin/users/${id}`, payload).then(r => r.data),
  deleteUser: (id: number) => api.delete(`/admin/users/${id}`),

  // Cron jobs (Systém → Plánované úlohy)
  cronJobs: (signal?: AbortSignal) =>
    api.get<CronJobsResponse>('/admin/cron-jobs', { signal }).then(r => r.data),
  runCronJob: (script: string) =>
    api.post<{ script: string; started: boolean }>(`/admin/cron-jobs/${encodeURIComponent(script)}/run`).then(r => r.data),
}

export type CronJobHealth = 'ok' | 'overdue' | 'failing' | 'overdue_and_failing' | 'never_ran'

export interface CronJob {
  script: string
  recommended: string
  linux_cron: string
  weekdays_only: boolean
  critical: boolean
  max_age_hours: number
  health: CronJobHealth
  last_started_at: string | null
  last_finished_at: string | null
  last_status: 'running' | 'ok' | 'error' | null
  last_duration_ms: number | null
  last_exit_code: number | null
  last_host: string | null
  last_message: string | null
  last_report: Record<string, unknown> | null
  last_ok_started_at: string | null
  last_ok_finished_at: string | null
  age_sec_since_ok: number | null
  counts_24h: { ok: number; error: number; total: number }
}

export interface CronJobsResponse {
  jobs: CronJob[]
  server_time: string
}

export type SmtpEncryption = 'ssl' | 'tls' | 'none'

export interface SmtpForm {
  enabled: boolean
  host: string
  port: number
  encryption: SmtpEncryption
  verify_peer: boolean
  auth_enabled: boolean
  username: string | null
  from_email: string
  from_name: string
  reply_to_email: string | null
  reply_to_name: string | null
}

export interface SmtpSettingsResponse {
  settings: (SmtpForm & {
    has_password: boolean
    last_test_at: string | null
    last_test_status: 'ok' | 'error' | null
    last_test_message: string | null
  }) | null
  active_source: 'db' | 'cfg'
  /** Hodnoty z cfg.php (bez hesla) — výchozí formulář, když v DB nic není. */
  cfg: SmtpForm & { has_password: boolean }
}

export const smtpApi = {
  get: () => api.get<SmtpSettingsResponse>('/admin/smtp').then(r => r.data),
  /** password: prázdné = beze změny */
  save: (p: SmtpForm & { password: string }) => api.put<SmtpSettingsResponse>('/admin/smtp', p).then(r => r.data),
  test: (p: SmtpForm & { password: string; to: string }) =>
    api.post<{ ok: boolean; message: string }>('/admin/smtp/test', p, { timeout: 60_000 }).then(r => r.data),
}
