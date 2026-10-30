import { createI18n } from 'vue-i18n'
import cs from './cs.json'
import en from './en.json'

export const i18n = createI18n({
  legacy: false,
  locale: localStorage.getItem('locale') || 'cs',
  fallbackLocale: 'cs',
  messages: { cs, en },
  // Čeština: 4 varianty zprávy podle počtu — 0 | 1 | 2–4 | 5+ („Zatím prázdné | {n} pozice | {n} pozice | {n} pozic“).
  // Angličtina zůstává na výchozím pravidle (0 | 1 | víc).
  pluralRules: {
    cs: (n: number) => (n === 0 ? 0 : n === 1 ? 1 : n >= 2 && n <= 4 ? 2 : 3),
  },
})
