import { onUnmounted, ref } from 'vue'

interface TurnstileApi {
  render: (
    container: string | HTMLElement,
    options: {
      sitekey: string
      action?: string
      callback?: (token: string) => void
      'error-callback'?: () => void
      'expired-callback'?: () => void
      theme?: 'light' | 'dark' | 'auto'
    },
  ) => string
  reset: (widgetId?: string) => void
  remove: (widgetId: string) => void
}

declare global {
  interface Window {
    turnstile?: TurnstileApi
    onTurnstileLoad?: () => void
  }
}

const SCRIPT_ID = 'cf-turnstile-script'
let scriptPromise: Promise<void> | null = null

function loadScript(scriptUrl: string): Promise<void> {
  if (window.turnstile) return Promise.resolve()
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise((resolve, reject) => {
    if (document.getElementById(SCRIPT_ID)) return resolve()
    const s = document.createElement('script')
    s.id = SCRIPT_ID
    s.src = scriptUrl
    s.async = true
    s.defer = true
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('Turnstile script load failed'))
    document.head.appendChild(s)
  })

  return scriptPromise
}

export function useTurnstile() {
  const token = ref<string>('')
  const widgetId = ref<string | null>(null)
  const loaded = ref(false)
  const containerRef = ref<HTMLElement | null>(null)

  async function render(siteKey: string, scriptUrl: string, action = 'login') {
    if (!containerRef.value || !siteKey || !scriptUrl) return

    await loadScript(scriptUrl)
    // Po načtení skriptu počkat až bude window.turnstile
    let waited = 0
    while (!window.turnstile && waited < 3000) {
      await new Promise((r) => setTimeout(r, 50))
      waited += 50
    }
    if (!window.turnstile) return

    widgetId.value = window.turnstile.render(containerRef.value, {
      sitekey: siteKey,
      action,
      callback: (t) => {
        token.value = t
      },
      'error-callback': () => {
        token.value = ''
      },
      'expired-callback': () => {
        token.value = ''
      },
    })
    loaded.value = true
  }

  function reset() {
    if (window.turnstile && widgetId.value) {
      window.turnstile.reset(widgetId.value)
      token.value = ''
    }
  }

  onUnmounted(() => {
    if (window.turnstile && widgetId.value) {
      try { window.turnstile.remove(widgetId.value) } catch {/* ignore */}
    }
  })

  return { token, widgetId, loaded, containerRef, render, reset }
}
