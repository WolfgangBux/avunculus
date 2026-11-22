<script setup>
import { computed, watch } from 'vue'
import { fetchFamilyBook } from '../../api/reports.js'
import { useReportData, nameLabel, nameWithSpan, datePlace, factLines, filterText, SYMBOLS } from './reportFormat.js'
import SymbolLegend from './SymbolLegend.vue'
import { t } from '../../i18n/index.js'

/**
 * Familienbuch (wie ein Ortsfamilienbuch): jede Familie mit laufender Nummer, sortiert nach dem
 * Familiennamen des Mannes und dem Heiratsjahr. Die Herkunft der Partner und die Familien der Kinder
 * stehen als Verweis „Fam. 12“, wenn sie im Buch sind, sonst ausgeschrieben. Am Ende ein Namensregister.
 */
const props = defineProps({ params: { type: Object, required: true } })
const emit  = defineEmits(['title'])

const { data, error } = useReportData(() => fetchFamilyBook(props.params))
const person = id => data.value?.persons?.[id]

const filter = computed(() => filterText(props.params))
watch(data, d => d && emit('title', t('reports.kinds.familybook') + (filter.value ? ' (' + filter.value + ')' : '')))

/** Familien-ID → Nummer im Buch */
const numbers = computed(() => new Map((data.value?.families ?? []).map((f, i) => [f.id, i + 1])))

const surnameOf = id => {
  const p = person(id)
  if (!p || p.private) return ''
  return [p.surname_prefix, p.surname || p.alt_surname].filter(Boolean).join(' ')
}

/** Familien nach dem Namen des ersten Partners gruppiert (Reihenfolge vom Server) */
const groups = computed(() => {
  const out = []
  for (const f of data.value?.families ?? []) {
    const title = surnameOf(f.partners[0]) || t('reports.noSurname')
    if (out.at(-1)?.title !== title) out.push({ title, families: [] })
    out.at(-1).families.push(f)
  }
  return out
})

const inline = p => factLines(p)
const eventLabel = ev => (ev.event_type === 'EVEN' && ev.event_descriptor) || t('family.eventTypes.' + ev.event_type)
const eventText  = ev => [datePlace(ev.event_date, ev.place), ev.event_value].filter(Boolean).join(' – ')
/** Ereignisse mit Angaben (eine Heirat ohne Datum und Ort sagt im Buch nichts) */
const shownEvents = f => f.events.filter(ev => eventText(ev) || ev.event_type !== 'MARR')

/** „30.12.1965 in Nöham mit“ – der Name des Partners folgt fett */
const unionText = u => [u.marriage ? datePlace(u.marriage.date, u.marriage.place) : '', t('reports.with'),
                        u.spouse_id ? '' : t('reports.unknownPartner')].filter(Boolean).join(' ')

/** Herkunft eines Partners: { father, mother, ref } – ref = Nummer der Elternfamilie im Buch */
function origin(pid) {
  const o = data.value?.origins?.[pid]
  if (!o) return null
  return { father: o.parents[0], mother: o.parents[1], ref: numbers.value.get(o.family_id) ?? null }
}

/** Namensregister: jede Person mit den Nummern, in denen sie vorkommt (als Partner fett) */
const register = computed(() => {
  const d = data.value
  if (!d) return []
  const map = new Map()
  const add = (pid, no, partner) => {
    const p = person(pid)
    if (!p || p.private) return
    if (!map.has(pid)) map.set(pid, { pid, refs: new Map() })
    const refs = map.get(pid).refs
    refs.set(no, refs.get(no) || partner)
  }
  d.families.forEach((f, i) => {
    for (const pid of f.partners) add(pid, i + 1, true)
    for (const c of f.children) add(c.person_id, i + 1, false)
  })
  const key = e => { const p = person(e.pid); return `${p.surname || p.alt_surname || '\uffff'}\u0000${p.given_name ?? ''}` }
  return [...map.values()]
    .map(e => ({ ...e, key: key(e), refs: [...e.refs].sort((a, b) => a[0] - b[0]) }))
    .sort((a, b) => a.key.localeCompare(b.key, 'de', { sensitivity: 'base' }))
})
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <template v-else>
    <p class="report-summary">{{ t(data.families.length === 1 ? 'reports.familyOne' : 'reports.familyCount', { n: data.families.length }) }}</p>
    <p v-if="data.truncated" class="report-warning">{{ t('reports.truncatedBook', { n: data.limit, total: data.total }) }}</p>
    <p v-if="!data.families.length" class="muted">{{ t('reports.empty') }}</p>

    <section v-for="g in groups" :key="g.title + g.families[0].id" class="report-section">
      <h2>{{ g.title }} <small>({{ g.families.length }})</small></h2>
      <div class="report-entries">
        <article v-for="f in g.families" :key="f.id" class="report-entry report-book-family">
          <div class="report-num">{{ numbers.get(f.id) }}</div>
          <div>
            <template v-for="(pid, pi) in f.partners" :key="pid">
              <div class="report-book-partner">
                <span class="report-name">{{ nameLabel(person(pid)) }}</span>
                <span v-if="person(pid)?.private" class="muted"> – {{ t('reports.privateHint') }}</span>
                <template v-else>
                  <span v-for="l in inline(person(pid))" :key="l.key" class="report-inline-fact" :title="l.label">{{ l.symbol || l.label + ':' }} {{ l.text }}</span>
                </template>
                <div v-if="origin(pid)" class="report-book-origin">
                  {{ t('reports.parentsOf.' + (person(pid)?.sex ?? 'U')) }}
                  {{ origin(pid).father ? nameLabel(person(origin(pid).father)) : '–' }}
                  {{ t('reports.and') }}
                  {{ origin(pid).mother ? nameLabel(person(origin(pid).mother)) : '–' }}<template v-if="origin(pid).ref">
                    → <strong>{{ t('reports.famRef', { n: origin(pid).ref }) }}</strong></template>
                </div>
              </div>
              <!-- Ereignisse der Familie zwischen den Partnern -->
              <ul v-if="pi === 0 && shownEvents(f).length" class="report-facts report-book-events">
                <li v-for="(ev, i) in shownEvents(f)" :key="i">
                  <span class="report-sym">{{ ev.event_type === 'MARR' ? SYMBOLS.marriage : eventLabel(ev) + ':' }}</span> {{ eventText(ev) }}
                </li>
              </ul>
            </template>

            <ol v-if="f.children.length" class="report-book-children">
              <li v-for="c in f.children" :key="c.person_id">
                <span class="report-name">{{ nameLabel(person(c.person_id)) }}</span>
                <span v-if="c.pedigree && c.pedigree !== 'BIRTH'" class="muted"> ({{ t('reports.pedigree.' + c.pedigree) }})</span>
                <span v-for="l in inline(person(c.person_id))" :key="l.key" class="report-inline-fact" :title="l.label">{{ l.symbol || l.label + ':' }} {{ l.text }}</span>
                <div v-for="u in data.unions[c.person_id] ?? []" :key="u.family_id" class="report-book-union">
                  {{ SYMBOLS.marriage }} {{ unionText(u) }} <strong v-if="u.spouse_id">{{ nameWithSpan(person(u.spouse_id)) }}</strong><template
                    v-if="numbers.get(u.family_id)"> → <strong>{{ t('reports.famRef', { n: numbers.get(u.family_id) }) }}</strong></template>
                </div>
              </li>
            </ol>
          </div>
        </article>
      </div>
    </section>

    <section v-if="register.length" class="report-section report-register">
      <h2>{{ t('reports.register') }} <small>{{ t('reports.registerHint') }}</small></h2>
      <ul>
        <li v-for="e in register" :key="e.pid">
          {{ nameWithSpan(person(e.pid)) }}
          <span class="report-register-refs">
            <template v-for="([no, partner], i) in e.refs" :key="no"><template v-if="i">, </template><strong v-if="partner">{{ no }}</strong><template v-else>{{ no }}</template></template>
          </span>
        </li>
      </ul>
    </section>
    <SymbolLegend />
  </template>
</template>
