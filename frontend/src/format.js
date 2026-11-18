// Gemeinsame Anzeige-Helfer für Personen (Liste, Stammbaum, Suche)
import { extractYear, normalizeGedcomDate, toGedcomDate, isValidGedcomDate, GEDCOM_MONTHS } from './gedcom.js'
import { t, locale } from './i18n/index.js'

/** „Nachname, Vorname“ inkl. Namenszusatz (von, van …) */
export function nameLabel(p) {
  if (p?.private) return t('privacy.person')   // für Besucher anonymisiert
  const surname = [p.surname_prefix, p.surname].filter(Boolean).join(' ')
  // Hauptname ohne Nachnamen: Nachnamen aus einem weiteren Namen in Klammern zeigen
  if (!surname && p.alt_surname) return `${p.given_name || '–'} (${p.alt_surname})`
  return [surname, p.given_name].filter(Boolean).join(', ') || '–'
}

/** Vorname (mit Präfix wie „Dr.“) und Nachname getrennt – für Karten */
export function nameParts(p) {
  if (p?.private) return { given: t('privacy.person'), surname: '' }
  return {
    given:   [p.name_prefix, p.given_name].filter(Boolean).join(' '),
    surname: [p.surname_prefix, p.surname].filter(Boolean).join(' ') || (p.alt_surname ? `(${p.alt_surname})` : ''),
  }
}

/** „1879 – 1952“, „* 1879“, „† 1952“ oder '' */
export function lifeSpan(p) {
  const b = extractYear(p.birth_date)
  const d = extractYear(p.death_date)
  if (b && d) return `${b} – ${d}`
  if (b)      return `* ${b}`
  if (d)      return `† ${d}`
  return ''
}

/** CSS-Klasse für die Geschlechterfarbe */
export function sexClass(p) {
  return 'sex-' + (['M', 'F', 'X'].includes(p?.sex) ? p.sex : 'U')
}

// ── Lesbare Datumsanzeige (gespeichert und bearbeitet wird weiter im GEDCOM-Format) ──

const monthName = idx => (t('date.months').split(',')[idx] ?? GEDCOM_MONTHS[idx]).trim()

/** Einzelnes GEDCOM-Datum: „8 MAR 1960“ → „8.3.1960“ (de) bzw. „8 Mar 1960“ (en); null, wenn kein einfaches Datum */
function displaySingle(d) {
  let m
  if ((m = d.match(/^(\d{1,2}) ([A-Z]{3}) (\d{1,4})$/))) {
    const idx = GEDCOM_MONTHS.indexOf(m[2])
    if (idx < 0) return null
    return locale.value === 'de' ? `${+m[1]}.${idx + 1}.${m[3]}` : `${+m[1]} ${monthName(idx).slice(0, 3)} ${m[3]}`
  }
  if ((m = d.match(/^([A-Z]{3}) (\d{1,4})$/))) {
    const idx = GEDCOM_MONTHS.indexOf(m[1])
    return idx < 0 ? null : `${monthName(idx)} ${m[2]}`
  }
  if (/^\d{1,4}$/.test(d)) return d
  return null
}

/**
 * GEDCOM-Datum lesbar machen: „FROM 1960 TO 1970“ → „1960–1970“, „ABT 1850“ → „um 1850“,
 * „8 MAR 1960“ → „8.3.1960“. Unbekanntes wird unverändert zurückgegeben.
 */
export function displayDate(raw) {
  if (!raw?.trim()) return ''
  const s = normalizeGedcomDate(raw)
  const one = displaySingle(s)
  if (one) return one

  let m
  if ((m = s.match(/^FROM (.+?) TO (.+)$/))) {
    const a = displaySingle(m[1]), b = displaySingle(m[2])
    if (a && b) return /\D/.test(a + b) ? `${a} – ${b}` : `${a}–${b}`
  }
  if ((m = s.match(/^BET (.+?) AND (.+)$/))) {
    const a = displaySingle(m[1]), b = displaySingle(m[2])
    if (a && b) return t('date.between', { a, b })
  }
  if ((m = s.match(/^(FROM|TO|ABT|EST|CAL|BEF|AFT) (.+)$/))) {
    const a = displaySingle(m[2])
    if (a) return t('date.' + m[1], { a })
  }
  // Nicht-GEDCOM-Schreibweise wie „20 6 1879“: umwandeln und dann einheitlich anzeigen
  const fixed = toGedcomDate(raw)
  if (fixed && fixed !== s && isValidGedcomDate(fixed)) return displayDate(fixed)
  return raw
}

// ── Verknüpfte Personen (ASSO) ────────────────────────────

/** Rolle lesbar: GEDCOM-Rolle übersetzt, bei „Sonstiges“ der Freitext – bekannte RELA-Texte (godson …) übersetzt */
export function assoRole(a) {
  if (a?.role && a.role !== 'OTHER') return t('person.associations.roles.' + a.role)
  const phrase = (a?.role_phrase ?? '').trim()
  if (!phrase) return a?.role ? t('person.associations.roles.OTHER') : null
  const key = 'person.associations.phrases.' + phrase.toLowerCase().replace(/[^a-z]/g, '')
  const tr  = t(key)
  return tr !== key ? tr : phrase
}

const INVERSE_ROLES   = { GODP: { role: 'OTHER', role_phrase: 'godchild' }, CHIL: { role: 'PARENT' },
                          FATH: { role: 'CHIL' }, MOTH: { role: 'CHIL' }, PARENT: { role: 'CHIL' },
                          SPOU: { role: 'SPOU' }, HUSB: { role: 'SPOU' }, WIFE: { role: 'SPOU' },
                          FRIEND: { role: 'FRIEND' }, NGHBR: { role: 'NGHBR' } }
const INVERSE_PHRASES = { godson: { role: 'GODP' }, goddaughter: { role: 'GODP' }, godchild: { role: 'GODP' },
                          son: { role: 'PARENT' }, daughter: { role: 'PARENT' }, child: { role: 'PARENT' },
                          alias: { role: 'OTHER', role_phrase: 'alias' } }   // ALIA: gegenseitig

/**
 * Rolle aus Sicht der Gegenseite: Bei A ist B als „Patensohn“ eingetragen → für B ist A „Taufpate“.
 * null, wenn sich die Rolle nicht umkehren lässt.
 */
export function inverseAssoRole(a) {
  const phrase = (a?.role_phrase ?? '').trim().toLowerCase().replace(/[^a-z]/g, '')
  const inv = a?.role && a.role !== 'OTHER' ? INVERSE_ROLES[a.role]
            : INVERSE_PHRASES[phrase] ?? null
  return inv ? assoRole(inv) : null
}

/** Verlässlichkeit einer Quellenangabe (QUAY 0–3) als Sterne, '' ohne Angabe */
export const qualityStars = q => (q === null || q === undefined || q === '') ? '' : '★'.repeat(+q) + '☆'.repeat(3 - +q)
