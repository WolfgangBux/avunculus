// Personenformular: Konstanten, leere Einträge und Umwandlung zwischen API-Daten und Formularzustand
import { t } from './i18n/index.js'
import { normalizeGedcomDate, isValidGedcomDate, toGedcomDate } from './gedcom.js'
import { displayDate } from './format.js'

export const NAME_TYPE_VALUES  = ['BIRTH','MARRIED','AKA','IMMIGRANT','FORMAL','PROFESSIONAL','RELIGIOUS']
// muss zu GedcomTags (Backend) passen
export const EVENT_TYPE_VALUES = ['BIRT','CHR','BAPM','CHRA','FCOM','CONF','BARM','BASM','BLES','ADOP',
                                  'GRAD','ORDN','CENS','RESI','EMIG','IMMI','NATU','RETI',
                                  'DEAT','BURI','CREM','PROB','WILL','EVEN']
export const ATTRIBUTE_VALUES  = ['OCCU','EDUC','RELI','TITL','NATI','DSCR','PROP','IDNO','SSN','CAST','NCHI','NMR','FACT']
export const RESTRICTION_VALUES = ['CONFIDENTIAL', 'LOCKED', 'PRIVACY']
export const ROLE_VALUES       = ['WITN','GODP','CLERGY','OFFICIATOR','HUSB','WIFE','SPOU',
                                  'FATH','MOTH','PARENT','CHIL','FRIEND','NGHBR','MULTIPLE','OTHER']
export const SEX_SYMBOLS       = { M: '♂', F: '♀', X: '⚧', U: '?' }
// Typen, bei denen GEDCOM eine genauere Art (TYPE) vorsieht
const DESCRIPTOR_TYPES = ['EVEN', 'FACT', 'IDNO']
const DETAIL_FIELDS    = ['event_time', 'age', 'cause', 'agency', 'religion', 'notes']

// Genealogische Zeichen für die wichtigsten Ereignisse, sonst ein Symbol
const EVENT_SYMBOLS = { BIRT: '*', BAPM: '~', CHR: '~', CHRA: '~', DEAT: '†', BURI: '▭', CREM: '▭' }
const EVENT_ICONS   = { OCCU: 'pi-briefcase', EDUC: 'pi-graduation-cap', RELI: 'pi-book', TITL: 'pi-id-card',
                        NATI: 'pi-flag', PROP: 'pi-home', RESI: 'pi-home', IDNO: 'pi-id-card', SSN: 'pi-id-card',
                        EMIG: 'pi-globe', IMMI: 'pi-globe', NATU: 'pi-flag', FCOM: 'pi-sun', CONF: 'pi-sun' }

// ── Leere Einträge ─────────────────────────────────────────────
export const blankName = () => ({
  name_type: 'BIRTH', name_prefix: '', given_name: '',
  nickname: '', surname_prefix: '', surname: '', name_suffix: '',
})
export const blankCitation    = () => ({ source_id: '', source_label: '', page: '', transcription: '', url: '', quality: null, media: [] })
export const blankAssociation = () => ({ person_id: '', person_label: '', role: '', role_phrase: '' })
export const blankEvent       = (type = 'BIRT') => ({
  event_type: type, event_date: '', place_id: null, event_place: '',
  event_value: '', event_descriptor: '', age: '', cause: '', agency: '', religion: '', event_time: '', notes: '',
  citations: [], associations: [], media: [],
})

// ── Ereignistypen ──────────────────────────────────────────────
export const isAttribute   = type => ATTRIBUTE_VALUES.includes(type)
// Bei Merkmalen und Wohnort ist „1960-1970“ ein Zeitraum (FROM/TO), sonst eine unsichere Angabe (BET/AND)
export const isPeriodType  = type => isAttribute(type) || type === 'RESI'
export const hasValueField = type => isAttribute(type) || type === 'EVEN'
export const hasDescriptor = type => DESCRIPTOR_TYPES.includes(type)
export const hasDetails    = evt => DETAIL_FIELDS.some(f => evt[f])
export const eventSymbol   = type => EVENT_SYMBOLS[type] ?? ''
export const eventIcon     = type => EVENT_ICONS[type] ?? (isAttribute(type) ? 'pi-tag' : 'pi-calendar')
export const eventTitle    = evt => (hasDescriptor(evt.event_type) && evt.event_descriptor) || t('person.eventTypes.' + evt.event_type)

/** Datum eines Ereignisses in GEDCOM-Form bringen (beim Verlassen des Feldes und vor dem Speichern) */
export function normalizeEventDate(evt) {
  evt.event_date = toGedcomDate(evt.event_date, { period: isPeriodType(evt.event_type) })
}

/** Vorschau beim Tippen: was wird aus der Eingabe? ('' wenn nichts umzuwandeln ist) */
export function datePreview(evt) {
  if (!evt.event_date?.trim()) return ''
  const out = toGedcomDate(evt.event_date, { period: isPeriodType(evt.event_type) })
  return out !== normalizeGedcomDate(evt.event_date) && isValidGedcomDate(out) ? out : ''
}

export const isFilledCitation = c => c.source_id || c.page || c.transcription || c.url || c.media?.length
export const filledCitations  = list => list.filter(isFilledCitation)

/** Felder der Leseansicht eines Ereignisses – nur ausgefüllte */
export function viewFields(evt) {
  const f = (key, value) => value ? [{ key, label: t('person.eventFields.' + key), value }] : []
  return [
    ...(hasDescriptor(evt.event_type) && evt.event_descriptor ? f('descriptor', evt.event_descriptor) : []),
    ...(hasValueField(evt.event_type) ? f(evt.event_type === 'EVEN' ? 'description' : 'value', evt.event_value) : []),
    ...f('dateLabel', evt.event_date && displayDate(evt.event_date) + (evt.event_time ? ', ' + evt.event_time : '')),
    ...f('placeLabel', evt.event_place),
    ...f('age', evt.age), ...f('cause', evt.cause), ...f('agency', evt.agency),
    ...(evt.event_type !== 'RELI' ? f('religion', evt.religion) : []),
    ...f('notes', evt.notes),
  ]
}

// ── Namen ──────────────────────────────────────────────────────
export function fullName(n) {
  return [n.name_prefix, n.given_name, n.nickname ? `„${n.nickname}“` : null,
          n.surname_prefix, n.surname, n.name_suffix].filter(Boolean).join(' ')
}

/** „Nachname, Vorname“ für Meldungen */
export function listName(person) {
  const n = person.names?.length ? person.names[0] : person
  return [[n.surname_prefix, n.surname].filter(Boolean).join(' '), n.given_name]
    .filter(Boolean).join(', ') || '(Unbekannt)'
}

// ── API → Formular ─────────────────────────────────────────────
// Ereignisse, Namen, Quellenangaben und Verknüpfungen tragen ihre ID (neue: keine bzw. null).
// Das Backend gleicht damit ab: vorhandene Zeilen behalten ihre ID (Verweise, Änderungshistorie).
const str      = v => v ?? ''
// crop: Ausschnitt der Person auf dem Bild (nur Anzeige; gesetzt wird er über media/{id}/region, nicht mit der Person)
const mediaItem = m => ({ id: m.id, title: m.title ?? '', mime_type: m.mime_type, is_primary: !!m.is_primary, crop: m.crop ?? null })
/** Medien einer Quellenangabe (Scan des zitierten Eintrags) – für alle Formulare mit Quellenangaben */
// crop: Bereich, den die Transkription wiedergibt (gespeichert mit der Quellenangabe)
export const citationMediaFromApi = c => (c.media ?? []).map(m => ({ id: m.id, title: m.title ?? '', mime_type: m.mime_type, crop: m.crop ?? null }))
const citationMediaToApi = list => (list ?? []).map(m => ({ id: m.id, crop: m.crop ?? null }))
/** Quellenangabe zum Speichern: Medien nur mit ID und markiertem Bereich */
export const citationToApi = c => ({ ...c, media: citationMediaToApi(c.media) })
const citationFromApi = c => ({
  id: c.id ?? null,
  source_id: str(c.source_id), source_label: c.source_abbreviation || c.source_title || '', page: str(c.page), transcription: str(c.transcription), url: str(c.url),
  quality: c.quality ?? null,
  media: citationMediaFromApi(c),
})

/** Formularzustand aus einer Person der API (null = neue, leere Person) */
export function personFromApi(person) {
  if (!person) {
    return { sex: '', restriction: [], notes: '', names: [blankName()], events: [blankEvent()],
             citations: [], associations: [], media: [] }
  }
  return {
    sex:         person.sex ?? 'U',
    restriction: (person.restriction ?? '').split(',').map(x => x.trim()).filter(Boolean),
    notes:       str(person.notes),
    media:       (person.media ?? []).map(mediaItem),
    citations:   (person.citations ?? []).map(citationFromApi),
    associations: (person.associations ?? []).map(a => ({
      id: a.id ?? null, person_id: str(a.person_id), person_label: str(a.display_name), role: str(a.role),
      role_phrase: str(a.role_phrase), notes: str(a.notes),
    })),
    names: (person.names ?? []).length
      ? person.names.map(n => ({
          id: n.id ?? null,
          name_type: n.name_type ?? 'BIRTH', name_prefix: str(n.name_prefix), given_name: str(n.given_name),
          nickname: str(n.nickname), surname_prefix: str(n.surname_prefix), surname: str(n.surname),
          name_suffix: str(n.name_suffix),
        }))
      : [blankName()],
    events: (person.events ?? []).length
      ? person.events.map(e => ({
          id: e.id ?? null,
          event_type: e.event_type ?? 'EVEN', event_date: str(e.event_date), place_id: e.place_id ?? null,
          event_place: str(e.event_place), event_value: str(e.event_value), event_descriptor: str(e.event_descriptor),
          age: str(e.age), cause: str(e.cause), agency: str(e.agency), religion: str(e.religion),
          event_time: str(e.event_time), notes: str(e.notes),
          citations: (e.citations ?? []).map(citationFromApi),
          media: (e.media ?? []).map(mediaItem),
          associations: (e.associations ?? []).map(a => ({
            id: a.id ?? null, person_id: str(a.person_id), person_label: str(a.display_name), role: str(a.role), role_phrase: str(a.role_phrase),
          })),
        }))
      : [blankEvent()],
  }
}

// ── Formular → API ─────────────────────────────────────────────
const hasPerson = a => a.person_id !== '' && a.person_id !== null
const associationsToApi = (list, withNotes) => list
  .filter(hasPerson)
  .map(a => ({
    id:          a.id ?? null,
    person_id:   Number(a.person_id),
    role:        a.role || null,
    role_phrase: a.role === 'OTHER' ? (a.role_phrase.trim() || null) : null,
    ...(withNotes ? { notes: a.notes.trim() || null } : {}),
  }))

/** Daten zum Speichern (POST/PUT /persons); Daten in GEDCOM-Form, auch wenn das Feld noch nicht verlassen wurde */
export function personToApi(s) {
  return {
    sex:          s.sex,
    restriction:  s.restriction.join(', '),
    citations:    filledCitations(s.citations).map(citationToApi),
    media:        s.media.map(m => ({ id: m.id, is_primary: m.is_primary })),
    associations: associationsToApi(s.associations, true),
    notes:        s.notes,
    names:        s.names.map(n => ({ ...n })),
    events: s.events.map(e => ({
      id:               e.id ?? null,
      event_type:       e.event_type,
      event_date:       toGedcomDate(e.event_date, { period: isPeriodType(e.event_type) }),
      place_id:         e.place_id ?? null,
      event_place:      e.event_place,
      event_value:      hasValueField(e.event_type) ? e.event_value : '',
      event_descriptor: hasDescriptor(e.event_type) ? e.event_descriptor : '',
      age:        e.age,
      cause:      e.cause,
      agency:     e.agency,
      religion:   e.event_type === 'RELI' ? '' : e.religion,
      event_time: e.event_date ? e.event_time : '',
      notes:      e.notes,
      citations: filledCitations(e.citations).map(c => ({
        id:            c.id ?? null,
        source_id:     c.source_id !== '' ? c.source_id : null,
        page:          c.page.trim()          || null,
        transcription: c.transcription.trim() || null,
        url:           c.url.trim()           || null,
        quality:       c.quality ?? null,
        media:         citationMediaToApi(c.media),
      })),
      media:        e.media.map(m => ({ id: m.id })),
      associations: associationsToApi(e.associations, false),
    })),
  }
}

// ── IDs neuer Zeilen nach dem Speichern übernehmen ─────────────
/** Die Zeilen des Formulars, die personToApi() sendet – in derselben Reihenfolge */
export function sentRows(s) {
  return {
    names:        [...s.names],
    citations:    filledCitations(s.citations),
    associations: s.associations.filter(hasPerson),
    events: s.events.map(e => ({
      row: e, citations: filledCitations(e.citations), associations: e.associations.filter(hasPerson),
    })),
  }
}

/**
 * IDs aus der gespeicherten Person (Antwort des Servers) in die gesendeten Formularzeilen und in die
 * gesendeten Daten übernehmen, damit die nächste Speicherung dieselben Zeilen aktualisiert statt neue
 * anzulegen. false, wenn der Server Zeilen übergangen hat (dann passt die Zuordnung nicht).
 */
export function assignIds(rows, data, person) {
  // Ereignisse kommen chronologisch sortiert zurück – sort_order ist die gesendete Reihenfolge
  const events = [...(person.events ?? [])].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
  const lists = [
    [rows.names, data.names, person.names],
    [rows.citations, data.citations, person.citations],
    [rows.associations, data.associations, person.associations],
    [rows.events.map(e => e.row), data.events, events],
  ]
  rows.events.forEach((e, i) => {
    const saved = events[i]
    lists.push([e.citations, data.events[i].citations, saved?.citations],
               [e.associations, data.events[i].associations, saved?.associations])
  })
  if (!lists.every(([form, , saved]) => form.length === (saved ?? []).length)) return false
  for (const [form, sent, saved] of lists) {
    form.forEach((row, i) => { row.id = sent[i].id = saved[i].id })
  }
  return true
}
