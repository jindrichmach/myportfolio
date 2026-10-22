import { createI18n } from 'vue-i18n'
import cs from './cs.json'
import en from './en.json'

export const i18n = createI18n({
  legacy: false,
  locale: localStorage.getItem('locale') || 'cs',
  fallbackLocale: 'cs',
  messages: { cs, en },
})
