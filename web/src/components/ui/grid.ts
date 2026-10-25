/**
 * Definice filtrů a stránkování pro DataGrid (čistá logika, bez Vue).
 *
 * Typy filtrů:
 * - text: hledání podle textu
 * - select: jeden výběr (prázdná hodnota = vše)
 * - multiselect: více voleb najednou (OR)
 * - daterange: rozsah datumů „od–do“ (YYYY-MM-DD, obě hranice volitelné)
 * - custom: vlastní vstup, který si vykreslí rodič přes slot `filter-<key>`
 *
 * `column` určuje sloupec řádku, nad kterým filtr v klientském režimu filtruje. Bez `column` filtr
 * nepůsobí sám; výsledek počítá `filterFn` rodiče, který může kombinovat víc filtrů a polí.
 */
export interface GridOption {
  value: string
  label: string
}

export type GridFilter =
  | { key: string; type: 'text'; label: string; placeholder?: string; column?: string }
  | { key: string; type: 'select'; label: string; options: GridOption[]; allLabel: string; column?: string }
  | { key: string; type: 'multiselect'; label: string; options: GridOption[]; allLabel: string; column?: string }
  | { key: string; type: 'daterange'; label: string; column?: string }
  | { key: string; type: 'custom'; label: string }

export interface GridDateRange {
  from: string
  to: string
}

export type GridFilterValues = Record<string, unknown>

export interface GridQuery {
  page: number
  pageSize: number
  filterValues: GridFilterValues
}

/** Výchozí hodnota filtru podle typu. */
export function defaultFilterValue(filter: GridFilter): unknown {
  switch (filter.type) {
    case 'text':
    case 'select':
      return ''
    case 'multiselect':
      return []
    case 'daterange':
      return { from: '', to: '' } satisfies GridDateRange
    case 'custom':
      return undefined
  }
}

/** Je filtr nastavený (počítá se do odznaku na tlačítku Filtry). */
export function isFilterActive(filter: GridFilter, value: unknown): boolean {
  switch (filter.type) {
    case 'text':
    case 'select':
      return typeof value === 'string' && value.trim() !== ''
    case 'multiselect':
      return Array.isArray(value) && value.length > 0
    case 'daterange': {
      const range = value as GridDateRange | undefined
      return !!range && (range.from !== '' || range.to !== '')
    }
    case 'custom':
      return value !== undefined && value !== null && value !== ''
  }
}

/** Výchozí predikát jednoho filtru nad hodnotou sloupce. Filtr bez `column` vrací true. */
export function matchesFilter(filter: GridFilter, value: unknown, cell: unknown): boolean {
  if (filter.type === 'custom' || !filter.column || !isFilterActive(filter, value)) return true
  const text = String(cell ?? '')
  switch (filter.type) {
    case 'text':
      return text.toLowerCase().includes(String(value).trim().toLowerCase())
    case 'select':
      return text === value
    case 'multiselect':
      return (value as string[]).includes(text)
    case 'daterange': {
      const day = text.slice(0, 10)
      const range = value as GridDateRange
      return (range.from === '' || day >= range.from) && (range.to === '' || day <= range.to)
    }
  }
}

export function pageCount(total: number, pageSize: number): number {
  return Math.max(1, Math.ceil(total / pageSize))
}

export function clampPage(page: number, count: number): number {
  return Math.min(Math.max(1, page), count)
}

/**
 * Položky číselného stránkování: čísla stránek a mezery (`'gap'`) za skrytými stránkami.
 * Vždy se ukáže první a poslední stránka a `siblings` stránek kolem aktuální. Mezera přes jednu
 * stránku se nahradí touto stránkou, aby se nezobrazovala zbytečná „…“.
 */
export function pageItems(current: number, count: number, siblings = 2): Array<number | 'gap'> {
  if (count <= 1) return [1]
  const items: Array<number | 'gap'> = [1]
  const left = Math.max(2, current - siblings)
  const right = Math.min(count - 1, current + siblings)
  if (left === 3) items.push(2)
  else if (left > 3) items.push('gap')
  for (let p = left; p <= right; p++) items.push(p)
  if (right === count - 2) items.push(count - 1)
  else if (right < count - 2) items.push('gap')
  items.push(count)
  return items
}
