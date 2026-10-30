import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'
import { COLOR_SCHEMES, DEFAULT_COLOR_SCHEME, isColorScheme, type ColorScheme } from '@/theme/colorSchemes'

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

/**
 * Barevné schéma (vzhled): volba uživatele, jinak schéma instance — nastavuje App.vue podle auth store.
 * Uložené v localStorage, aby ho anti-FOUC script v index.html nastavil před startem appky (stejný klíč).
 */
export const SCHEME_STORAGE_KEY = 'myportfolio-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const storedScheme = useStorage<string>(SCHEME_STORAGE_KEY, DEFAULT_COLOR_SCHEME)
const colorScheme = computed<ColorScheme>({
  get: () => (isColorScheme(storedScheme.value) ? storedScheme.value : DEFAULT_COLOR_SCHEME),
  set: (value) => { storedScheme.value = value },
})
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

// flush 'sync': třída .dark musí být nastavená dřív, než useChartColors přečte CSS proměnné
watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
}, { flush: 'sync' })

// Výchozí schéma = bez atributu (hodnoty main.css); favikona a barva lišty prohlížeče podle loga schématu.
watchEffect(() => {
  const scheme = colorScheme.value
  const root = document.documentElement
  if (scheme === DEFAULT_COLOR_SCHEME) root.removeAttribute('data-scheme')
  else root.setAttribute('data-scheme', scheme)
  document.querySelector('link[rel="icon"][type="image/svg+xml"]')
    ?.setAttribute('href', scheme === DEFAULT_COLOR_SCHEME ? '/pwa/favicon.svg' : `/pwa/favicon-${scheme}.svg`)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', COLOR_SCHEMES[scheme][0])
}, { flush: 'sync' })

export function useTheme() {
  return { preference, isDark, colorScheme }
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
  const dark = isDark.value // závislost: po přepnutí motivu nebo schématu přečíst hodnoty znovu
  void colorScheme.value
  return {
    border: token('surface'),
    tick: token('chart-tick'),
    grid: token('chart-grid'),
    tooltipBg: token('chart-tooltip'),
    primary: token(dark ? 'primary-500' : 'primary-600'),
    gain: token('chart-gain'),
    loss: token('chart-loss'),
    palette: Array.from({ length: 10 }, (_, i) => token(`chart-${i + 1}`)),
    /** Barva třídy aktiv podle rizikovosti (crypto → cash). */
    risk: Object.fromEntries(['crypto', 'stock', 'etf', 'fund', 'cash'].map(c => [c, token(`risk-${c}`)])) as Record<string, string>,
  }
})

export function useChartColors() {
  return chartColors
}
