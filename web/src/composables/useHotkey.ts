import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Globální keyboard shortcuts.
 *  combo: "ctrl+s" | "ctrl+n" | "esc" | "/"
 *  handler dostane KeyboardEvent — může zavolat preventDefault.
 */
export function useHotkey(combo: string, handler: (e: KeyboardEvent) => void) {
  const parts = combo.toLowerCase().split('+').map(s => s.trim())
  const key = parts.pop() ?? ''
  const ctrl = parts.includes('ctrl') || parts.includes('cmd')
  const shift = parts.includes('shift')
  const alt = parts.includes('alt')

  function onKey(e: KeyboardEvent) {
    if (e.key.toLowerCase() !== key) return
    if (ctrl !== (e.ctrlKey || e.metaKey)) return
    if (shift !== e.shiftKey) return
    if (alt !== e.altKey) return
    handler(e)
  }

  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
}
