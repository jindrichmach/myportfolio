import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'

/**
 * Barevný režim aplikace: System / Light / Dark.
 *
 * Why: `auto` respektuje OS (prefers-color-scheme), `light`/`dark` ho přebijí.
 * Volba se ukládá do localStorage (klíč musí sedět s anti-FOUC scriptem v index.html).
 * Reaktivně přepíná třídu `.dark` na <html>, na kterou je navázán dark scope v main.css.
 *
 * Stav je modul-level singleton, takže všechny komponenty sdílejí jednu instanci
 * a watchEffect běží jen jednou.
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myportfolio-color-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

// flush 'sync': třída .dark musí být nastavená dřív, než useChartColors přečte CSS proměnné
watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
}, { flush: 'sync' })

export function useTheme() {
  return { preference, isDark }
}

/**
 * Barvy pro chart.js — ten nečte CSS proměnné sám, takže je bereme z tokenů v styles/main.css
 * (--color-chart-*, light i .dark scope). Jediný zdroj hodnot je main.css.
 * Sdílený singleton; v komponentě: const colors = useChartColors() + watch(colors, build).
 */
function token(name: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim()
}

const chartColors = computed(() => {
  const dark = isDark.value // závislost: po přepnutí motivu přečíst hodnoty znovu
  return {
    border: token('surface'),
    tick: token('chart-tick'),
    grid: token('chart-grid'),
    tooltipBg: token('chart-tooltip'),
    primary: token(dark ? 'primary-500' : 'primary-600'),
    gain: token('chart-gain'),
    loss: token('chart-loss'),
    palette: Array.from({ length: 10 }, (_, i) => token(`chart-${i + 1}`)),
  }
})

export function useChartColors() {
  return chartColors
}
