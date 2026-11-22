// Bezeichnungen für Verwandtschaften (Verwandtschaftsrechner). Die Struktur kommt vom Server (Kinship.php):
// ga = Generationen von der Person hinauf zum gemeinsamen Vorfahren, gb = Generationen vom Gegenüber.
// Die Wortbildung unterscheidet sich je Sprache zu stark für einzelne Übersetzungstexte.
import { t, locale } from './i18n/index.js'

const pick = (sex, m, f, n) => (sex === 'M' ? m : sex === 'F' ? f : n)

// ── Deutsch ───────────────────────────────────────────────

/** „“, „Groß“, „Urgroß“, „Ururgroß“, „Urururgroß“, „4×Urgroß“ … für k Generationen über dem Grundwort */
function deGreat(k) {
  if (k <= 0) return ''
  if (k === 1) return 'Groß'
  return (k <= 4 ? 'Ur' + 'ur'.repeat(k - 2) : `${k - 1}×Ur`) + 'groß'
}
/** „Ur“ vor Enkel: Urenkel, Ururenkel, 4×Urenkel … */
const deUr = k => (k <= 0 ? '' : k <= 3 ? 'Ur' + 'ur'.repeat(k - 1) : `${k}×Ur`)

/** Präfix + Grundwort als ein Wort: „Groß“ + „Onkel“ → „Großonkel“ */
const join = (prefix, word) => (prefix ? prefix + word.toLowerCase() : word)

function deTerm(ga, gb, sex, half) {
  const h = w => (half ? join('Halb', w) : w)
  // unbekanntes Geschlecht: „Großonkel/Großtante“
  const both = (pre, m, f) => pick(sex, h(join(pre, m)), h(join(pre, f)), `${h(join(pre, m))}/${h(join(pre, f))}`)
  const grade = n => (n > 1 ? ` ${n}. Grades` : '')

  if (ga === 0) {   // Vorfahr
    if (gb === 1) return pick(sex, 'Vater', 'Mutter', 'Elternteil')
    const pre = deGreat(gb - 1)
    return pick(sex, `${pre}vater`, `${pre}mutter`, `${pre}elternteil`)
  }
  if (gb === 0) {   // Nachkomme
    if (ga === 1) return pick(sex, 'Sohn', 'Tochter', 'Kind')
    const pre = deUr(ga - 2)
    return pick(sex, join(pre, 'Enkel'), join(pre, 'Enkelin'), join(pre, 'Enkelkind'))
  }
  if (ga === 1 && gb === 1) return h(pick(sex, 'Bruder', 'Schwester', 'Geschwister'))
  if (ga === gb)    return both('', 'Cousin', 'Cousine') + grade(ga - 1)
  if (ga < gb)      return both(deGreat(gb - ga - 1), 'Onkel', 'Tante') + grade(ga)
  return both(deGreat(ga - gb - 1), 'Neffe', 'Nichte') + grade(gb)
}

// ── Englisch ──────────────────────────────────────────────

const enGreat = k => (k <= 0 ? '' : k <= 2 ? 'great-'.repeat(k) : `${k}× great-`)
const ORDINALS = ['', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth']
const enOrdinal = n => ORDINALS[n] ?? `${n}th`
const enTimes   = n => (n === 1 ? 'once' : n === 2 ? 'twice' : `${n} times`)

function enTerm(ga, gb, sex, half) {
  const h = w => (half ? 'half-' + w : w)
  if (ga === 0) return gb === 1 ? pick(sex, 'father', 'mother', 'parent')
    : enGreat(gb - 2) + pick(sex, 'grandfather', 'grandmother', 'grandparent')
  if (gb === 0) return ga === 1 ? pick(sex, 'son', 'daughter', 'child')
    : enGreat(ga - 2) + pick(sex, 'grandson', 'granddaughter', 'grandchild')
  if (ga === 1 && gb === 1) return h(pick(sex, 'brother', 'sister', 'sibling'))
  if (ga === 1) return h(enGreat(gb - 2) + pick(sex, 'uncle', 'aunt', 'uncle/aunt'))
  if (gb === 1) return h(enGreat(ga - 2) + pick(sex, 'nephew', 'niece', 'nephew/niece'))
  const removed = Math.abs(ga - gb)
  return (half ? 'half ' : '') + `${enOrdinal(Math.min(ga, gb) - 1)} cousin` + (removed ? ` ${enTimes(removed)} removed` : '')
}

// ── Öffentlich ────────────────────────────────────────────

/** Was ist eine Person (Geschlecht sex) für ihr Gegenüber? z. B. „Großonkel 2. Grades“ */
export function bloodTerm(ga, gb, sex, half = false) {
  return (locale.value === 'en' ? enTerm : deTerm)(ga, gb, sex, half)
}

/** Ehemann / Ehefrau / Ehepartner */
export const partnerTerm = sex => pick(sex, t('kinship.husband'), t('kinship.wife'), t('kinship.spouse'))

/**
 * Bezeichnung für eine Verwandtschaft aus Sicht von A (Geschlecht sex), wie sie der Server liefert.
 * Für inlaw: side 'b' – A ist (ga, gb) zu Bs Partner; side 'a' – A ist Partner von via, via ist (ga, gb) zu B.
 */
export function relationTerm(rel, sex) {
  switch (rel.type) {
    case 'same':    return t('kinship.same')
    case 'blood':   return bloodTerm(rel.ga, rel.gb, sex, rel.half)
    case 'partner': return partnerTerm(sex)
    case 'path':    return t('kinship.connected')
    case 'inlaw':   return inlawTerm(rel, sex)
  }
  return ''
}

// Feste Wörter für die häufigsten Verschwägerungen: Schlüssel side:ga:gb
const INLAW = {
  'b:0:1': 'parentInLaw',      // Vater des Partners
  'b:0:2': 'grandparentInLaw',
  'b:1:1': 'siblingInLaw',     // Bruder des Partners
  'b:1:0': 'stepchild',        // Kind des Partners
  'b:2:0': 'stepGrandchild',
  'a:0:1': 'stepparent',       // Partner der Mutter
  'a:0:2': 'stepGrandparent',
  'a:1:1': 'siblingInLaw',     // Partner der Schwester
  'a:1:0': 'childInLaw',       // Partner der Tochter
}

function inlawTerm(rel, sex) {
  const key = INLAW[`${rel.side}:${rel.ga}:${rel.gb}`]
  if (key) return pick(sex, t(`kinship.${key}.m`), t(`kinship.${key}.f`), t(`kinship.${key}.n`))
  return t('kinship.byMarriage', { term: bloodTerm(rel.ga, rel.gb, sex, rel.half) })
}

/** Dieselbe Verwandtschaft aus Sicht von B */
export function reverseRelation(rel) {
  if (rel.type === 'blood') return { ...rel, ga: rel.gb, gb: rel.ga }
  if (rel.type === 'inlaw') return { ...rel, ga: rel.gb, gb: rel.ga, side: rel.side === 'a' ? 'b' : 'a' }
  return rel
}

/** Bezeichnung eines Schritts im Weg: Vater, Tochter, Ehefrau … (was die Person für die vorige ist) */
export function stepTerm(step, sex) {
  if (step.rel === 'parent')  return bloodTerm(0, 1, sex)
  if (step.rel === 'child')   return bloodTerm(1, 0, sex)
  if (step.rel === 'partner') return partnerTerm(sex)
  return ''
}
