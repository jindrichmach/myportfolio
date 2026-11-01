import { onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

type Field = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement

const isField = (el: EventTarget | null): el is Field =>
  el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement

/**
 * Nativní bubliny HTML5 validace mluví jazykem prohlížeče, ne aplikace. Jeden globální listener
 * (capture `invalid`) nahradí text hláškou z i18n podle `validity`; při úpravě pole se vlastní text smaže,
 * ať pole zase validuje prohlížeč. Volá se jednou v App.vue.
 */
export function useValidationMessages() {
  const { t } = useI18n()

  function message(el: Field): string {
    const v = el.validity
    const input = el instanceof HTMLInputElement ? el : null
    if (v.valueMissing) return t(input?.type === 'file' ? 'validation.file_required' : el instanceof HTMLSelectElement ? 'validation.select_required' : 'validation.required')
    if (v.typeMismatch) return t(input?.type === 'email' ? 'validation.email' : input?.type === 'url' ? 'validation.url' : 'validation.invalid')
    if (v.badInput) return t(input?.type === 'number' ? 'validation.number' : 'validation.invalid')
    if (v.rangeUnderflow) return t('validation.min', { min: input?.min ?? '' })
    if (v.rangeOverflow) return t('validation.max', { max: input?.max ?? '' })
    if (v.stepMismatch) return t('validation.step')
    if (v.tooShort) return t('validation.min_length', { min: (el as HTMLInputElement).minLength })
    if (v.tooLong) return t('validation.max_length', { max: (el as HTMLInputElement).maxLength })
    if (v.patternMismatch) return el.title || t('validation.pattern')
    return ''
  }

  function onInvalid(e: Event) {
    const el = e.target
    if (!isField(el)) return
    // Vlastní hláška z jiného místa (setCustomValidity ve stránce) má přednost.
    if (el.validity.customError && !el.dataset.i18nValidity) return
    el.setCustomValidity('')
    const text = message(el)
    if (!text) return
    el.dataset.i18nValidity = '1'
    el.setCustomValidity(text)
  }

  function onEdit(e: Event) {
    const el = e.target
    if (!isField(el) || !el.dataset.i18nValidity) return
    delete el.dataset.i18nValidity
    el.setCustomValidity('')
  }

  onMounted(() => {
    document.addEventListener('invalid', onInvalid, true)
    document.addEventListener('input', onEdit, true)
    document.addEventListener('change', onEdit, true)
  })
  onBeforeUnmount(() => {
    document.removeEventListener('invalid', onInvalid, true)
    document.removeEventListener('input', onEdit, true)
    document.removeEventListener('change', onEdit, true)
  })
}
