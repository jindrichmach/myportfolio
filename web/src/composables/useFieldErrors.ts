import { reactive } from 'vue'

/** Lokální kontrola pole po opravě: vrací hlášku, nebo null (pole je v pořádku). */
export type FieldValidator = (value: string) => string | null

/**
 * Číslo ve stejném formátu, jaký přijme backend (`Decimal::parse`): mezery pryč, čárka = tečka, prázdné je OK.
 */
export function isDecimal(value: string): boolean {
  const v = value.replace(/[\s  ]/g, '').replace(',', '.')
  return v === '' || /^-?\d+(?:\.\d+)?$/.test(v)
}

/**
 * Chyby validace z API (422 `error.fields`) u konkrétních polí místo souhrnu pod formulářem.
 * `attrs(field)` patří na input: `aria-invalid` (červený rámeček přes variantu `aria-invalid:`),
 * `aria-describedby` na hlášku `errorId(field)` a pole po opravě znovu ověří: při psaní chybu smaže, jakmile
 * hodnota projde validátorem (rozepsané „13,“ nebliká červeně), při opuštění pole ji u pole, které už chybu
 * mělo, znovu nastaví. Pole bez validátoru se úpravou vrátí do výchozího stavu — zbytek ověří backend při uložení.
 */
export function useFieldErrors(fid: (name: string) => string, validators: Record<string, FieldValidator> = {}) {
  const errors = reactive<Record<string, string[]>>({})
  // Pole, která už chybu měla — ta se při opuštění ověřují i po smazání chyby.
  const touched = new Set<string>()

  function clear() {
    for (const k of Object.keys(errors)) delete errors[k]
  }

  /** Převezme chyby polí z axios chyby; vrací false, když odpověď žádné chyby polí nenese. */
  function fromApi(e: unknown): boolean {
    clear()
    const fields = (e as { response?: { data?: { error?: { fields?: unknown } } } })?.response?.data?.error?.fields
    if (!fields || typeof fields !== 'object') return false
    for (const [field, msgs] of Object.entries(fields)) {
      const list = (Array.isArray(msgs) ? msgs : [msgs]).filter((m): m is string => typeof m === 'string' && m !== '')
      if (list.length) {
        errors[field] = list
        touched.add(field)
      }
    }
    return Object.keys(errors).length > 0
  }

  /** `onBlur`: neplatnou hodnotu označí i u pole, jehož chyba už byla smazaná. */
  function recheck(field: string, value: unknown = '', onBlur = false) {
    if (!errors[field] && !(onBlur && touched.has(field))) return
    const msg = validators[field]?.(String(value ?? '')) ?? null
    if (msg) errors[field] = [msg]
    else delete errors[field]
  }

  const invalid = (field: string) => !!errors[field]
  // Při psaní jen úklid: chybu smaže až platná hodnota (pole bez validátoru hned).
  const isValid = (field: string, e: Event) => !validators[field]?.((e.target as HTMLInputElement).value)
  const errorId = (field: string) => fid(`${field}-error`)

  function attrs(field: string) {
    return {
      'aria-invalid': invalid(field) || undefined,
      'aria-describedby': invalid(field) ? errorId(field) : undefined,
      onInput: (e: Event) => { if (isValid(field, e)) recheck(field, (e.target as HTMLInputElement).value) },
      onBlur: (e: Event) => recheck(field, (e.target as HTMLInputElement).value, true),
    }
  }

  return { errors, clear, fromApi, recheck, invalid, errorId, attrs }
}
