import {
  computed,
  onMounted,
  onUnmounted,
  ref,
  toValue,
  watch,
  type MaybeRefOrGetter,
} from 'vue'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

export function useSessionAwarePolling(
  task: (signal: AbortSignal) => Promise<void>,
  intervalMs: number,
  enabled: MaybeRefOrGetter<boolean> = true,
) {
  const security = useSessionSecurityStore()
  const mounted = ref(false)
  const visible = ref(typeof document === 'undefined' || document.visibilityState === 'visible')
  const allowed = computed(() => mounted.value
    && visible.value
    && !security.privacyCurtain
    && toValue(enabled))

  let timer: number | null = null
  let controller: AbortController | null = null
  let generation = 0
  let running = false
  let rerun = false

  function clearTimer() {
    if (timer !== null) {
      window.clearTimeout(timer)
      timer = null
    }
  }

  function stopCurrent() {
    clearTimer()
    controller?.abort()
    controller = null
  }

  async function run(currentGeneration: number) {
    if (!allowed.value || currentGeneration !== generation) return
    if (running) {
      rerun = true
      return
    }

    running = true
    rerun = false
    controller = new AbortController()
    const signal = controller.signal
    try {
      await task(signal)
    } catch {
      // Chybu zobrazuje stránka; abort při lock/hidden je očekávaný.
    } finally {
      running = false
      controller = null
      if (!allowed.value) return
      if (rerun || currentGeneration !== generation) {
        rerun = false
        void run(generation)
        return
      }
      timer = window.setTimeout(() => {
        timer = null
        void run(generation)
      }, intervalMs)
    }
  }

  function restart() {
    generation += 1
    stopCurrent()
    if (allowed.value) {
      if (running) rerun = true
      else void run(generation)
    }
  }

  function visibilityChanged() {
    visible.value = document.visibilityState === 'visible'
  }

  watch(allowed, restart, { flush: 'sync' })

  onMounted(() => {
    document.addEventListener('visibilitychange', visibilityChanged)
    visible.value = document.visibilityState === 'visible'
    mounted.value = true
  })

  onUnmounted(() => {
    mounted.value = false
    generation += 1
    stopCurrent()
    document.removeEventListener('visibilitychange', visibilityChanged)
  })

  return { active: allowed }
}
