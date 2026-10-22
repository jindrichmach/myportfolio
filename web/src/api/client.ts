import axios from 'axios'

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

// CSRF token interceptor — token žije v Pinia auth store
let csrfToken: string | null = null
export function setCsrfToken(token: string | null) {
  csrfToken = token
}

api.interceptors.request.use((config) => {
  if (csrfToken && config.method && config.method.toUpperCase() !== 'GET') {
    config.headers.set('X-CSRF-Token', csrfToken)
  }
  // Pošli aktuální UI locale, aby backend hlášky chodily ve správném jazyce.
  // Auth middleware ji přepíše user.locale (pokud přihlášen).
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)
  // Sdílená data jiného uživatele (useOwnerContext) — osobní cesty backend ignoruje
  const owner = localStorage.getItem('myportfolio-owner')
  if (owner) config.headers.set('X-Owner', owner)
  return config
})

// 401 → redirect na /login (kromě situace kdy už jsme na /login nebo /setup)
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const code = error.response?.data?.error?.code

    if (status === 401 && [
      'unauthenticated',
      'session_expired',
      'invalid_token',
      'mfa_reauthentication_required',
    ].includes(code)) {
      const path = window.location.pathname
      if (!path.startsWith('/login') && !path.startsWith('/setup')) {
        window.location.href = '/login'
      }
    }
    if (status === 423 && code === 'session_locked') {
      window.dispatchEvent(new CustomEvent('myportfolio:session-locked'))
    }
    if (status === 423 && code === 'setup_required') {
      window.location.href = '/setup'
    }
    // 403 totp_setup_required = require_totp=true a uživatel nemá aktivní TOTP.
    // Backend takhle blokuje všechno mimo whitelist (/me, /logout, /totp/*).
    // Frontend má router guard, ale když 403 přijde z přímého API volání
    // (např. po HMR / bez navigation), interceptor zaručí redirect.
    // /login NEVYJÍMÁME — když máš stale session a otevřeš /login, redirect
    // na /setup-totp je správný.
    if (status === 403 && code === 'totp_setup_required') {
      if (window.location.pathname !== '/setup-mfa') {
        window.location.href = '/setup-mfa?method=totp'
      }
    }
    if (status === 403 && code === 'mfa_setup_required') {
      if (window.location.pathname !== '/setup-mfa') {
        window.location.href = '/setup-mfa'
      }
    }

    // 503 config_missing / bootstrap_failed = backend není nakonfigurovaný
    // (chybí cfg.php nebo nelze do DB). Zobrazíme fullscreen overlay s návodem,
    // ať uživatel nedostane jen prázdný login form bez vysvětlení.
    if (status === 503 && (code === 'config_missing' || code === 'bootstrap_failed')) {
      showBootstrapErrorOverlay(
        code === 'config_missing'
          ? 'Backend není nakonfigurovaný (chybí cfg.php).'
          : 'Backend selhal při startu (typicky nedostupná databáze).',
        error.response?.data?.error?.message || '',
        error.response?.data?.error?.hint || '',
      )
    }

    return Promise.reject(error)
  },
)

function showBootstrapErrorOverlay(title: string, detail: string, hint: string): void {
  if (document.getElementById('bootstrap-error-overlay')) return  // už zobrazený
  const div = document.createElement('div')
  div.id = 'bootstrap-error-overlay'
  // Barvy z tokenů v main.css (načtené i při chybě bootstrapu) → light i dark bez vlastních hodnot
  div.style.cssText = 'position:fixed;inset:0;z-index:99999;background:var(--color-backdrop);' +
    'display:flex;align-items:center;justify-content:center;font:14px/1.5 var(--font-sans);'
  div.innerHTML = `
    <div style="background:var(--color-surface);max-width:560px;width:90%;padding:32px;border-radius:12px;
                box-shadow:var(--shadow-xl);">
      <h2 style="margin:0 0 12px;color:var(--color-primary-700);font-size:24px;">MyPortfolio</h2>
      <p style="margin:0 0 16px;color:var(--color-neutral-900);font-weight:600;">${escapeHtml(title)}</p>
      ${detail ? `<p style="margin:0 0 12px;color:var(--color-neutral-600);font-family:var(--font-mono);
        background:var(--color-neutral-100);padding:8px 12px;border-radius:6px;font-size:13px;">${escapeHtml(detail)}</p>` : ''}
      ${hint ? `<p style="margin:0 0 16px;color:var(--color-neutral-600);">${escapeHtml(hint)}</p>` : ''}
      <p style="margin:0;color:var(--color-neutral-600);font-size:13px;">
        Kontaktuj administrátora a pošli mu tuhle hlášku.
        Detail v <code>log/bootstrap-error.log</code>.
      </p>
    </div>`
  document.body.appendChild(div)
}

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}

export interface HealthResponse {
  status: 'ok'
  version: string
  db: boolean
  redis: boolean
  warnings?: Array<{ code: string; message: string }>
  time: string
}

export const systemApi = {
  health: (signal?: AbortSignal) =>
    api.get<HealthResponse>('/health', { signal }).then((r) => r.data),
}
