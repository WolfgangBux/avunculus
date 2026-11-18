import { ref, computed, watch } from 'vue'
import { fetchPersonFamilies } from '../api/families.js'
import { dateKey, extractYear } from '../gedcom.js'
import { t } from '../i18n/index.js'

const STORAGE_KEY = 'avunculus.showRelativeEvents'
const readPref = () => { try { return localStorage.getItem(STORAGE_KEY) === '1' } catch { return false } }

// Genealogische Zeichen; sonst Herz (Familie) bzw. Kalender
const SYMBOLS = { BIRT: '*', DEAT: '†', MARR: '⚭', DIV: '⚮' }

// Wie in der Familienansicht: die Art (TYPE) ersetzt den Namen nur bei allgemeinen Ereignissen,
// sonst steht sie in Klammern dahinter („Heirat (kirchlich)“)
export function familyEventLabel(e) {
  const name = t('family.eventTypes.' + e.event_type)
  const desc = e.event_descriptor?.trim()
  if (!desc) return name
  if (e.event_type === 'EVEN') return desc
  const key = 'relEvents.descriptors.' + desc.toLowerCase()
  return `${name} (${t(key) === key ? desc : t(key)})`
}

const bySex = (sex, m, f, x) => sex === 'M' ? m : sex === 'F' ? f : x

/**
 * Ereignisse aus der Familie einer Person, die in ihren Lebenszeitraum fallen:
 * Familienereignisse (Heirat, Scheidung …), Geburt und Tod der Kinder, Tod der Partner und Eltern.
 * Geladen wird erst, wenn der Schalter an ist.
 *
 * personId: Getter der Personen-ID (null = neue Person), events: Ref der eigenen Ereignisse,
 * refresh: Getter, der sich ändert, wenn Beziehungen anderswo geändert wurden
 */
export function useRelativeEvents(personId, events, refresh) {
  const show     = ref(readPref())
  const families = ref([])
  let loadedFor  = null

  async function load() {
    const id = personId()
    if (!show.value || !id) { families.value = []; loadedFor = null; return }
    const key = `${id}/${refresh()}`
    if (key === loadedFor) return
    loadedFor = key
    try   { families.value = await fetchPersonFamilies(id) }
    catch { families.value = [] }
  }
  watch([show, personId, refresh], load, { immediate: true })
  watch(show, v => { try { localStorage.setItem(STORAGE_KEY, v ? '1' : '0') } catch { /* egal */ } })

  // Lebenszeitraum aus den aktuellen Eingaben (Taufe/Begräbnis als Ersatz)
  const ownDate = (...types) => types.map(ty => events.value.find(e => e.event_type === ty && e.event_date)?.event_date)
                                     .find(Boolean)
  const life = computed(() => {
    const birth = ownDate('BIRT', 'CHR', 'BAPM')
    const death = ownDate('DEAT', 'BURI', 'CREM')
    return { from: dateKey(birth), to: dateKey(death, { upper: true }), birthYear: extractYear(birth) }
  })
  const inLife = date => {
    const lo = dateKey(date), hi = dateKey(date, { upper: true })
    const { from, to } = life.value
    return (from === null || hi >= from) && (to === null || lo <= to)
  }

  const list = computed(() => {
    const id = Number(personId())
    if (!show.value || !id) return []
    const out = []
    const add = (date, item) => { if (date ? inLife(date) : !item.personId) out.push({ ...item, date: date ?? '' }) }

    for (const f of families.value) {
      if (f.person_role === 'CHIL') {
        // Elternfamilie: Tod von Vater und Mutter
        for (const p of f.members.filter(m => m.role !== 'CHIL' && m.death_date)) {
          add(p.death_date, { type: 'DEAT', label: t('relEvents.death' + bySex(p.sex, 'Father', 'Mother', 'Parent')),
                              name: p.display_name, personId: p.person_id })
        }
        continue
      }
      const partners = f.members.filter(m => m.role !== 'CHIL' && m.person_id !== id)
      const partnerNames = partners.map(p => p.display_name).join(', ')
      for (const e of f.events ?? []) {
        add(e.event_date, { type: e.event_type, label: familyEventLabel(e),
                            name: partnerNames, place: e.event_place, familyId: f.id, family: true })
      }
      for (const p of partners.filter(m => m.death_date)) {
        add(p.death_date, { type: 'DEAT', label: t('relEvents.death' + bySex(p.sex, 'Husband', 'Wife', 'Partner')),
                            name: p.display_name, personId: p.person_id })
      }
      for (const c of f.members.filter(m => m.role === 'CHIL')) {
        const kind = bySex(c.sex, 'Son', 'Daughter', 'Child')
        if (c.birth_date) add(c.birth_date, { type: 'BIRT', label: t('relEvents.birth' + kind), name: c.display_name, personId: c.person_id })
        if (c.death_date) add(c.death_date, { type: 'DEAT', label: t('relEvents.death' + kind), name: c.display_name, personId: c.person_id })
      }
    }
    for (const item of out) {
      const y = extractYear(item.date)
      item.key = dateKey(item.date)
      item.symbol = SYMBOLS[item.type] ?? ''
      item.age = y && life.value.birthYear && y >= life.value.birthYear ? y - life.value.birthYear : null
    }
    return out.sort((a, b) => (a.key ?? Infinity) - (b.key ?? Infinity))
  })

  /**
   * Eigene Ereignisse in ihrer Reihenfolge, dazwischen die Familienereignisse nach Datum:
   * jeweils vor dem ersten eigenen Ereignis mit späterem Datum, Undatiertes ans Ende.
   * Zeilen: { evt, ei } für eigene, { rel } für Familienereignisse.
   */
  const rows = computed(() => {
    const rel = list.value
    let next = 0
    const out = []
    events.value.forEach((evt, ei) => {
      const k = dateKey(evt.event_date)
      if (k !== null) while (next < rel.length && rel[next].key !== null && rel[next].key < k) out.push({ rel: rel[next++] })
      out.push({ evt, ei })
    })
    while (next < rel.length) out.push({ rel: rel[next++] })
    return out
  })

  return { showRelativeEvents: show, relativeEventCount: computed(() => list.value.length), eventRows: rows }
}
