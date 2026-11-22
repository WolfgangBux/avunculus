<script setup>
import { computed, watch } from 'vue'
import { fetchListReport } from '../../api/reports.js'
import { useReportData, nameLabel, lifeSpan, displayDate } from './reportFormat.js'
import { t } from '../../i18n/index.js'

/**
 * Listen: Geburten, Taufen, Sterbefälle, Heiraten (nach Datum), Berufe (nach Beruf gruppiert),
 * Friedhöfe (Begräbnisse nach Ort gruppiert). Filter aus der Adresse: from, to (Jahre), place.
 */
const props = defineProps({
  kind:   { type: String, required: true },
  params: { type: Object, required: true },
})
const emit = defineEmits(['title'])

const { data, error } = useReportData(() => fetchListReport(props.kind, props.params))
const person = id => data.value?.persons?.[id]

/** Filter als Untertitel: „1800–1850 · Ort: Ingolstadt“ */
const filterText = computed(() => {
  const { from, to, place } = props.params
  const parts = []
  if (from && to) parts.push(`${from}–${to}`)
  else if (from)  parts.push(t('reports.fromYear', { y: from }))
  else if (to)    parts.push(t('reports.toYear', { y: to }))
  if (place) parts.push(t('reports.placeFilter', { place }))
  return parts.join(' · ')
})
watch(data, d => d && emit('title', t('reports.kinds.' + props.kind) + (filterText.value ? ' (' + filterText.value + ')' : '')))

const grouped = computed(() => ['occupations', 'burials'].includes(props.kind))
/** Spaltenaufteilung (feste Breiten, damit die Tabellen der Gruppen untereinander fluchten) */
const layout  = computed(() => props.kind === 'marriages' ? 'couple' : grouped.value ? 'grouped' : 'dated')

/** Gruppen für Berufe (nach Bezeichnung) und Friedhöfe (nach Ort); sonst eine Gruppe ohne Titel */
const groups = computed(() => {
  const rows = data.value?.rows ?? []
  if (!grouped.value) return [{ title: null, rows }]
  const map = new Map()
  for (const r of rows) {
    const key = (props.kind === 'occupations' ? r.value : r.place) || t('reports.noValue.' + props.kind)
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(r)
  }
  return [...map].map(([title, list]) => ({ title, rows: list }))
})

const personName = id => (id ? nameLabel(person(id)) : '–')
const span = id => (id && !person(id)?.private ? lifeSpan(person(id)) : '')
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <template v-else>
    <p class="report-summary">
      {{ t('reports.entries', { n: data.rows.length }) }}<template v-if="grouped"> · {{ t('reports.groups.' + kind, { n: groups.length }) }}</template>
    </p>
    <p v-if="data.truncated" class="report-warning">{{ t('reports.truncated', { n: data.limit }) }}</p>
    <p v-if="!data.rows.length" class="muted">{{ t('reports.empty') }}</p>

    <template v-else>
    <section v-for="g in groups" :key="g.title ?? ''" class="report-section">
      <h2 v-if="g.title">{{ g.title }} <small>({{ g.rows.length }})</small></h2>
      <table :class="['report-table', 'report-list', 'report-list-' + layout]">
        <thead>
          <tr v-if="kind === 'marriages'">
            <th>{{ t('reports.cols.date') }}</th><th>{{ t('reports.cols.husband') }}</th><th>{{ t('reports.cols.wife') }}</th><th>{{ t('reports.cols.place') }}</th>
          </tr>
          <tr v-else-if="grouped">
            <th>{{ t('reports.cols.name') }}</th><th>{{ t('reports.cols.lifespan') }}</th><th>{{ t('reports.cols.date') }}</th>
            <th>{{ kind === 'occupations' ? t('reports.cols.place') : t('reports.cols.kind') }}</th>
          </tr>
          <tr v-else>
            <th>{{ t('reports.cols.date') }}</th><th>{{ t('reports.cols.name') }}</th><th>{{ t('reports.cols.lifespan') }}</th><th>{{ t('reports.cols.place') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-if="kind === 'marriages'">
            <tr v-for="(r, i) in g.rows" :key="i">
              <td class="nowrap">{{ displayDate(r.date) }}</td>
              <td>{{ personName(r.husband_id) }} <span class="muted">{{ span(r.husband_id) }}</span></td>
              <td>{{ personName(r.wife_id) }} <span class="muted">{{ span(r.wife_id) }}</span></td>
              <td>{{ r.place }}</td>
            </tr>
          </template>
          <template v-else-if="grouped">
            <tr v-for="(r, i) in g.rows" :key="i">
              <td>{{ personName(r.person_id) }}</td>
              <td class="nowrap">{{ span(r.person_id) }}</td>
              <td class="nowrap">{{ displayDate(r.date) }}</td>
              <td>{{ kind === 'occupations' ? r.place : t('reports.facts.' + (r.type === 'CREM' ? 'cremation' : 'burial')) }}</td>
            </tr>
          </template>
          <template v-else>
            <tr v-for="(r, i) in g.rows" :key="i">
              <td class="nowrap">{{ displayDate(r.date) }}</td>
              <td>{{ personName(r.person_id) }}</td>
              <td class="nowrap">{{ span(r.person_id) }}</td>
              <td>{{ r.place }}</td>
            </tr>
          </template>
        </tbody>
      </table>
    </section>
    </template>
  </template>
</template>
