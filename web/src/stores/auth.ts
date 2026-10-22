import { defineStore } from 'pinia'
import { clearOwnerContext } from '@/composables/useOwnerContext'
import { ref, computed } from 'vue'
import { authApi, type User, type SetupStatus, type SessionState } from '@/api/auth'
import { setCsrfToken } from '@/api/client'
import { broadcastSessionEvent } from '@/security/sessionChannel'
import { setAppTimeZone } from '@/utils/date'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const csrfToken = ref<string>('')
  const setupStatus = ref<SetupStatus | null>(null)
  const allowedMfaMethods = ref<Array<'passkey' | 'totp'>>([])
  const requireMfa = ref(false)
  const loading = ref(false)
  const lockedSession = ref<SessionState | null>(null)
  const profileHydrated = ref(false)
  let logoutRetryCsrfToken = ''
  let logoutPromise: Promise<void> | null = null

  const isAuthenticated = computed(() => user.value !== null)
  const needsSetup = computed(() => setupStatus.value?.needs_setup === true)
  const mustSetupTotp = computed(() => user.value?.must_setup_totp === true)
  const mustSetupMfa = computed(() => user.value?.must_setup_mfa === true)

  const isAdmin = computed(() => user.value?.role === 'admin')

  async function fetchSetupStatus() {
    setupStatus.value = await authApi.setupStatus()
    setAppTimeZone(setupStatus.value.timezone ?? 'Europe/Prague')
    return setupStatus.value
  }

  function setSessionCsrfToken(token: string) {
    csrfToken.value = token
    setCsrfToken(token || null)
    if (token) logoutRetryCsrfToken = ''
  }

  function setMfaPolicy(required: boolean, methods: Array<'passkey' | 'totp'>) {
    requireMfa.value = required
    allowedMfaMethods.value = methods
  }

  async function refresh() {
    try {
      const data = await authApi.me()
      user.value = data.user
      setSessionCsrfToken(data.csrf_token)
      setMfaPolicy(data.require_mfa, data.allowed_mfa_methods)
      lockedSession.value = null
      profileHydrated.value = true
      return true
    } catch (error: any) {
      if (error?.response?.status === 423
          && error?.response?.data?.error?.code === 'session_locked') {
        try {
          const session = await authApi.sessionStatus()
          if (session.session_state === 'locked') {
            user.value = session.user
            setSessionCsrfToken(session.csrf_token)
            lockedSession.value = session
            profileHydrated.value = false
            return true
          }
        } catch {
          if (user.value !== null) return false
        }
      }
      if (!error?.response && user.value !== null) return false
      user.value = null
      setSessionCsrfToken('')
      setMfaPolicy(false, [])
      lockedSession.value = null
      profileHydrated.value = false
      return false
    }
  }

  async function login(
    email: string,
    password: string,
    captchaToken?: string,
    totp?: string,
    opts?: { emailOtp?: string; rememberDevice?: boolean; resendOtp?: boolean; recoveryCode?: string },
  ) {
    loading.value = true
    try {
      const data = await authApi.login({
        email,
        password,
        totp: totp || undefined,
        recovery_code: opts?.recoveryCode || undefined,
        email_otp: opts?.emailOtp || undefined,
        remember_device: opts?.rememberDevice || undefined,
        resend_otp: opts?.resendOtp || undefined,
        cf_turnstile_response: captchaToken,
      })
      user.value = data.user
      setSessionCsrfToken(data.csrf_token)
      setMfaPolicy(data.require_mfa, data.allowed_mfa_methods)
      lockedSession.value = null
      profileHydrated.value = false
      // Login response nenese kompletní profil (display_currency, …) — dotáhni /me
      await refresh()
      return data.user
    } finally {
      loading.value = false
    }
  }

  function clearPrivateState() {
    user.value = null
    csrfToken.value = ''
    setCsrfToken(null)
    setMfaPolicy(false, [])
    lockedSession.value = null
    profileHydrated.value = false
    clearOwnerContext()
  }

  async function performLogout() {
    const requestCsrfToken = logoutRetryCsrfToken || csrfToken.value
    if (requestCsrfToken) setCsrfToken(requestCsrfToken)

    try {
      await authApi.logout()
      logoutRetryCsrfToken = ''
      broadcastSessionEvent('logout')
    } catch (error) {
      logoutRetryCsrfToken = requestCsrfToken
      throw error
    } finally {
      clearPrivateState()
    }
  }

  function logout(): Promise<void> {
    if (logoutPromise) return logoutPromise

    const pending = performLogout()
    logoutPromise = pending
    void pending.then(
      () => {
        if (logoutPromise === pending) logoutPromise = null
      },
      () => {
        if (logoutPromise === pending) logoutPromise = null
      },
    )
    return pending
  }

  function setLockedSession(session: SessionState | null) {
    lockedSession.value = session
  }

  return {
    user,
    csrfToken,
    setupStatus,
    allowedMfaMethods,
    requireMfa,
    loading,
    lockedSession,
    profileHydrated,
    isAuthenticated,
    needsSetup,
    mustSetupTotp,
    mustSetupMfa,
    isAdmin,
    fetchSetupStatus,
    setSessionCsrfToken,
    setMfaPolicy,
    setLockedSession,
    refresh,
    login,
    logout,
  }
})
