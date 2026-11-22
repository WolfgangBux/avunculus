<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import Select from 'openvue/select'
import ToggleSwitch from 'openvue/toggleswitch'
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
 * Auf Wunsch kommen Eltern (grün) und Großeltern (violett) dazu, je mit eigenen Ereignissen und Heirat.
 */
const props = defineProps({
  personId: { type: Number, required: true },
  person:   { type: Object, default: null },   // ändert sich nach dem Speichern → neu laden
  refresh:  Number,
})
const emit = defineEmits(['open-place'])

const SYMBOLS = { BIRT: '*', CHR: '~', BAPM: '~', DEAT: '†', BURI: '▭', CREM: '▭', MARR: '⚭', DIV: '⚮' }
const DESCRIPTOR_TYPES = ['EVEN', 'FACT', 'IDNO']

const ANCESTORS_KEY = 'avunculus.personMap.ancestors'
const ancestors = ref((() => { try { return Math.min(2, Number(localStorage.getItem(ANCESTORS_KEY)) || 0) } catch { return 0 } })())
watch(ancestors, v => { try { localStorage.setItem(ANCESTORS_KEY, String(v)) } catch { /* kein Storage */ } })
// Hintergrundkarte schwarz-weiß: Kreise und Linien heben sich besser ab
const GRAY_KEY = 'avunculus.personMap.gray'
const gray = ref((() => { try { return localStorage.getItem(GRAY_KEY) === '1' } catch { return false } })())
watch(gray, v => { try { localStorage.setItem(GRAY_KEY, v ? '1' : '0') } catch { /* kein Storage */ } })
const ancestorOptions = computed(() => [0, 1, 2].map(value => ({ value, label: t('personMap.ancestorOptions.' + value) })))

const data = ref(null)
const el   = ref(null)
let map = null

async function load() {
  try   { data.value = await fetchPersonPlaces(props.personId, ancestors.value) }
  catch { data.value = null }
}
watch(() => [props.personId, props.person, props.refresh, ancestors.value], load, { immediate: true })

const places  = computed(() => data.value?.places ?? [])
const without = computed(() => data.value?.without_coords ?? [])
const hasFamily = computed(() => places.value.some(p => p.events.some(e => e.scope === 'family' && !e.generation)))
const hasGen    = g => places.value.some(p => p.events.some(e => e.generation === g))
const hasApprox = computed(() => places.value.some(p => p.approx))

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback

function eventLabel(e) {
  if (e.generation) {
    const label = e.scope === 'family' ? familyEventLabel(e) : (DESCRIPTOR_TYPES.includes(e.event_type) && e.event_descriptor) || t('person.eventTypes.' + e.event_type)
    const who   = e.scope === 'family' ? e.partner : e.person
    return who ? `${label} – ${who}` : label
  }
  if (e.scope === 'family') {
    const label = familyEventLabel(e)
    return e.partner ? `${label} ${t('relEvents.with', { name: e.partner })}` : label
  }
  return (DESCRIPTOR_TYPES.includes(e.event_type) && e.event_descriptor) || t('person.eventTypes.' + e.event_type)
}
const byDate = (a, b) => (dateKey(a.event_date) ?? Infinity) - (dateKey(b.event_date) ?? Infinity)

function popup(p) {
  const dot  = e => p.events.some(x => x.generation) ? `<span class="map-dot" style="background:${colors[colorKey(e)]}"></span>` : ''
  const rows = [...p.events].sort(byDate).map(e => `<div>${dot(e)}${esc(SYMBOLS[e.event_type] ?? '•')} ${esc(eventLabel(e))}`
    + (e.event_date ? ` <span class="muted">${esc(displayDate(e.event_date))}</span>` : '') + '</div>')
  return `<div class="map-popup"><strong>${esc(p.full_name || p.name)}</strong>
    ${p.approx ? `<div class="muted">${esc(t('stats.map.approx'))}</div>` : ''}
    <div>${rows.join('')}</div>
    <button type="button" class="map-open" data-place="${p.id}">${esc(t('stats.map.openPlace'))}</button></div>`
}

// Farbe je Ereignis; ein Ort bekommt die Farbe der nächsten Generation (eigene vor Familie vor Eltern vor Großeltern)
let colors = {}
const RANK = ['own', 'family', 'gen1', 'gen2']
const colorKey = e => e.generation ? 'gen' + e.generation : e.scope === 'person' ? 'own' : 'family'
const placeColorKey = p => RANK[Math.min(...p.events.map(e => RANK.indexOf(colorKey(e))))]

// Richtung des Lebenswegs: Pfeilspitze in der Mitte jedes Abschnitts, gedreht nach dem Winkel
// auf der Karte (Mercator ist winkeltreu, der Winkel gilt also für jede Zoomstufe).
// Abschnitte, die auf dem Bildschirm zu kurz sind, bekommen keinen Pfeil – sonst verdeckt er die Kreise.
let arrows = []
function addArrows(steps, color, size) {
  for (let i = 1; i < steps.length; i++) {
    const a = L.latLng(steps[i - 1].lat, steps[i - 1].lon), b = L.latLng(steps[i].lat, steps[i].lon)
    const pa = map.project(a, 0), pb = map.project(b, 0)
    const angle = Math.atan2(pb.y - pa.y, pb.x - pa.x) * 180 / Math.PI
    const icon = L.divIcon({
      className: 'map-arrow', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
      html: `<svg viewBox="0 0 10 10" width="${size}" height="${size}" style="transform:rotate(${angle}deg)">`
          + `<path d="M2 1 L9 5 L2 9 L4 5 Z" fill="${color}" /></svg>`,
    })
    arrows.push({ a, b, marker: L.marker(L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2), { icon, interactive: false, keyboard: false }) })
  }
}
function updateArrows() {
  for (const { a, b, marker } of arrows) {
    const long = map.latLngToLayerPoint(a).distanceTo(map.latLngToLayerPoint(b)) > 50
    if (long && !map.hasLayer(marker)) marker.addTo(map)
    else if (!long && map.hasLayer(marker)) marker.remove()
  }
}

function draw() {
  map?.remove(); map = null; arrows = []
  if (!el.value || !places.value.length) return
  map = L.map(el.value, { scrollWheelZoom: false, worldCopyJump: true })
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
  }).addTo(map)
  map.on('focus click', () => map.scrollWheelZoom.enable())
  map.on('blur mouseout', () => map.scrollWheelZoom.disable())

  colors = { own: css('--color-primary', '#e8641b'), family: css('--color-family', '#3b7dd8'), gen1: css('--color-gen1', '#2e9e5b'), gen2: css('--color-gen2', '#8e5cc8') }
  const surface = css('--color-surface', '#fff')

  // Lebenswege: je Person (die eigene und jeder eingeblendete Vorfahr) die datierten Ereignisse
  // in zeitlicher Folge, gleiche Orte hintereinander nur einmal; Vorfahren dünner in ihrer Farbe
  const paths = new Map()
  for (const p of places.value) {
    for (const e of p.events) {
      if (e.scope !== 'person' || dateKey(e.event_date) === null) continue
      const key = e.generation ? e.person_id : 0
      if (!paths.has(key)) paths.set(key, { color: colors[colorKey(e)], own: !e.generation, steps: [] })
      paths.get(key).steps.push({ ...e, p })
    }
  }
  for (const path of [...paths.values()].sort((a, b) => a.own - b.own)) {   // eigener Weg zuletzt, also obenauf
    const steps = path.steps.sort(byDate).map(s => s.p).filter((p, i, all) => i === 0 || all[i - 1] !== p)
    if (steps.length < 2) continue
    L.polyline(steps.map(p => [p.lat, p.lon]), path.own ? { color: path.color, weight: 2, opacity: .6, dashArray: '5 6' }
                                                        : { color: path.color, weight: 1.5, opacity: .5, dashArray: '3 5' }).addTo(map)
    addArrows(steps, path.color, path.own ? 12 : 9)
  }

  for (const p of places.value) {
    const color = colors[placeColorKey(p)]
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
  map.on('zoomend', updateArrows)
  updateArrows()
}
watch(places, async () => { await nextTick(); draw() })

function onClick(e) {
  const btn = e.target.closest?.('.map-open')
  if (btn) emit('open-place', Number(btn.dataset.place))
}
onBeforeUnmount(() => { map?.remove(); map = null })
</script>

<template>
  <fieldset v-if="places.length || without.length || ancestors" class="person-map">
    <legend>{{ t('personMap.title') }}</legend>
    <div class="person-map-tools">
      {{ t('personMap.ancestors') }}
      <Select v-model="ancestors" :options="ancestorOptions" option-label="label" option-value="value" size="small" />
      <label v-if="places.length" class="person-map-gray">
        <ToggleSwitch v-model="gray" />
        {{ t('personMap.gray') }}
      </label>
    </div>
    <!-- Klasse für Schwarz-Weiß am umgebenden Div: am Karten-Div selbst würde Vue Leaflets eigene Klassen überschreiben -->
    <div v-if="places.length" :class="{ 'map-gray': gray }">
      <div ref="el" class="map-canvas person-map-canvas" @click="onClick" />
    </div>
    <p v-if="places.length" class="stats-sub map-legend">
      <span class="map-legend-item"><span class="map-key map-key-solid" /> {{ t('personMap.own') }}</span>
      <span v-if="hasFamily" class="map-legend-item"><span class="map-key map-key-family" /> {{ t('personMap.family') }}</span>
      <span v-if="hasGen(1)" class="map-legend-item"><span class="map-key map-key-gen1" /> {{ t('personMap.gen1') }}</span>
      <span v-if="hasGen(2)" class="map-legend-item"><span class="map-key map-key-gen2" /> {{ t('personMap.gen2') }}</span>
      <span v-if="hasApprox" class="map-legend-item"><span class="map-key map-key-approx" /> {{ t('stats.map.legendApprox') }}</span>
      <span>· {{ t('personMap.hint') }}</span>
    </p>
    <p v-if="without.length" class="field-hint">
      {{ t('personMap.withoutCoords') }}
      <template v-for="(w, i) in without" :key="w.id">
        <a href="#" @click.prevent="emit('open-place', w.id)">{{ w.full_name }}</a><template v-if="i < without.length - 1">; </template>
      </template>
    </p>
  </fieldset>
</template>
