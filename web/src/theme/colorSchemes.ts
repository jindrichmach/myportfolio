/**
 * Barevná schémata vzhledu. Tokeny jsou v styles/schemes.css (výchozí `forest` = hodnoty main.css),
 * favikony v public/pwa/favicon-<schéma>.svg, backend je zná v Service/Appearance/ColorSchemes.
 * Pořadí podle odstínu; swatch = [barva loga, akcent loga] pro náhled ve výběru.
 */
export const COLOR_SCHEMES = {
  raspberry:  ['#BE123C', '#FDA4AF'],
  terracotta: ['#C2410C', '#FDBA74'],
  bronze:     ['#8A5E1E', '#DBB980'],
  olive:      ['#65771F', '#C6D47A'],
  forest:     ['#1B7138', '#93D19F'],
  ocean:      ['#0369A1', '#7DD3FC'],
  indigo:     ['#4F46E5', '#A5B4FC'],
  amethyst:   ['#6D28D9', '#C4B5FD'],
  orchid:     ['#A21CAF', '#F0ABFC'],
  graphite:   ['#334155', '#CBD5E1'],
} as const satisfies Record<string, readonly [string, string]>

export type ColorScheme = keyof typeof COLOR_SCHEMES

export const DEFAULT_COLOR_SCHEME: ColorScheme = 'forest'

export const COLOR_SCHEME_KEYS = Object.keys(COLOR_SCHEMES) as ColorScheme[]

export function isColorScheme(value: unknown): value is ColorScheme {
  return typeof value === 'string' && value in COLOR_SCHEMES
}
