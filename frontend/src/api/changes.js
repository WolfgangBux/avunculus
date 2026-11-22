import { request } from './http.js'
import { t, locale } from '../i18n/index.js'

/**
 * Änderungsprotokoll des aktuellen Baums (nur Bearbeiter): { total, items, users? } – neueste zuerst.
 *   type/id: nur ein Datensatz; user: nur ein Benutzer; q: Suche in der Bezeichnung
 */
export function fetchChanges({ q = '', type = '', id = null, user = null, limit = 50, offset = 0 } = {}) {
  const params = new URLSearchParams({ q, type, limit: String(limit), offset: String(offset) })
  if (id)   params.set('id', String(id))
  if (user) params.set('user', String(user))
  return request('GET', `/changes?${params}`)
}

/** Ansicht (Tab) zu einem Datensatztyp, für den Sprung per Adresse #/<Baum>/<Ansicht>/<ID> */
export const CHANGE_VIEWS = {
  person: 'persons', family: 'families', source: 'sources', repository: 'repositories', place: 'places', media: 'media',
}
export const CHANGE_TYPES = ['person', 'family', 'source', 'repository', 'place', 'media', 'tree']

/** Zeitpunkt eines Eintrags (Server liefert „YYYY-MM-DD HH:MM:SS“) */
export function changeTime(s) {
  const d = new Date(String(s).replace(' ', 'T'))
  return isNaN(d) ? '' : d.toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
}

/** Was getan wurde, als kurzer Text, z. B. „Kind hinzugefügt: Anna Huber“ */
export function changeAction(c) {
  const d   = c.details ?? {}
  const key = 'changes.actions.' + c.action + (c.action === 'region' && d.removed ? 'Removed' : '')
  let text  = t(key)
  if (text === key) text = c.action           // unbekannte (neue) Aktion: Name aus routes.php
  const extra = []
  if (d.event_type) extra.push(t('family.eventTypes.' + d.event_type))
  if (d.person)     extra.push(d.role ? `${d.person} (${t('person.associations.roles.' + d.role)})` : d.person)
  if (d.target)     extra.push('→ ' + d.target)
  if (d.from_tree)  extra.push(t('changes.fromTree', { name: d.from_tree }))
  if (d.updated != null) extra.push(t('changes.count', { n: d.updated }))
  if (d.deleted != null) extra.push(t('changes.count', { n: d.deleted }))
  return extra.length ? `${text}: ${extra.join(' · ')}` : text
}

/** Import-Statistik als „123 Personen · 45 Familien …“ (nur Werte > 0) */
export function changeStats(c) {
  const s = c.details?.stats
  if (!s) return ''
  return Object.entries(s)
    .filter(([k, n]) => typeof n === 'number' && n > 0 && t('gedcom.stats.' + k) !== 'gedcom.stats.' + k)
    .map(([k, n]) => `${n.toLocaleString(locale.value)} ${t('gedcom.stats.' + k)}`)
    .join(' · ')
}

/** Übersetzung mit Rückfall auf den Schlüssel selbst (neue Felder erscheinen dann roh statt leer) */
const tr = (key, fallback) => { const v = t(key); return v === key ? fallback : v }

/**
 * Geänderte Felder eines Eintrags (details.changes, siehe backend/lib/ChangeDiff.php) zur Anzeige:
 * [{ label, kind: 'added'|'removed'|'changed', old, new }]
 */
export function changeFields(c) {
  return (c.details?.changes ?? []).map(ch => {
    if (ch.l === 'more') return { label: t('changes.more', { n: ch.n }), kind: 'more' }
    const [l, type] = ch.l.split(':')
    let label = l === 'event'  ? tr('person.eventTypes.' + type, type)
              : l === 'fevent' ? tr('family.eventTypes.' + type, type)
              : tr('changes.fields.' + l, l)
    if (ch.s) label += ' – ' + tr('changes.parts.' + ch.s, ch.s)
    const val = v => (v != null && l === 'sex') ? tr('sex.' + v, v) : v
    return {
      label,
      kind: ch.o == null ? 'added' : ch.n == null ? 'removed' : 'changed',
      old: val(ch.o), new: val(ch.n),
    }
  })
}
