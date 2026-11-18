/**
 * GEDCOM 7.0 date utilities
 * Spec: https://gedcom.io/specifications/FamilySearchGEDCOMv7.html#date
 *
 * Supported forms:
 *   Exact:        1920  |  JAN 1920  |  15 JAN 1920
 *   Approximate:  ABT 1920  |  EST 15 JAN 1920  |  CAL JAN 1920
 *   Before/After: BEF 1900  |  AFT 1900
 *   Range:        BET 1900 AND 1920
 *   Period:       FROM 1900  |  TO 1920  |  FROM 1900 TO 1920
 *
 * toGedcomDate() wandelt bequeme Eingaben („8.3.1960“, „um 1850“, „1960-1970“ …) in dieses Format um.
 */

export const GEDCOM_MONTHS = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC']

const MON = `(?:${GEDCOM_MONTHS.join('|')})`
const YR  = `\\d{1,4}`
const DAY = `\\d{1,2}`

const RE_YEAR     = new RegExp(`^${YR}$`)
const RE_MON_YEAR = new RegExp(`^${MON} ${YR}$`)
const RE_FULL     = new RegExp(`^${DAY} ${MON} ${YR}$`)

function isDateValue(s) {
  return RE_YEAR.test(s) || RE_MON_YEAR.test(s) || RE_FULL.test(s)
}

/** Uppercase + collapse whitespace. */
export function normalizeGedcomDate(raw) {
  if (!raw?.trim()) return ''
  return raw.trim().toUpperCase().replace(/\s+/g, ' ')
}

/** Returns true if the string is a valid (or empty) GEDCOM date. */
export function isValidGedcomDate(raw) {
  if (!raw?.trim()) return true
  const s = normalizeGedcomDate(raw)

  if (isDateValue(s)) return true

  let m
  if ((m = s.match(/^(?:ABT|EST|CAL) (.+)$/)))           return isDateValue(m[1])
  if ((m = s.match(/^(?:BEF|AFT) (.+)$/)))               return isDateValue(m[1])
  if ((m = s.match(/^BET (.+?) AND (.+)$/)))              return isDateValue(m[1]) && isDateValue(m[2])
  if ((m = s.match(/^FROM (.+?) TO (.+)$/)))              return isDateValue(m[1]) && isDateValue(m[2])
  if ((m = s.match(/^FROM (.+)$/)))                       return isDateValue(m[1])
  if ((m = s.match(/^TO (.+)$/)))                         return isDateValue(m[1])

  return false
}

/** Extract the first 4-digit year found (for rough chronology checks). */
export function extractYear(raw) {
  if (!raw) return null
  const m = normalizeGedcomDate(raw).match(/\b(\d{3,4})\b/)
  return m ? parseInt(m[1], 10) : null
}

const RE_DATE_PART = new RegExp(`\\b(?:(${DAY}) )?(?:(${MON}) )?(\\d{3,4})\\b`, 'g')

/**
 * Vergleichbare Zahl JJJJMMTT für Sortierung und Zeitraumvergleiche (null ohne Jahr).
 * upper = true: späteste Möglichkeit (letztes Datum einer Spanne, fehlender Monat/Tag → 12/31),
 * sonst früheste (erstes Datum, fehlend → 0).
 */
export function dateKey(raw, { upper = false } = {}) {
  if (!raw) return null
  const parts = [...normalizeGedcomDate(raw).matchAll(RE_DATE_PART)]
  if (!parts.length) return null
  const [, d, m, y] = upper ? parts.at(-1) : parts[0]
  const month = m ? GEDCOM_MONTHS.indexOf(m) + 1 : (upper ? 12 : 0)
  return +y * 10000 + month * 100 + (d ? +d : (upper ? 31 : 0))
}

// ── Bequeme Eingabe → GEDCOM ───────────────────────────────

const MONTH_NAMES = {
  JAN: ['jan', 'januar', 'jänner', 'jaenner', 'january'],
  FEB: ['feb', 'februar', 'feber', 'february'],
  MAR: ['mar', 'mär', 'maer', 'mrz', 'märz', 'maerz', 'march'],
  APR: ['apr', 'april'],
  MAY: ['mai', 'may'],
  JUN: ['jun', 'juni', 'june'],
  JUL: ['jul', 'juli', 'july'],
  AUG: ['aug', 'august'],
  SEP: ['sep', 'sept', 'september'],
  OCT: ['okt', 'oct', 'oktober', 'october'],
  NOV: ['nov', 'november'],
  DEC: ['dez', 'dec', 'dezember', 'december'],
}
const MONTH_LOOKUP = Object.fromEntries(
  Object.entries(MONTH_NAMES).flatMap(([mon, names]) => names.map(n => [n, mon])))

const isNum = t => /^\d+$/.test(t)
const monthOf = t => isNum(t) ? (+t >= 1 && +t <= 12 ? GEDCOM_MONTHS[+t - 1] : null) : (MONTH_LOOKUP[t] ?? null)
const yearOf  = t => /^\d{3,4}$/.test(t) ? String(+t) : null
const MAX_DAYS = { FEB: 29, APR: 30, JUN: 30, SEP: 30, NOV: 30 }
// Tag nur, wenn es ihn in diesem Monat gibt (31.2. wird nicht umgewandelt)
const dayOf   = (t, mon) => isNum(t) && +t >= 1 && +t <= (MAX_DAYS[mon] ?? 31) ? String(+t) : null

/** Einzelnes Datum: „8.3.1960“, „8 3 1960“, „1960-03-08“, „8. März 1960“, „3/1960“, „1960“ → GEDCOM oder null */
function parseSingleDate(raw) {
  const tokens = raw.toLowerCase().replace(/(\d)\.(?=\s|$)/g, '$1').split(/[\s./-]+/).filter(Boolean)
  if (tokens.length === 1) {
    return yearOf(tokens[0])
  }
  if (tokens.length === 2) {
    const [a, b] = tokens
    if (yearOf(b) && monthOf(a)) return `${monthOf(a)} ${yearOf(b)}`          // 3.1960, März 1960
    if (/^\d{4}$/.test(a) && monthOf(b)) return `${monthOf(b)} ${yearOf(a)}` // 1960-03
    return null
  }
  if (tokens.length === 3) {
    const [a, b, c] = tokens
    const full = (d, mon, y) => mon && y && dayOf(d, mon) ? `${dayOf(d, mon)} ${mon} ${y}` : null
    if (/^\d{4}$/.test(a)) return full(c, monthOf(b), yearOf(a))          // 1960-03-08
    if (!isNum(a))          return full(b, monthOf(a), yearOf(c))          // März 8 1960
    return full(a, monthOf(b), yearOf(c))                                // 8.3.1960, 8. März 1960
  }
  return null
}

const QUALIFIERS = [
  [/^(?:zwischen|between|bet\.?)\s+(.+?)\s+(?:und|and|bis|-)\s+(.+)$/i, (a, b) => `BET ${a} AND ${b}`],
  [/^(?:von|vom|from)\s+(.+?)\s+(?:bis|to|-)\s+(.+)$/i,                   (a, b) => `FROM ${a} TO ${b}`],
  [/^(?:um|ca\.?|circa|etwa|ungefähr|ungef\.?|abt\.?|about|approx\.?|~)\s*(.+)$/i, a => `ABT ${a}`],
  [/^(?:geschätzt|geschaetzt|gesch\.|est\.?|estimated)\s+(.+)$/i,          a => `EST ${a}`],
  [/^(?:berechnet|ber\.|cal\.?|calculated)\s+(.+)$/i,                      a => `CAL ${a}`],
  [/^(?:vor|before|bef\.?|<)\s*(.+)$/i,                                     a => `BEF ${a}`],
  [/^(?:nach|after|aft\.?|>)\s*(.+)$/i,                                     a => `AFT ${a}`],
  [/^(?:ab|seit|from|von|vom)\s+(.+)$/i,                                     a => `FROM ${a}`],
  [/^(?:bis|to|until)\s+(.+)$/i,                                             a => `TO ${a}`],
]

/**
 * Bequeme Datumseingabe in GEDCOM umwandeln.
 *   period = true: „1960-1970“ ist ein Zeitraum (FROM … TO …), z. B. bei Beruf oder Wohnort;
 *   sonst eine unsichere Angabe (BET … AND …), z. B. bei Geburt oder Heirat.
 * Bereits gültige GEDCOM-Angaben bleiben unverändert; Unerkanntes wird nur vereinheitlicht.
 */
export function toGedcomDate(raw, { period = false } = {}) {
  if (!raw?.trim()) return ''
  const normalized = normalizeGedcomDate(raw)
  if (isValidGedcomDate(normalized)) return normalized

  const input = raw.trim().replace(/[–—‑]/g, '-').replace(/\s+/g, ' ')

  for (const [re, build] of QUALIFIERS) {
    const m = input.match(re)
    if (!m) continue
    const parts = m.slice(1).map(parseSingleDate)
    if (parts.every(Boolean)) return build(...parts)
  }

  const single = parseSingleDate(input)
  if (single) return single

  // Bereich ohne Wörter: „1960-1970“, „8.3.1960 - 10.5.1970“, „1960 bis 1970“
  const range = input.match(/^(.+?)\s*(?:-|bis|to)\s*(.+)$/i)
  if (range) {
    // bei Bindestrichen innerhalb der Daten (ISO) die Trennstelle mit Leerzeichen bevorzugen
    const spaced = input.match(/^(.+?)\s+(?:-|bis|to)\s+(.+)$/i)
    for (const m of [spaced, range].filter(Boolean)) {
      const a = parseSingleDate(m[1]), b = parseSingleDate(m[2])
      if (a && b) return period ? `FROM ${a} TO ${b}` : `BET ${a} AND ${b}`
    }
  }

  return normalized
}
