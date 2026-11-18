<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import SelectButton from 'openvue/selectbutton'
import Slider from 'openvue/slider'
import { fetchStatisticsMap } from '../api/statistics.js'
import { currentTreeId } from '../trees.js'
import { t, locale } from '../i18n/index.js'

/**
 * Karte der Orte eines Stammbaums (Leaflet, OpenStreetMap-Kacheln).
 * Kreisgröße ∝ Wurzel der Anzahl Ereignisse (Fläche ∝ Anzahl); Filter nach Art und Zeitraum.
 * Hohle Kreise: Lage nur ungefähr (Koordinaten der Gemeinde).
 */
const emit = defineEmits(['open-place'])

const el      = ref(null)
const data    = ref(null)      // { places: [[id, name, full, lat, lon, approx]], counts: [[idx, kind, decade, n]], without_coords }
const error   = ref('')
const kind    = ref('ALL')
const range   = ref([0, 0])    // Jahrzehnte [von, bis]
let map = null, layer = null, fitted = false

const kindOptions = computed(() => ['ALL', 'B', 'D', 'M'].map(k => ({ value: k, label: t('stats.map.kinds.' + k) })))

const decades = computed(() => {
  const ds = (data.value?.counts ?? []).map(c => c[2]).filter(d => d > 0)
  if (!ds.length) return null
  // robuste Grenzen wie bei der Statistik: einzelne Ausreißer abschneiden
  ds.sort((a, b) => a - b)
  const cut = Math.floor(ds.length * 0.002)
  return { min: ds[cut], max: ds[ds.length - 1 - cut] }
})
const fullRange = computed(() => decades.value && range.value[0] <= decades.value.min && range.value[1] >= decades.value.max)

/** Summen je Ort für die aktuelle Auswahl */
const totals = computed(() => {
  const out = new Map()
  for (const [idx, k, dec, n] of data.value?.counts ?? []) {
    if (kind.value !== 'ALL' && k !== kind.value) continue
    if (!fullRange.value && (dec === 0 || dec < range.value[0] || dec > range.value[1])) continue
    const o = out.get(idx) ?? { n: 0, B: 0, D: 0, M: 0, O: 0 }
    o.n += n; o[k] += n
    out.set(idx, o)
  }
  return out
})
const shown = computed(() => {
  let ev = 0
  for (const o of totals.value.values()) ev += o.n
  return { places: totals.value.size, events: ev }
})

const fmt = n => n.toLocaleString(locale.value)
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim()

/**
 * Ausschnitt für den Start: Gebiet der mittleren 95 % aller Ereignisse (nach Anzahl gewichtet),
 * damit einzelne Auswanderer nach Amerika nicht die ganze Welt ins Bild holen
 */
function coreBounds(entries) {
  const q = (axis) => {
    const pts = entries.map(([idx, o]) => [data.value.places[idx][axis], o.n]).sort((a, b) => a[0] - b[0])
    const total = pts.reduce((s, p) => s + p[1], 0)
    let acc = 0, lo = pts[0][0], hi = pts[pts.length - 1][0]
    for (const [v, w] of pts) { acc += w; if (acc >= total * 0.025) { lo = v; break } }
    acc = 0
    for (const [v, w] of [...pts].reverse()) { acc += w; if (acc >= total * 0.025) { hi = v; break } }
    return [lo, hi]
  }
  const [la0, la1] = q(3), [lo0, lo1] = q(4)
  return L.latLngBounds([la0, lo0], [la1, lo1])
}

function draw() {
  if (!map || !data.value) return
  layer?.remove()
  layer = L.layerGroup()
  const primary = css('--color-primary') || '#e8641b'
  const surface = css('--color-surface') || '#fff'
  const max = Math.max(1, ...[...totals.value.values()].map(o => o.n))
  // große Kreise zuerst, damit kleine darüber liegen und anklickbar bleiben
  const entries = [...totals.value.entries()].sort((a, b) => b[1].n - a[1].n)
  for (const [idx, o] of entries) {
    const [id, name, full, lat, lon, approx] = data.value.places[idx]
    const m = L.circleMarker([lat, lon], {
      radius: 3 + 22 * Math.sqrt(o.n / max),
      color: approx ? primary : surface, weight: approx ? 1.5 : 1, dashArray: approx ? '3 2' : null,
      fillColor: primary, fillOpacity: approx ? 0.15 : 0.55,
    })
    m.bindTooltip(`<strong>${esc(name)}</strong> · ${fmt(o.n)}`, { direction: 'top', offset: [0, -4] })
    const parts = ['B', 'D', 'M', 'O'].filter(k => o[k]).map(k => `${esc(t('stats.map.kinds.' + k))}: ${fmt(o[k])}`)
    m.bindPopup(`<div class="map-popup"><strong>${esc(full || name)}</strong>
      ${approx ? `<div class="muted">${esc(t('stats.map.approx'))}</div>` : ''}
      <div>${parts.join('<br>')}</div>
      <button type="button" class="map-open" data-place="${id}">${esc(t('stats.map.openPlace'))}</button></div>`)
    layer.addLayer(m)
  }
  layer.addTo(map)
  if (!fitted && entries.length) {
    map.fitBounds(coreBounds(entries), { padding: [20, 20], maxZoom: 11 })
    fitted = true
  }
}

async function load() {
  error.value = ''
  fitted = false
  try {
    data.value = await fetchStatisticsMap()
    if (decades.value) range.value = [decades.value.min, decades.value.max]
  } catch (e) { error.value = e?.error || t('stats.loadError') }
}

onMounted(async () => {
  map = L.map(el.value, { preferCanvas: true, scrollWheelZoom: false, worldCopyJump: true })
  map.setView([48.7, 13.5], 7)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
  }).addTo(map)
  // Mausrad erst nach Klick in die Karte – sonst bleibt man beim Scrollen der Seite hängen
  map.on('focus click', () => map.scrollWheelZoom.enable())
  map.on('blur mouseout', () => map.scrollWheelZoom.disable())
  // „Ort öffnen“ im Popup
  el.value.addEventListener('click', e => {
    const btn = e.target.closest?.('.map-open')
    if (btn) emit('open-place', Number(btn.dataset.place))
  })
  await load()
  await nextTick()
  draw()
})
onBeforeUnmount(() => { map?.remove(); map = null })

watch(currentTreeId, async () => { await load(); draw() })
watch(totals, draw)
</script>

<template>
  <div class="place-map">
    <div class="map-filters">
      <SelectButton v-model="kind" :options="kindOptions" option-label="label" option-value="value" :allow-empty="false" size="small" />
      <div v-if="decades" class="map-range">
        <span class="map-range-label">{{ range[0] }}–{{ range[1] + 9 }}</span>
        <Slider v-model="range" range :min="decades.min" :max="decades.max" :step="10" class="map-slider" />
      </div>
    </div>
    <p v-if="error" class="alert">{{ error }}</p>
    <div ref="el" class="map-canvas" />
    <p v-if="data" class="stats-sub map-legend">
      <span class="map-key map-key-solid" /> {{ t('stats.map.legendExact') }}
      <span class="map-key map-key-approx" /> {{ t('stats.map.legendApprox') }}
      · {{ t('stats.map.shown', { places: fmt(shown.places), events: fmt(shown.events) }) }}
      <template v-if="!fullRange"> · {{ t('stats.map.undatedHidden') }}</template>
      <template v-if="data.without_coords"> · {{ t('stats.map.withoutCoords', { n: fmt(data.without_coords) }) }}</template>
    </p>
  </div>
</template>
