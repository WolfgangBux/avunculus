// Gemeinsame Bausteine der Druckansichten
import { ref, onMounted } from 'vue'
import { displayDate, nameLabel, lifeSpan } from '../../format.js'
import { t } from '../../i18n/index.js'

/** Genealogische Zeichen der Kurzdaten */
export const SYMBOLS = { birth: '*', baptism: '~', marriage: '⚭', death: '†', burial: '▭' }

/** „8.3.1960 in Ingolstadt“ – '' ohne Datum und Ort */
export function datePlace(date, place) { return datePlaceFull(date, place) }
function datePlaceFull(date, place) {
  const d = date ? displayDate(date) : ''
  if (d && place) return t('reports.datePlace', { date: d, place })
  return d || place || ''
}

/**
 * Kurzdaten einer Person aus dem Bericht (Fakten aus ReportController::facts):
 * [{ key, symbol, label, text }] – nur Ausgefülltes, Berufe zusammengefasst.
 */
export function factLines(p, { marriage = null, shortPlaces = false } = {}) {
  if (!p || p.private) return []
  const out = []
  // kurz: nur der Ort selbst („Wiesent“ statt „Wiesent, Wiesent, Regensburg, Oberpfalz …“)
  const datePlace = shortPlaces ? (d, pl) => datePlaceFull(d, pl?.split(',')[0].trim()) : datePlaceFull
  const add = (key, text, symbol = SYMBOLS[key] ?? '') => { if (text) out.push({ key, symbol, label: t('reports.facts.' + key), text }) }
  add('birth',    datePlace(p.birth_date, p.birth_place))
  add('baptism',  p.baptism && datePlace(p.baptism.date, p.baptism.place))
  add('marriage', marriage && datePlace(marriage.date, marriage.place))
  add('death',    datePlace(p.death_date, p.death_place))
  if (p.burial) add(p.burial.type === 'CREM' ? 'cremation' : 'burial', datePlace(p.burial.date, p.burial.place), SYMBOLS.burial)
  add('occupation', p.occupations?.join(', '))
  return out
}

/** „Huber, Maria (1879 – 1952)“ */
export function nameWithSpan(p) {
  if (!p) return '–'
  const span = p.private ? '' : lifeSpan(p)
  return span ? `${nameLabel(p)} (${span})` : nameLabel(p)
}

export { nameLabel, lifeSpan, displayDate }

/** 1 → „I“, 14 → „XIV“ (Generationen der Ahnenliste) */
export function roman(n) {
  const digits = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'],
                  [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']]
  let out = ''
  for (const [v, s] of digits) while (n >= v) { out += s; n -= v }
  return out
}

/** Filter einer Liste als Text: „1800–1850 · Ort: Ingolstadt“ */
export function filterText({ from, to, place, surname } = {}) {
  const parts = []
  if (from && to) parts.push(`${from}–${to}`)
  else if (from)  parts.push(t('reports.fromYear', { y: from }))
  else if (to)    parts.push(t('reports.toYear', { y: to }))
  if (place)   parts.push(t('reports.placeFilter', { place }))
  if (surname) parts.push(t('reports.surnameFilter', { name: surname }))
  return parts.join(' · ')
}

/** Daten eines Berichts laden: { data, error } als Refs (data bleibt null bis geladen) */
export function useReportData(loader) {
  const data  = ref(null)
  const error = ref(false)
  onMounted(async () => {
    try { data.value = await loader() } catch { error.value = true }
  })
  return { data, error }
}
