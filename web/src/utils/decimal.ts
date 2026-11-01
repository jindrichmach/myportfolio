/**
 * Odčítání desetinných čísel ze stringů bez floatu (množství má v DB 18 desetinných míst).
 * Vstup jako backend `Decimal::parse`: mezery pryč, čárka = tečka; neplatné číslo → null.
 */
const SCALE = 18

function toUnits(value: string): bigint | null {
  const v = value.replace(/\s/g, '').replace(',', '.')
  const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(v)
  if (!m || (m[3]?.length ?? 0) > SCALE) return null
  const units = BigInt(m[2] + (m[3] ?? '').padEnd(SCALE, '0'))
  return m[1] ? -units : units
}

function fromUnits(units: bigint): string {
  const sign = units < 0n ? '-' : ''
  const digits = (units < 0n ? -units : units).toString().padStart(SCALE + 1, '0')
  const frac = digits.slice(-SCALE).replace(/0+$/, '')
  return `${sign}${digits.slice(0, -SCALE)}${frac ? `.${frac}` : ''}`
}

/** a − b jako string bez koncových nul („0.500000000000000000“ − „0.0001“ = „0.4999“); null při neplatném vstupu. */
export function decimalMinus(a: string, b: string): string | null {
  const x = toUnits(a)
  const y = toUnits(b === '' ? '0' : b)
  return x === null || y === null ? null : fromUnits(x - y)
}
