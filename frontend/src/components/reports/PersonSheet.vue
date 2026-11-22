<script setup>
import { computed, watch } from 'vue'
import { fetchPerson } from '../../api/persons.js'
import { fetchPersonFamilies } from '../../api/families.js'
import { mediaFileUrl, isImage } from '../../api/media.js'
import { eventTitle, viewFields, fullName } from '../../personModel.js'
import { useReportData, datePlace, lifeSpan } from './reportFormat.js'
import { t } from '../../i18n/index.js'

/** Personenblatt: Namen, alle Ereignisse und Merkmale, Eltern, Geschwister, Ehen mit Kindern, Notizen, Quellen */
const props = defineProps({ params: { type: Object, required: true } })
const emit  = defineEmits(['title'])

const { data, error } = useReportData(async () => {
  const [person, families] = await Promise.all([fetchPerson(props.params.person), fetchPersonFamilies(props.params.person)])
  return { person, families }
})
const person = computed(() => data.value?.person)
const name   = computed(() => person.value?.names?.[0] ? fullName(person.value.names[0]) || '–' : '–')
watch(data, d => d && emit('title', t('reports.titles.person', { name: name.value })))

const photo = computed(() => person.value?.media?.find(m => m.is_primary && isImage(m)) ?? null)

// Angaben eines Ereignisses außer Datum und Ort (die haben eigene Spalten)
const details = ev => viewFields(ev).filter(f => !['dateLabel', 'placeLabel'].includes(f.key))

/** Mitglied aus der Familien-API (display_name „Nachname, Vorname“, birth_date, death_date) mit Lebensdaten */
function label(m) {
  const span = m.private ? '' : lifeSpan(m)
  return span ? `${m.display_name} (${span})` : m.display_name
}

const parents = computed(() => (data.value?.families ?? []).filter(f => f.person_role === 'CHIL').map(f => ({
  id: f.id,
  parents:  f.members.filter(m => m.role !== 'CHIL'),
  siblings: f.members.filter(m => m.role === 'CHIL' && m.person_id !== person.value.id),
})))
const unions = computed(() => (data.value?.families ?? []).filter(f => f.person_role !== 'CHIL').map(f => ({
  id: f.id,
  partners: f.members.filter(m => m.role !== 'CHIL' && m.person_id !== person.value.id),
  children: f.members.filter(m => m.role === 'CHIL'),
  events:   f.events ?? [],
})))
const familyEvent = ev => (ev.event_type === 'EVEN' && ev.event_descriptor) || t('family.eventTypes.' + ev.event_type)

/** Quellen der Person und ihrer Ereignisse, je Quelle + Fundstelle einmal, durchnummeriert */
const sources = computed(() => {
  const p = person.value
  if (!p) return []
  const seen = new Map()
  for (const c of [...(p.citations ?? []), ...(p.events ?? []).flatMap(e => e.citations ?? [])]) {
    const title = c.source_title || c.source_abbreviation
    if (!title && !c.page) continue
    const key = (c.source_id ?? title) + '|' + (c.page ?? '')
    if (!seen.has(key)) seen.set(key, { title: title || '–', page: c.page })
  }
  return [...seen.values()]
})
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <p v-else-if="person.private" class="report-warning">{{ t('privacy.hiddenPerson') }}</p>
  <template v-else>
    <div class="report-person-head">
      <div>
        <table class="report-table">
          <tbody>
            <tr v-for="(n, i) in person.names" :key="i">
              <th>{{ t('person.nameTypes.' + n.name_type) }}</th><td><strong>{{ fullName(n) || '–' }}</strong></td>
            </tr>
            <tr><th>{{ t('sex.label') }}</th><td>{{ t('sex.' + (person.sex || 'U')) }}</td></tr>
          </tbody>
        </table>
      </div>
      <img v-if="photo" class="report-photo" :src="mediaFileUrl(photo.id)" :alt="photo.title || name" />
    </div>

    <section v-if="person.events?.length" class="report-section">
      <h2>{{ t('reports.events') }}</h2>
      <table class="report-table report-events">
        <thead><tr><th>{{ t('reports.cols.event') }}</th><th>{{ t('reports.cols.date') }}</th><th>{{ t('reports.cols.place') }}</th><th>{{ t('reports.cols.details') }}</th></tr></thead>
        <tbody>
          <tr v-for="ev in person.events" :key="ev.id">
            <th>{{ eventTitle(ev) }}</th>
            <td class="nowrap">{{ ev.event_date ? datePlace(ev.event_date, null) : '' }}</td>
            <td>{{ ev.event_place }}</td>
            <td><span v-for="f in details(ev)" :key="f.key" class="report-detail"><em v-if="f.key !== 'value'">{{ f.label + ': ' }}</em>{{ f.value }}</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-for="f in parents" :key="'p' + f.id" class="report-section">
      <h2>{{ t('reports.parentsAndSiblings') }}</h2>
      <table class="report-table">
        <tbody>
          <tr v-for="m in f.parents" :key="m.person_id">
            <th>{{ t(m.role === 'WIFE' ? 'reports.mother' : 'reports.father') }}</th><td>{{ label(m) }}</td>
          </tr>
          <tr v-if="f.siblings.length">
            <th>{{ t('reports.siblings') }}</th>
            <td><div v-for="m in f.siblings" :key="m.person_id">{{ label(m) }}</div></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-for="u in unions" :key="'u' + u.id" class="report-section">
      <h2>{{ t('reports.familyWith', { name: u.partners.map(label).join(', ') || t('reports.unknownPartner') }) }}</h2>
      <table class="report-table">
        <tbody>
          <tr v-for="ev in u.events" :key="ev.id">
            <th>{{ familyEvent(ev) }}</th><td>{{ datePlace(ev.event_date, ev.event_place) }}</td>
          </tr>
          <tr v-if="u.children.length">
            <th>{{ t('reports.children') }}</th>
            <td><div v-for="m in u.children" :key="m.person_id">{{ label(m) }}</div></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-if="person.notes" class="report-section">
      <h2>{{ t('reports.notes') }}</h2>
      <p class="report-notes">{{ person.notes }}</p>
    </section>

    <section v-if="sources.length" class="report-section">
      <h2>{{ t('reports.sources') }}</h2>
      <ol class="report-sources">
        <li v-for="(s, i) in sources" :key="i">{{ s.title }}<template v-if="s.page">, {{ s.page }}</template></li>
      </ol>
    </section>
  </template>
</template>
