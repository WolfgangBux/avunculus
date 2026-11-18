<script setup>
// Ereignisse einer Person: eine Zeile je Ereignis, Klick klappt die Leseansicht auf, der Stift den Editor.
// Auf Wunsch dazwischen Ereignisse der Familie (Heirat, Kinder, Tod von Partner/Eltern).
import { ref, computed } from 'vue'
import Button from 'openvue/button'
import ToggleSwitch from 'openvue/toggleswitch'
import MediaStrip from './MediaStrip.vue'
import CitationView from './CitationView.vue'
import PersonEventEditor from './PersonEventEditor.vue'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { displayDate, assoRole } from '../format.js'
import { useRelativeEvents } from '../composables/relativeEvents.js'
import { blankEvent, eventSymbol, eventIcon, eventTitle, viewFields, filledCitations } from '../personModel.js'

const events   = defineModel({ type: Array, required: true })
const open     = defineModel('open', { default: null })            // Index des Ereignisses im Editor
const expanded = defineModel('expanded', { type: Object, default: () => ({}) })   // Leseansicht je Index
const props = defineProps({
  personId: { type: Number, default: null },   // null = neue Person
  errors:   { type: Array, default: () => [] }, // je Ereignis { event_date? }
  refresh:  Number,                             // Beziehungen anderswo geändert
})
const emit = defineEmits(['go-person', 'go-family'])

// Je Ereignis: Details bzw. Medienbereich ausdrücklich aufgeklappt
const detailsOpen = ref({})
const mediaOpen   = ref({})

const { showRelativeEvents, relativeEventCount, eventRows } =
  useRelativeEvents(() => props.personId, events, () => props.refresh)

const excludeIds  = computed(() => props.personId ? [props.personId] : [])
const assoLabel   = a => assoRole(a) ?? t('person.associations.roleNone')
const citationCount = evt => filledCitations(evt.citations).length
const toggle = ei => { expanded.value = { ...expanded.value, [ei]: !expanded.value[ei] } }

function addEvent() { events.value.push(blankEvent('EVEN')); open.value = events.value.length - 1 }
function removeEvent(ei) {
  events.value.splice(ei, 1)
  open.value = null
  expanded.value = {}   // Indizes haben sich verschoben
}
const goRel = rel => rel.family ? emit('go-family', rel.familyId) : emit('go-person', rel.personId)
</script>

<template>
  <fieldset>
    <legend>{{ t('person.sections.events') }}</legend>

    <label v-if="personId" class="rel-events-toggle" v-tooltip.left="t('relEvents.toggleHint')">
      <ToggleSwitch v-model="showRelativeEvents" />
      {{ t('relEvents.toggle') }}
      <span v-if="showRelativeEvents && relativeEventCount" class="muted">({{ relativeEventCount }})</span>
    </label>

    <ul class="event-list">
      <template v-for="{ evt, ei, rel } in eventRows" :key="rel ? 'rel' + rel.type + rel.date + (rel.personId ?? rel.familyId) : ei">
      <!-- Ereignis aus der Familie (nur lesen; Klick öffnet Person bzw. Familie) -->
      <li v-if="rel" class="event-item event-rel">
        <div class="event-line" role="button" tabindex="0" @click="goRel(rel)" @keydown.enter="goRel(rel)">
          <span class="event-icon">
            <template v-if="rel.symbol">{{ rel.symbol }}</template>
            <i v-else :class="['pi', rel.family ? 'pi-heart' : 'pi-calendar']" />
          </span>
          <span class="event-type-label" :title="rel.label">{{ rel.label }}</span>
          <span :class="['event-date', 'gedcom-date', { muted: !rel.date }]">{{ rel.date ? displayDate(rel.date) : t('person.eventFields.noDate') }}</span>
          <span class="event-place">
            <strong v-if="rel.name" class="event-value">{{ rel.family ? t('relEvents.with', { name: rel.name }) : rel.name }}</strong>
            <template v-if="rel.name && rel.place"> · </template>{{ rel.place }}
          </span>
          <span class="event-badges">
            <span v-if="rel.age !== null" class="event-badge" :title="t('relEvents.ageHint')">{{ t('relEvents.age', { age: rel.age }) }}</span>
          </span>
          <span class="name-line-actions">
            <i :class="['pi', rel.family ? 'pi-sitemap' : 'pi-user', 'event-chevron']" />
          </span>
        </div>
      </li>

      <li v-else :class="['event-item', { open: open === ei, viewing: open !== ei && expanded[ei], invalid: errors[ei]?.event_date }]">
        <!-- Zeile -->
        <div v-if="open !== ei" class="event-line" role="button" tabindex="0" :aria-expanded="!!expanded[ei]"
          @click="toggle(ei)" @keydown.enter="toggle(ei)">
          <span class="event-icon">
            <template v-if="eventSymbol(evt.event_type)">{{ eventSymbol(evt.event_type) }}</template>
            <i v-else :class="['pi', eventIcon(evt.event_type)]" />
          </span>
          <span class="event-type-label" :title="t('person.eventTypes.' + evt.event_type)">{{ eventTitle(evt) }}</span>
          <span :class="['event-date', 'gedcom-date', { muted: !evt.event_date }]">{{ evt.event_date ? displayDate(evt.event_date) : t('person.eventFields.noDate') }}<template v-if="evt.event_date && evt.event_time">, {{ evt.event_time }}</template></span>
          <span class="event-place">
            <strong v-if="evt.event_value" class="event-value">{{ evt.event_value }}</strong>
            <template v-if="evt.event_value && evt.event_place"> · </template>{{ evt.event_place }}
            <template v-if="evt.cause"> · {{ evt.cause }}</template>
          </span>
          <span class="event-badges">
            <span v-if="citationCount(evt)" class="event-badge" :title="t('person.citations.title')"><i class="pi pi-book" />{{ citationCount(evt) }}</span>
            <span v-if="evt.associations.length" class="event-badge" :title="t('person.associations.title')"><i class="pi pi-users" />{{ evt.associations.length }}</span>
            <span v-if="evt.media.length" class="event-badge" :title="t('media.title')"><i class="pi pi-image" />{{ evt.media.length }}</span>
            <span v-if="evt.notes" class="event-badge" :title="evt.notes"><i class="pi pi-comment" /></span>
          </span>
          <span class="name-line-actions" @click.stop>
            <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" :aria-label="t('action.edit')" v-tooltip.top="t('action.edit')" @click="open = ei" />
            <Button v-if="canEdit" icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="removeEvent(ei)" />
            <i :class="['pi', expanded[ei] ? 'pi-chevron-up' : 'pi-chevron-down', 'event-chevron']" />
          </span>
        </div>

        <!-- Leseansicht: alles zum Ereignis, ohne Eingabefelder -->
        <div v-if="open !== ei && expanded[ei]" class="event-view">
          <dl v-if="viewFields(evt).length" class="view-fields">
            <template v-for="f in viewFields(evt)" :key="f.key">
              <dt>{{ f.label }}</dt><dd :class="{ 'gedcom-date': f.key === 'dateLabel', 'view-notes': f.key === 'notes' }">{{ f.value }}</dd>
            </template>
          </dl>
          <div v-if="filledCitations(evt.citations).length" class="event-sub">
            <div class="event-sub-title">{{ t('person.citations.title') }}</div>
            <CitationView v-for="(cit, ci) in filledCitations(evt.citations)" :key="ci" :citation="cit" />
          </div>
          <div v-if="evt.associations.length" class="event-sub">
            <div class="event-sub-title">{{ t('person.associations.title') }}</div>
            <div class="relations-chips">
              <button v-for="(a, ai) in evt.associations" :key="ai" type="button" class="rel-chip rel-chip-sm"
                :disabled="!a.person_id" @click="$emit('go-person', a.person_id)">
                <span class="rel-name">{{ a.person_label || '–' }}</span>
                <span v-if="assoLabel(a)" class="rel-years">{{ assoLabel(a) }}</span>
              </button>
            </div>
          </div>
          <div v-if="evt.media.length" class="event-sub">
            <div class="event-sub-title">{{ t('media.title') }}</div>
            <MediaStrip :model-value="evt.media" readonly />
          </div>
          <p v-if="!viewFields(evt).length && !filledCitations(evt.citations).length && !evt.associations.length && !evt.media.length"
            class="field-hint">{{ t('person.eventFields.noDetails') }}</p>
        </div>

        <PersonEventEditor v-if="open === ei" :event="evt" :index="ei" :date-error="errors[ei]?.event_date ?? ''"
          :exclude-ids="excludeIds" v-model:details-open="detailsOpen[ei]" v-model:media-open="mediaOpen[ei]"
          @remove="removeEvent(ei)" @done="open = null" />
      </li>
      </template>
    </ul>

    <Button v-if="canEdit" :label="t('person.eventFields.addBtn')" icon="pi pi-plus" text size="small" @click="addEvent" />
  </fieldset>
</template>
