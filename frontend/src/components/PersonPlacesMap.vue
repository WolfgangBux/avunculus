<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { fetchPersonPlaces } from '../api/persons.js'
import { familyEventLabel } from '../composables/relativeEvents.js'
import { dateKey } from '../gedcom.js'
import { displayDate } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Karte mit allen Orten einer Person: eigene Ereignisse (orange) und Ereignisse ihrer Familien,
 * z. B. Heirat (blau). Die eigenen datierten Ereignisse sind als Lebensweg verbunden.
 * Hohle Kreise: Lage nur ungefähr (Koordinaten der Gemeinde).
 */
const props = defineProps({
  personId: { type: Number, required: true },
  person:   { type: Object, default: null },   // ändert sich nach dem Speichern → neu laden
  refresh:  Number,
})
const emit = defineEmits(['open-place'])

const SYMBOLS = { BIRT: '*', CHR: '~', BAPM: '~', DEAT: '†', BURI: '▭', CREM: '▭', MARR: '⚭', DIV: '⚮' }
const DESCRIPTOR_TYPES = ['EVEN', 'FACT', 'IDNO']

const data = ref(null)
const el   = ref(null)
let map = null

async function load() {
  try   { data.value = await fetchPersonPlaces(props.personId) }
  catch { data.value = null }
}
watch(() => [props.personId, props.person, props.refresh], load, { immediate: true })

const places  = computed(() => data.value?.places ?? [])
const without = computed(() => data.value?.without_coords ?? [])
const hasFamily = computed(() => places.value.some(p => p.events.some(e => e.scope === 'family')))
const hasApprox = computed(() => places.value.some(p => p.approx))

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback

function eventLabel(e) {
  if (e.scope === 'family') {
    const label = familyEventLabel(e)
    return e.partner ? `${label} ${t('relEvents.with', { name: e.partner })}` : label
  }
  return (DESCRIPTOR_TYPES.includes(e.event_type) && e.event_descriptor) || t('person.eventTypes.' + e.event_type)
}
const byDate = (a, b) => (dateKey(a.event_date) ?? Infinity) - (dateKey(b.event_date) ?? Infinity)

function popup(p) {
  const rows = [...p.events].sort(byDate).map(e => `<div>${esc(SYMBOLS[e.event_type] ?? '•')} ${esc(eventLabel(e))}`
    + (e.event_date ? ` <span class="muted">${esc(displayDate(e.event_date))}</span>` : '') + '</div>')
  return `<div class="map-popup"><strong>${esc(p.full_name || p.name)}</strong>
    ${p.approx ? `<div class="muted">${esc(t('stats.map.approx'))}</div>` : ''}
    <div>${rows.join('')}</div>
    <button type="button" class="map-open" data-place="${p.id}">${esc(t('stats.map.openPlace'))}</button></div>`
}

function draw() {
  map?.remove(); map = null
  if (!el.value || !places.value.length) return
  map = L.map(el.value, { scrollWheelZoom: false, worldCopyJump: true })
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
  }).addTo(map)
  map.on('focus click', () => map.scrollWheelZoom.enable())
  map.on('blur mouseout', () => map.scrollWheelZoom.disable())

  const own    = css('--color-primary', '#e8641b')
  const family = css('--color-family', '#3b7dd8')
  const surface = css('--color-surface', '#fff')

  // Lebensweg: eigene datierte Ereignisse in zeitlicher Folge, gleiche Orte hintereinander nur einmal
  const steps = places.value.flatMap(p => p.events.filter(e => e.scope === 'person' && dateKey(e.event_date) !== null)
                                                    .map(e => ({ ...e, p })))
                            .sort(byDate)
                            .map(s => s.p).filter((p, i, all) => i === 0 || all[i - 1] !== p)
  if (steps.length > 1) {
    L.polyline(steps.map(p => [p.lat, p.lon]), { color: own, weight: 2, opacity: .6, dashArray: '5 6' }).addTo(map)
  }

  for (const p of places.value) {
    const color = p.events.some(e => e.scope === 'person') ? own : family
    const n = p.events.length
    const m = L.circleMarker([p.lat, p.lon], {
      radius: 6 + 3 * Math.min(4, n - 1),
      color: p.approx ? color : surface, weight: p.approx ? 1.5 : 1.5, dashArray: p.approx ? '3 2' : null,
      fillColor: color, fillOpacity: p.approx ? 0.2 : 0.8,
    })
    m.bindTooltip(`<strong>${esc(p.name)}</strong>` + (n > 1 ? ` · ${n}` : ''), { direction: 'top', offset: [0, -4] })
    m.bindPopup(popup(p))
    m.addTo(map)
  }

  const bounds = L.latLngBounds(places.value.map(p => [p.lat, p.lon]))
  map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 })
}
watch(places, async () => { await nextTick(); draw() })

function onClick(e) {
  const btn = e.target.closest?.('.map-open')
  if (btn) emit('open-place', Number(btn.dataset.place))
}
onBeforeUnmount(() => { map?.remove(); map = null })
</script>

<template>
  <fieldset v-if="places.length || without.length" class="person-map">
    <legend>{{ t('personMap.title') }}</legend>
    <div v-if="places.length" ref="el" class="map-canvas person-map-canvas" @click="onClick" />
    <p v-if="places.length" class="stats-sub map-legend">
      <span class="map-key map-key-solid" /> {{ t('personMap.own') }}
      <template v-if="hasFamily"><span class="map-key map-key-family" /> {{ t('personMap.family') }}</template>
      <template v-if="hasApprox"><span class="map-key map-key-approx" /> {{ t('stats.map.legendApprox') }}</template>
      · {{ t('personMap.hint') }}
    </p>
    <p v-if="without.length" class="field-hint">
      {{ t('personMap.withoutCoords') }}
      <template v-for="(w, i) in without" :key="w.id">
        <a href="#" @click.prevent="emit('open-place', w.id)">{{ w.full_name }}</a><template v-if="i < without.length - 1">; </template>
      </template>
    </p>
  </fieldset>
</template>
