import { useId } from 'vue'

/**
 * Unikátní id polí formuláře pro `<label :for>` + `:id` (WCAG 1.3.1). Prefix z `useId()` drží id
 * unikátní i při více instancích komponenty: `const fid = useFieldId()` → `fid('amount')`.
 */
export function useFieldId() {
  const base = useId()
  return (name: string) => `${base}-${name}`
}
