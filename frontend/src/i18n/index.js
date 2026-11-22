import { ref } from 'vue'
import de from './de.js'
import en from './en.js'

const LOCALES = { de, en }

export const locale = ref('de')

export function setLocale(lang) {
  if (LOCALES[lang]) locale.value = lang
}

export function t(key, params) {
  const msgs = LOCALES[locale.value] ?? LOCALES.de
  const val  = key.split('.').reduce((o, k) => (o != null ? o[k] : undefined), msgs)
  if (typeof val !== 'string') return key
  if (!params) return val
  return val.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? `{{${k}}}`))
}

export const availableLocales = [
  { value: 'de', label: 'DE', name: 'Deutsch' },
  { value: 'en', label: 'EN', name: 'English' },
]
