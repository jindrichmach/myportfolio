/** Barva textu podle znaménka hodnoty z API (zisk / ztráta); nula a chybějící hodnota bez barvy. */
export function gainClass(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  const n = Number(value)
  return n > 0 ? 'text-success-600' : n < 0 ? 'text-danger-500' : ''
}
