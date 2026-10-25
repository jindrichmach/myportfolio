import { api } from './client'
import type { DisplayCurrency } from './auth'
import type { ColorScheme } from '@/theme/colorSchemes'

export interface Profile {
  name: string
  email: string
  locale: 'cs' | 'en'
  display_currency: DisplayCurrency
  /** null = výchozí schéma instance */
  color_scheme: ColorScheme | null
}

export const settingsApi = {
  profile: () => api.get<Profile>('/settings/profile').then(r => r.data),
  updateProfile: (payload: Partial<Pick<Profile, 'name' | 'locale' | 'display_currency' | 'color_scheme'>>) =>
    api.put<Profile>('/settings/profile', payload).then(r => r.data),
}

export interface WeeklyReportSettings {
  enabled: boolean
  /** true = i jednotlivé pozice (instrumenty), false = jen portfolia a účty */
  detailed: boolean
  /** Měsíční / roční souhrn e-mailem (stejní příjemci) */
  monthly: boolean
  yearly: boolean
  recipients: string[]
  default_email: string
  sent_at: string | null
}

export const weeklyReportApi = {
  get: () => api.get<WeeklyReportSettings>('/settings/weekly-report').then(r => r.data),
  update: (p: { enabled: boolean; detailed: boolean; monthly: boolean; yearly: boolean; recipients: string[] }) => api.put<WeeklyReportSettings>('/settings/weekly-report', p).then(r => r.data),
  send: () => api.post<{ sent_to: string[] }>('/settings/weekly-report/send', {}, { timeout: 120_000 }).then(r => r.data),
  previewUrl: '/api/settings/weekly-report/preview',
  periodPreviewUrl: (period?: string) => `/api/settings/period-report/preview${period ? `?period=${period}` : ''}`,
}
