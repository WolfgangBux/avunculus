import { ref } from 'vue'
import { currentTreeId } from '../trees.js'
import { locale } from '../i18n/index.js'

/** Berichte, die eine Person bzw. Familie brauchen, und die Listen */
export const PERSON_REPORTS = ['ancestors', 'pedigree', 'descendants', 'person']
export const FAMILY_REPORTS = ['family']
export const LIST_REPORTS   = ['familybook', 'births', 'baptisms', 'deaths', 'marriages', 'occupations', 'burials']
export const REPORT_KINDS   = [...PERSON_REPORTS, ...FAMILY_REPORTS, ...LIST_REPORTS]

/** Generationen: Standard und Höchstwert (wie im Backend, ReportController) */
export const GENERATIONS = { ancestors: { def: 5, max: 12 }, pedigree: { def: 5, max: 6 }, descendants: { def: 4, max: 10 } }

/** Berichtsdialog für die ganze App (in App.vue eingebunden) */
export const showReports = ref(false)
/** Vorbelegung beim Öffnen: { kind, person, family } */
export const reportStart = ref({ kind: 'ancestors', person: null, family: null })

export function openReports(kind = 'ancestors', { person = null, family = null } = {}) {
  reportStart.value = { kind, person: person ? Number(person) : null, family: family ? Number(family) : null }
  showReports.value = true
}

/**
 * Adresse der Druckansicht: #/report/<Art>?tree=…&lang=…&… – öffnet in einem neuen Tab die
 * Berichtsseite statt der App (main.js). Nur über # wie die App, damit es auch im Unterordner läuft.
 */
export function reportUrl(kind, params = {}) {
  const q = new URLSearchParams({ tree: String(currentTreeId.value), lang: locale.value })
  for (const [k, v] of Object.entries(params)) if (v !== '' && v != null) q.set(k, String(v))
  return `${location.pathname}#/report/${kind}?${q}`
}

/** Bericht der aktuellen Adresse (Berichtsseite) oder null */
export function parseReportHash(hash = location.hash) {
  const m = hash.match(/^#\/report\/([a-z]+)(?:\?(.*))?$/)
  if (!m || !REPORT_KINDS.includes(m[1])) return null
  return { kind: m[1], params: Object.fromEntries(new URLSearchParams(m[2] ?? '')) }
}
