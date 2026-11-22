<script setup>
import { computed } from 'vue'
import { mediaThumbUrl } from '../api/media.js'
import { t } from '../i18n/index.js'
import { nameParts, sexClass, displayDate } from '../format.js'
import { GEDCOM_MONTHS } from '../gedcom.js'

/**
 * Info-Karte beim Verweilen über einer Person im Stammbaum.
 * Zeigt sofort die schlanken Daten aus dem Stammbaum und – sobald geladen – die volle Person.
 */
const props = defineProps({
  person:  { type: Object, required: true },   // schlanke Person aus dem Stammbaum
  details: { type: Object, default: null },    // volle Person (GET /persons/:id), sobald geladen
  rect:    { type: Object, required: true },   // Bildschirmrechteck der Karte im Baum
})

const WIDTH  = 320
const MARGIN = 10

// Rechts neben der Karte, sonst links; senkrecht im Fenster halten
const style = computed(() => {
  const { left, right, top } = props.rect
  const x = right + MARGIN + WIDTH <= window.innerWidth - 8 ? right + MARGIN : Math.max(8, left - MARGIN - WIDTH)
  const y = Math.max(8, Math.min(top - 8, window.innerHeight - 340))
  return { left: x + 'px', top: y + 'px', width: WIDTH + 'px' }
})

const p     = computed(() => props.person)
const parts = computed(() => nameParts(p.value))
const nickname = computed(() => props.details?.names?.[0]?.nickname ?? p.value.nickname ?? '')
const suffix   = computed(() => props.details?.names?.[0]?.name_suffix ?? '')

// Weitere Namen (Heiratsname, Alias …)
const otherNames = computed(() => (props.details?.names ?? []).slice(1).map(n => ({
  type: n.name_type ? t(`person.nameTypes.${n.name_type}`) : '',
  name: [n.name_prefix, n.given_name, n.surname_prefix, n.surname, n.name_suffix].filter(Boolean).join(' '),
})).filter(n => n.name))

const typeLabel = type => {
  const key = `person.eventTypes.${type}`
  const label = t(key)
  return label === key ? type : label.replace(/\s*\([A-Z]+\)$/, '')
}
// „Taimering, Riekofen, Regensburg, Oberpfalz, …“ → die ersten drei Glieder
const shortPlace = pl => {
  if (!pl) return ''
  const bits = pl.split(',').map(s => s.trim())
  return bits.length > 3 ? bits.slice(0, 3).join(', ') + '\u00a0…' : pl
}

const MAX_EVENTS = 10
const events = computed(() => {
  const list = props.details?.events ?? [
    { event_type: 'BIRT', event_date: p.value.birth_date, event_place: p.value.birth_place },
    { event_type: 'DEAT', event_date: p.value.death_date, event_place: p.value.death_place },
  ]
  return list
    .filter(e => e.event_date || e.event_place || e.event_value || e.event_descriptor)
    .map(e => ({
      label: typeLabel(e.event_type) + (e.event_descriptor ? ` (${e.event_descriptor})` : ''),
      date:  e.event_date ? displayDate(e.event_date) : '',
      value: e.event_value ?? '',
      place: shortPlace(e.event_place),
      fullPlace: e.event_place ?? '',
    }))
})

// Alter beim Tod (nur bei genauen Daten bzw. Jahren)
function parseDate(raw) {
  const m = raw?.match(/^(?:(\d{1,2}) )?(?:([A-Z]{3}) )?(\d{3,4})$/)
  if (!m) return null
  return { y: +m[3], m: m[2] ? GEDCOM_MONTHS.indexOf(m[2]) : null, d: m[1] ? +m[1] : null }
}
const ageAtDeath = computed(() => {
  const b = parseDate(p.value.birth_date), d = parseDate(p.value.death_date)
  if (!b || !d) return null
  let age = d.y - b.y
  if (b.m == null || d.m == null) return { age, approx: true }
  if (d.m < b.m || (d.m === b.m && b.d != null && d.d != null && d.d < b.d)) age--
  return { age, approx: b.d == null || d.d == null }
})

const notes = computed(() => {
  const n = props.details?.notes?.trim()
  return n ? (n.length > 220 ? n.slice(0, 220).trimEnd() + ' …' : n) : ''
})
const moreEvents = computed(() => Math.max(0, events.value.length - MAX_EVENTS))
const counts = computed(() => {
  const d = props.details
  if (!d) return ''
  const c = (d.citations?.length ?? 0) + (d.events ?? []).reduce((n, e) => n + (e.citations?.length ?? 0), 0)
  const m = d.media?.length ?? 0
  return [c && t(c === 1 ? 'tree.hover.source' : 'tree.hover.sources', { n: c }),
          m && t(m === 1 ? 'tree.hover.medium' : 'tree.hover.media', { n: m })].filter(Boolean).join(' · ')
})
</script>

<template>
  <Teleport to="body">
    <div :class="['tree-hover', sexClass(p)]" :style="style" role="tooltip">
      <div class="tree-hover-head">
        <div :class="['avatar', 'tree-hover-avatar', { 'avatar-photo': p.photo_id }]" aria-hidden="true">
          <img v-if="p.photo_id" :src="mediaThumbUrl(p.photo_id, p.photo_crop)" alt="" />
          <svg v-else viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="12" r="6"/><path d="M4 32c0-7 5.4-11 12-11s12 4 12 11z"/></svg>
        </div>
        <div class="tree-hover-name">
          <div class="tree-hover-given">
            {{ parts.given || '–' }}<span v-if="nickname" class="tree-hover-nick"> „{{ nickname }}“</span>
          </div>
          <div class="tree-hover-surname">{{ [parts.surname, suffix].filter(Boolean).join(' ') }}</div>
          <div v-if="ageAtDeath" class="tree-hover-age">
            {{ t(ageAtDeath.approx ? 'tree.hover.ageApprox' : 'tree.hover.age', { n: ageAtDeath.age }) }}
          </div>
        </div>
      </div>

      <ul v-if="otherNames.length" class="tree-hover-names">
        <li v-for="(n, i) in otherNames" :key="i"><span class="tree-hover-label">{{ n.type }}</span>{{ n.name }}</li>
      </ul>

      <ul v-if="events.length" class="tree-hover-events">
        <li v-for="(e, i) in events.slice(0, MAX_EVENTS)" :key="i">
          <span class="tree-hover-label">{{ e.label }}</span>
          <span class="tree-hover-ev">
            <span v-if="e.value">{{ e.value }}</span>
            <span v-if="e.date" class="tree-hover-date">{{ e.date }}</span>
            <span v-if="e.place" class="tree-hover-place" :title="e.fullPlace">{{ e.place }}</span>
          </span>
        </li>
      </ul>
      <p v-else class="tree-hover-empty">{{ t('tree.hover.noData') }}</p>
      <p v-if="moreEvents" class="tree-hover-more">{{ t('tree.hover.more', { n: moreEvents }) }}</p>

      <p v-if="notes" class="tree-hover-notes">{{ notes }}</p>
      <div v-if="counts" class="tree-hover-foot">{{ counts }}</div>
      <div v-else-if="!details && !p.private" class="tree-hover-foot tree-hover-loading"><i class="pi pi-spin pi-spinner" /></div>
    </div>
  </Teleport>
</template>
