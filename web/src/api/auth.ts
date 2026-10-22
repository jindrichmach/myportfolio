import { api } from './client'

export type DisplayCurrency = 'CZK' | 'EUR' | 'USD'

export interface User {
  id: number
  email: string
  name: string
  role: 'admin' | 'user'
  locale: 'cs' | 'en'
  display_currency?: DisplayCurrency
  totp_enabled?: boolean
  must_setup_totp?: boolean
  mfa_enabled?: boolean
  mfa_methods?: Array<'passkey' | 'totp'>
  passkey_count?: number
  must_setup_mfa?: boolean
}

export interface SetupStatus {
  needs_setup: boolean
  version: string
  timezone?: string
  passwordless_login_enabled: boolean
  captcha: {
    provider: 'turnstile' | 'none'
    site_key: string
    script_url: string
  }
}

export interface LoginPayload {
  email: string
  password: string
  totp?: string
  email_otp?: string
  /** Záložní jednorázový kód — break-glass při ztraceném silném faktoru. */
  recovery_code?: string
  remember_device?: boolean
  resend_otp?: boolean
  cf_turnstile_response?: string
}

/** Stav sady záložních kódů. Kódy samotné server podruhé nevydá. */
export interface RecoveryCodeStatus {
  total: number
  remaining: number
  generated_at: string | null
  low: boolean
  batch_size: number
}

export interface WebAuthnFlow {
  flow_token: string
  public_key: Record<string, any>
}

export interface SessionState {
  session_state: 'active' | 'locked'
  csrf_token: string
  server_time: string
  idle_expires_at: string | null
  lock_after_minutes: number
  unlock_methods: Array<'passkey'>
  user: User
}

export interface SessionLockPreference {
  user_lock_after_minutes: number | null
  admin_lock_after_minutes: number
  maximum_lock_after_minutes: number
  effective_lock_after_minutes: number
}

export interface SessionLockPreferenceUpdate extends SessionLockPreference {
  session: SessionState
}

export interface AuthSessionContract {
  user: User
  csrf_token: string
  require_totp: boolean
  require_mfa: boolean
  allowed_mfa_methods: Array<'passkey' | 'totp'>
  session_state: 'active'
  server_time: string
  idle_expires_at: string | null
  lock_after_minutes: number
}

export type MeResponse = AuthSessionContract

export interface PasskeyCredential {
  id: number
  label: string
  transports: string[]
  backup_eligible: boolean
  backup_state: boolean
  created_at: string | null
  last_used_at: string | null
}

export interface MfaSetupCompletion {
  csrf_token?: string
  session_state?: 'active'
  must_setup_mfa?: false
}

export interface PasskeyRegistrationResult extends MfaSetupCompletion {
  credential: PasskeyCredential
}

export interface TotpSetup {
  secret: string
  uri: string
  qr_data_uri: string
  issuer: string
}

export interface SetupSmtp {
  host: string
  port: number
  encryption: 'ssl' | 'tls' | 'none'
  verify_peer: boolean
  auth_enabled: boolean
  username: string
  password: string
  from_email: string
  from_name: string
}

export interface SetupPayload {
  admin: { name: string; email: string; password: string; locale?: 'cs' | 'en'; display_currency?: 'CZK' | 'EUR' | 'USD' }
  /** Volitelné SMTP (stejný formulář jako v administraci) */
  smtp?: SetupSmtp | null
  /** Ukázková data do účtu admina; kurzy a ceny se stáhnou na pozadí */
  demo_data?: boolean
  require_mfa?: boolean
  allowed_mfa_methods?: Array<'passkey' | 'totp'>
  /** Legacy kompatibilita pro starší backendy/klienty. */
  require_totp?: boolean
}

export const authApi = {
  setupStatus: () => api.get<SetupStatus>('/auth/setup-status').then((r) => r.data),

  setupSmtpTest: (smtp: SetupSmtp, to: string, locale: string) =>
    api.post<{ ok: boolean; message: string }>('/auth/setup/smtp-test', { smtp, to, locale }, { timeout: 60_000 }).then((r) => r.data),

  setup: (payload: SetupPayload) =>
    api.post<{
      user: User
      next: string
      csrf_token: string
      require_totp: boolean
      require_mfa: boolean
      allowed_mfa_methods: Array<'passkey' | 'totp'>
      cfg_local_written: boolean
      demo: { ok: boolean; transactions?: number; backfill_started?: boolean; message?: string } | null
    }>(
      '/auth/setup',
      payload,
    ).then(r => r.data),

  login: (payload: LoginPayload) =>
    api.post<AuthSessionContract>('/auth/login', payload).then(r => r.data),

  passkeyLoginOptions: (captchaToken?: string) =>
    api.post<WebAuthnFlow>('/auth/webauthn/login/options', {
      cf_turnstile_response: captchaToken,
    }).then(r => r.data),

  passkeyLoginVerify: (flowToken: string, credential: Record<string, any>) =>
    api.post<AuthSessionContract>('/auth/webauthn/login/verify', {
      flow_token: flowToken,
      credential,
    }).then(r => r.data),

  sessionStatus: () =>
    api.get<SessionState>('/auth/session/status').then(r => r.data),
  sessionActivity: () =>
    api.post<SessionState>('/auth/session/activity', {}).then(r => r.data),
  sessionLock: () =>
    api.post<SessionState>('/auth/session/lock', {}).then(r => r.data),
  sessionLockPreference: () =>
    api.get<SessionLockPreference>('/auth/session/lock-preference').then(r => r.data),
  updateSessionLockPreference: (lockAfterMinutes: number | null) =>
    api.put<SessionLockPreferenceUpdate>('/auth/session/lock-preference', {
      lock_after_minutes: lockAfterMinutes,
    }).then(r => r.data),
  sessionUnlockOptions: () =>
    api.post<WebAuthnFlow>('/auth/session/unlock/options', {}).then(r => r.data),
  sessionUnlockVerify: (flowToken: string, credential: Record<string, any>) =>
    api.post<SessionState>('/auth/session/unlock/verify', {
      flow_token: flowToken,
      credential,
    }).then(r => r.data),

  passkeys: () =>
    api.get<{ credentials: PasskeyCredential[] }>('/auth/webauthn/credentials').then(r => r.data.credentials),
  passkeyRegisterOptions: (authorization: { current_password?: string; step_up_token?: string }) =>
    api.post<WebAuthnFlow>('/auth/webauthn/register/options', authorization).then(r => r.data),
  passkeyRegisterVerify: (flowToken: string, credential: Record<string, any>, label: string) =>
    api.post<PasskeyRegistrationResult>('/auth/webauthn/register/verify', {
      flow_token: flowToken, credential, label,
    }).then(r => r.data),
  passkeyRename: (id: number, label: string) =>
    api.patch<{ credential: PasskeyCredential }>(`/auth/webauthn/credentials/${id}`, { label }).then(r => r.data.credential),
  passkeyRevoke: (id: number, stepUpToken: string) =>
    api.delete(`/auth/webauthn/credentials/${id}`, { data: { step_up_token: stepUpToken } }),
  passkeyStepUpOptions: (operation: string) =>
    api.post<WebAuthnFlow>('/auth/webauthn/step-up/options', { operation }).then(r => r.data),
  passkeyStepUpVerify: (flowToken: string, operation: string, credential: Record<string, any>) =>
    api.post<{ step_up_token: string }>('/auth/webauthn/step-up/verify', {
      flow_token: flowToken, operation, credential,
    }).then(r => r.data.step_up_token),
  totpStepUp: (operation: string, code: string) =>
    api.post<{ step_up_token: string }>('/auth/mfa/step-up/totp', { operation, code })
      .then(r => r.data.step_up_token),
  recoveryStepUp: (operation: string, code: string) =>
    api.post<{ step_up_token: string; remaining: number }>('/auth/mfa/step-up/recovery', { operation, code })
      .then(r => r.data),

  recoveryCodeStatus: () =>
    api.get<RecoveryCodeStatus>('/auth/mfa/recovery-codes').then(r => r.data),
  /** Vrátí kódy v plaintextu — jediná a poslední příležitost, kdy je lze zobrazit. */
  generateRecoveryCodes: (stepUpToken: string) =>
    api.post<RecoveryCodeStatus & { codes: string[] }>('/auth/mfa/recovery-codes', {
      step_up_token: stepUpToken,
    }).then(r => r.data),

  logout: () => api.post('/auth/logout').then(() => undefined),

  me: () => api.get<MeResponse>('/auth/me').then(r => r.data),

  changePassword: (current: string, next: string) =>
    api.post('/auth/change-password', {
      current_password: current,
      new_password: next,
      new_password_confirm: next,
    }),

  forgot: (email: string, turnstileToken?: string) =>
    api.post('/auth/forgot', {
      email,
      ...(turnstileToken ? { cf_turnstile_response: turnstileToken } : {}),
    }),

  reset: (token: string, password: string) =>
    api.post('/auth/reset', { token, password, password_confirm: password }),

  // TOTP / 2FA
  totpStatus: () => api.get<{ enabled: boolean }>('/auth/totp/status').then(r => r.data),
  totpSetup:  () => api.post<TotpSetup>('/auth/totp/setup').then(r => r.data),
  totpEnable: (code: string) =>
    api.post<{ enabled: boolean } & MfaSetupCompletion>('/auth/totp/enable', { code }).then(r => r.data),
}
