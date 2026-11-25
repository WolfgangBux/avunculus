<script setup>
import { ref, computed } from 'vue'
import InputText from 'openvue/inputtext'
import FamilyEventEditor from './FamilyEventEditor.vue'
import Button from 'openvue/button'
import CitationView from './CitationView.vue'
import MediaStrip from './MediaStrip.vue'
import { addFamilyEvent, updateFamilyEvent, deleteFamilyEvent } from '../api/families.js'
import { toGedcomDate, isValidGedcomDate } from '../gedcom.js'
import { useAsk, useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { displayDate, assoRole } from '../format.js'
import { citationMediaFromApi, citationToApi } from '../personModel.js'

// muss zu FamilyController::EVENT_TYPES im Backend passen
const FAMILY_EVENT_TYPES =['MARR', 'MARB', 'MARC', 'MARL', 'ENGA', 'DIV', 'DIVF', 'ANUL', 'CENS', 'RESI', 'EVEN']

const props = defineProps({
  family: { type: Object, required: true },
  bare:   { type: Boolean, default: false },   // ohne Beschriftungsspalte (Familien-Editor)
  personLinks: { type: Boolean, default: false },   // Beteiligte anklickbar (go-person)
})
const emit  = defineEmits(['changed', 'go-person'])

const ask    = useAsk()
const notify = useNotify()

const editing = ref(null)   // { id|null, event_type, event_date, event_place, citations: [] }


const DETAIL_KEYS  = ['event_value', 'event_descriptor', 'husb_age', 'wife_age', 'cause', 'agency', 'religion', 'event_time', 'notes']
const blankDetails = () => Object.fromEntries(DETAIL_KEYS.map(k => [k, '']))

/** Titel einer Ereigniszeile: bei „Sonstiges Ereignis“ die genauere Art, falls angegeben */
const eventTitle = ev => (ev.event_type === 'EVEN' && ev.event_descriptor) || t('family.eventTypes.' + ev.event_type)

/** Tooltip für 📖: Quelle und Seite je Quellenangabe */
function citationTitle(ev) {
  return (ev.citations ?? [])
    .map(c => [c.source_abbreviation || c.source_title, c.page].filter(Boolean).join(', ') || '–')
    .join('\n')
}
// Leseansicht je Ereignis (Klick auf die Zeile): Details, Quellenangaben samt Original, Beteiligte, Medien
const expanded = ref({})
const toggle   = id => { expanded.value = { ...expanded.value, [id]: !expanded.value[id] } }
function viewFields(ev) {
  const f = (label, value, key = '') => value ? [{ key, label, value }] : []
  return [
    ...(ev.event_type === 'EVEN' ? f(t('person.eventFields.descriptor'), ev.event_descriptor) : []),
    ...f(t('person.eventFields.' + (ev.event_type === 'EVEN' ? 'description' : 'value')), ev.event_value),
    ...f(t('person.eventFields.dateLabel'), ev.event_date && displayDate(ev.event_date) + (ev.event_time ? ', ' + ev.event_time : ''), 'date'),
    ...f(t('person.eventFields.placeLabel'), ev.event_place),
    ...f(t('family.events.husbAge'), ev.husb_age), ...f(t('family.events.wifeAge'), ev.wife_age),
    ...f(t('person.eventFields.cause'), ev.cause), ...f(t('person.eventFields.agency'), ev.agency),
    ...f(t('person.eventFields.religion'), ev.religion),
    ...f(t('person.eventFields.notes'), ev.notes, 'notes'),
  ]
}
// Quellenangaben der API für CitationView (Anzeigename der Quelle)
const viewCitations = ev => (ev.citations ?? []).map(c => ({ ...c, source_label: c.source_abbreviation || c.source_title || '' }))
const hasView = ev => viewFields(ev).length || ev.citations?.length || ev.associations?.length || ev.media?.length

const error   = ref('')
const saving  = ref(false)

const typeOptions = computed(() =>
  FAMILY_EVENT_TYPES.map(v => ({ value: v, label: t('family.eventTypes.' + v) })))

const events = computed(() => props.family.events ?? [])

function icon(type) {
  if (['MARR', 'MARB', 'MARC', 'MARL', 'ENGA'].includes(type)) return 'pi pi-heart'
  if (['DIV', 'DIVF', 'ANUL'].includes(type))                 return 'pi pi-ban'
  return 'pi pi-calendar'
}

function startAdd() {
  const hasMarriage = events.value.some(e => e.event_type === 'MARR')
  editing.value = { id: null, event_type: hasMarriage ? 'DIV' : 'MARR', event_date: '', place_id: null, event_place: '', ...blankDetails(), citations: [], media: [], associations: [] }
  error.value   = ''
}

function startEdit(ev) {
  editing.value = {
    id: ev.id, event_type: ev.event_type, event_date: ev.event_date ?? '', place_id: ev.place_id ?? null, event_place: ev.event_place ?? '',
    ...Object.fromEntries(DETAIL_KEYS.map(k => [k, ev[k] ?? ''])),
    // mit IDs: das Backend gleicht ab, vorhandene Quellenangaben/Beteiligte behalten ihre ID
    citations: (ev.citations ?? []).map(c => ({
      id: c.id ?? null, source_id: c.source_id, source_label: c.source_abbreviation || c.source_title || '', page: c.page ?? '', url: c.url ?? '', transcription: c.transcription ?? '', quality: c.quality ?? null,
      media: citationMediaFromApi(c),
    })),
    media: (ev.media ?? []).map(m => ({ id: m.id, title: m.title ?? '', mime_type: m.mime_type })),
    associations: (ev.associations ?? []).map(a => ({
      id: a.id ?? null, person_id: a.person_id ?? '', person_label: a.display_name ?? '', role: a.role ?? '', role_phrase: a.role_phrase ?? '',
    })),
  }
  error.value   = ''
}

async function save() {
  const e = editing.value
  e.event_date = toGedcomDate(e.event_date, { period: e.event_type === 'RESI' })
  if (e.event_date && !isValidGedcomDate(e.event_date)) {
    error.value = t('person.eventFields.dateError')
    return
  }
  saving.value = true
  try {
    const data = {
      event_type: e.event_type, event_date: e.event_date, place_id: e.place_id, event_place: (e.event_place ?? '').trim(),
      ...Object.fromEntries(DETAIL_KEYS.map(k => [k, e[k]])),
      citations:  e.citations.map(citationToApi),
      media:      e.media.map(m => ({ id: m.id })),
      associations: e.associations
        .filter(a => a.person_id !== '' && a.person_id !== null)
        .map(a => ({ id: a.id ?? null, person_id: Number(a.person_id), role: a.role || null,
                     role_phrase: a.role === 'OTHER' ? (a.role_phrase.trim() || null) : null })),
    }
    if (e.id) await updateFamilyEvent(props.family.id, e.id, data)
    else      await addFamilyEvent(props.family.id, data)
    editing.value = null
    emit('changed')
  } catch {
    notify.error(t('action.saveError'))
  } finally {
    saving.value = false
  }
}

async function remove(ev) {
  if (!(await ask(t('family.events.deleteConfirm', { type: t('family.eventTypes.' + ev.event_type) })))) return
  try {
    await deleteFamilyEvent(props.family.id, ev.id)
    emit('changed')
  } catch {
    notify.error(t('family.error.delete'))
  }
}
</script>

<template>
  <div :class="bare ? 'fam-events-bare' : 'fam-row'">
    <span v-if="!bare" class="fam-row-label">{{ t('family.events.label') }}</span>
    <div class="fam-events">
      <template v-for="ev in events" :key="ev.id">
        <!-- das gerade bearbeitete Ereignis wird an seiner Stelle durch das Formular ersetzt -->
        <div v-if="editing?.id !== ev.id" :class="['fam-event', { expanded: expanded[ev.id] }]" role="button" tabindex="0"
          :aria-expanded="!!expanded[ev.id]" @click="toggle(ev.id)" @keydown.enter.self="toggle(ev.id)">
          <i :class="['fam-event-icon', icon(ev.event_type)]" />
          <span class="fam-event-type">{{ eventTitle(ev) }}</span>
          <strong v-if="ev.event_value" class="fam-event-value">{{ ev.event_value }}</strong>
          <span v-if="ev.event_date" class="gedcom-date" :title="ev.event_date">{{ displayDate(ev.event_date) }}<template v-if="ev.event_time">, {{ ev.event_time }}</template></span>
          <span v-if="ev.event_place" class="muted fam-event-place">{{ ev.event_place }}</span>
          <span v-if="ev.husb_age || ev.wife_age" class="muted fam-event-place">
            ({{ [ev.husb_age, ev.wife_age].filter(Boolean).join(' / ') }})
          </span>
          <span v-if="ev.notes" class="fam-event-sources" v-tooltip.top="ev.notes"><i class="pi pi-comment" /></span>
          <span v-if="ev.citation_count" class="fam-event-sources" v-tooltip.top="citationTitle(ev)">
            <i class="pi pi-book" /> {{ ev.citation_count }}
          </span>
          <span v-if="ev.associations?.length" class="fam-event-sources"
            v-tooltip.top="ev.associations.map(a => a.display_name + (assoRole(a) ? ' (' + assoRole(a) + ')' : '')).join('\n')">
            <i class="pi pi-users" /> {{ ev.associations.length }}
          </span>
          <span v-if="ev.media?.length" class="fam-event-sources" v-tooltip.top="ev.media.map(m => m.title || '–').join('\n')">
            <i class="pi pi-image" /> {{ ev.media.length }}
          </span>
          <span class="fam-event-actions" @click.stop>
            <button v-if="canEdit" class="icon-btn" :title="t('action.edit')" :aria-label="t('action.edit')" @click="startEdit(ev)"><i class="pi pi-pencil" /></button>
            <button v-if="canEdit" class="icon-btn danger" :title="t('action.delete')" :aria-label="t('action.delete')" @click="remove(ev)"><i class="pi pi-trash" /></button>
            <i :class="['pi', expanded[ev.id] ? 'pi-chevron-up' : 'pi-chevron-down', 'event-chevron']" />
          </span>

          <!-- Leseansicht: alles zum Ereignis, ohne Eingabefelder -->
          <div v-if="expanded[ev.id]" class="fam-event-view" @click.stop>
            <dl v-if="viewFields(ev).length" class="view-fields">
              <template v-for="f in viewFields(ev)" :key="f.label">
                <dt>{{ f.label }}</dt><dd :class="{ 'gedcom-date': f.key === 'date', 'view-notes': f.key === 'notes' }">{{ f.value }}</dd>
              </template>
            </dl>
            <div v-if="ev.citations?.length" class="event-sub">
              <div class="event-sub-title">{{ t('person.citations.title') }}</div>
              <CitationView v-for="cit in viewCitations(ev)" :key="cit.id" :citation="cit" />
            </div>
            <div v-if="ev.associations?.length" class="event-sub">
              <div class="event-sub-title">{{ t('person.associations.title') }}</div>
              <div class="relations-chips">
                <button v-for="a in ev.associations" :key="a.id" type="button" class="rel-chip rel-chip-sm"
                  :disabled="!personLinks || !a.person_id" @click="emit('go-person', a.person_id)">
                  <span class="rel-name">{{ a.display_name || '–' }}</span>
                  <span v-if="assoRole(a)" class="rel-years">{{ assoRole(a) }}</span>
                </button>
              </div>
            </div>
            <div v-if="ev.media?.length" class="event-sub">
              <div class="event-sub-title">{{ t('media.title') }}</div>
              <MediaStrip :model-value="ev.media" readonly />
            </div>
            <p v-if="!hasView(ev)" class="field-hint">{{ t('person.eventFields.noDetails') }}</p>
          </div>
        </div>
        <FamilyEventEditor v-else :model="editing" :type-options="typeOptions"
          :error="error" :saving="saving"
          @save="save" @cancel="editing = null" @clear-error="error = ''" />
      </template>

      <FamilyEventEditor v-if="editing && !editing.id" :model="editing" :type-options="typeOptions"
        :error="error" :saving="saving"
        @save="save" @cancel="editing = null" @clear-error="error = ''" />

      <span v-if="!events.length && !editing" class="muted">–</span>
      <Button v-if="canEdit && (!editing)" :label="t('family.events.add')" icon="pi pi-plus" text size="small"
        class="fam-event-add" @click="startAdd" />
    </div>
  </div>
</template>
