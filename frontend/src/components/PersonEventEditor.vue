<script setup>
// Ein Ereignis bzw. Merkmal bearbeiten: Art, Wert, Datum, Ort, Details, Quellen, Beteiligte, Medien.
// Bearbeitet das übergebene Objekt direkt.
import { computed } from 'vue'
import InputText from 'openvue/inputtext'
import Select from 'openvue/select'
import Textarea from 'openvue/textarea'
import Button from 'openvue/button'
import PlacePicker from './PlacePicker.vue'
import MediaStrip from './MediaStrip.vue'
import CitationFields from './CitationFields.vue'
import AssociationFields from './AssociationFields.vue'
import { t, locale } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import {
  EVENT_TYPE_VALUES, ATTRIBUTE_VALUES, blankCitation, blankAssociation,
  hasValueField, hasDescriptor, hasDetails, datePreview, normalizeEventDate,
} from '../personModel.js'

const props = defineProps({
  event:      { type: Object, required: true },
  index:      { type: Number, required: true },   // für eindeutige IDs
  dateError:  { type: String, default: '' },
  excludeIds: { type: Array, default: () => [] }, // die Person selbst (nicht als Beteiligte wählbar)
})
// Details und Medienbereich aufgeklappt (bleibt beim Zu- und Wiederaufklappen erhalten)
// detailsOpen: undefined = automatisch (offen, wenn etwas eingetragen ist) – deshalb ohne type: Boolean
const detailsOpen = defineModel('detailsOpen', { default: undefined })
const mediaOpen   = defineModel('mediaOpen', { type: Boolean, default: false })
defineEmits(['remove', 'done'])

// Ereignisse und Merkmale mit Zwischenüberschriften. Bewusst flache Liste: Select mit Gruppen
// *und* Suchfeld wählt beim Klick auf einen Treffer die falsche Zeile.
// Innerhalb jeder Gruppe alphabetisch nach dem angezeigten Namen (sprachabhängig)
const sortedTypes = values => values
  .map(v => ({ value: v, label: t('person.eventTypes.' + v) }))
  .sort((a, b) => a.label.localeCompare(b.label, locale.value))
const eventTypeOptions = computed(() => [
  { value: '__events', label: t('person.eventGroups.events'), header: true },
  ...sortedTypes(EVENT_TYPE_VALUES),
  { value: '__attributes', label: t('person.eventGroups.attributes'), header: true },
  ...sortedTypes(ATTRIBUTE_VALUES),
])
const valuePlaceholder = type => {
  const key = 'person.eventFields.valuePlaceholders.' + type
  return t(key) === key ? '' : t(key)
}
// Details offen, wenn ausdrücklich aufgeklappt oder etwas eingetragen ist
const showDetails = computed(() => detailsOpen.value ?? hasDetails(props.event))
const evt = computed(() => props.event)
const ei  = computed(() => props.index)
</script>

<template>
  <div class="event-editor">
    <div class="event-grid">
      <div class="form-group">
        <label>{{ t('person.eventFields.typeLabel') }}</label>
        <Select v-model="evt.event_type" :options="eventTypeOptions" option-label="label" option-value="value"
          option-disabled="header" filter fluid>
          <template #option="{ option }">
            <span :class="{ 'select-group-header': option.header }">{{ option.label }}</span>
          </template>
        </Select>
      </div>
      <div v-if="hasValueField(evt.event_type)" class="form-group event-value-field">
        <label :for="'val' + ei">{{ evt.event_type === 'EVEN' ? t('person.eventFields.description') : t('person.eventFields.value') }}</label>
        <InputText :id="'val' + ei" v-model="evt.event_value" :placeholder="valuePlaceholder(evt.event_type)" fluid />
      </div>
      <div v-if="hasDescriptor(evt.event_type)" class="form-group">
        <label :for="'typ' + ei">{{ t('person.eventFields.descriptor') }}</label>
        <InputText :id="'typ' + ei" v-model="evt.event_descriptor" :placeholder="t('person.eventFields.descriptorPlaceholder')" fluid />
      </div>
      <div class="form-group">
        <label :for="'date' + ei">{{ t('person.eventFields.dateLabel') }}</label>
        <InputText :id="'date' + ei" v-model="evt.event_date" :invalid="!!dateError"
          :placeholder="t('person.eventFields.datePlaceholder')" autocomplete="off" spellcheck="false"
          class="gedcom-date" fluid @blur="normalizeEventDate(evt)" />
      </div>
      <div class="form-group">
        <label :for="'plac' + ei">{{ t('person.eventFields.placeLabel') }}</label>
        <PlacePicker :input-id="'plac' + ei" v-model:place-id="evt.place_id" v-model:place-name="evt.event_place"
          :placeholder="t('person.eventFields.placePlaceholder')" />
      </div>
    </div>
    <small v-if="dateError" class="error-msg">{{ dateError }}</small>
    <small v-else-if="datePreview(evt)" class="date-preview"><i class="pi pi-arrow-right" /> {{ t('person.eventFields.datePreview') }} <strong>{{ datePreview(evt) }}</strong></small>
    <small v-else class="field-hint">{{ t('person.eventFields.dateHint') }}</small>

    <!-- Details: Uhrzeit, Alter, Ursache, zuständige Stelle, Konfession, Notiz -->
    <div v-if="showDetails" class="event-sub">
      <div class="event-sub-title">{{ t('person.eventFields.details') }}</div>
      <div class="details-grid">
        <div v-if="evt.event_date" class="form-group">
          <label :for="'time' + ei">{{ t('person.eventFields.time') }}</label>
          <InputText :id="'time' + ei" v-model.trim="evt.event_time" placeholder="14:30" autocomplete="off" fluid />
        </div>
        <div class="form-group">
          <label :for="'age' + ei">{{ t('person.eventFields.age') }}</label>
          <InputText :id="'age' + ei" v-model.trim="evt.age" :placeholder="t('person.eventFields.agePlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label :for="'caus' + ei">{{ t('person.eventFields.cause') }}</label>
          <InputText :id="'caus' + ei" v-model.trim="evt.cause" :placeholder="t('person.eventFields.causePlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label :for="'agnc' + ei">{{ t('person.eventFields.agency') }}</label>
          <InputText :id="'agnc' + ei" v-model.trim="evt.agency" :placeholder="t('person.eventFields.agencyPlaceholder')" fluid />
        </div>
        <div v-if="evt.event_type !== 'RELI'" class="form-group">
          <label :for="'reli' + ei">{{ t('person.eventFields.religion') }}</label>
          <InputText :id="'reli' + ei" v-model.trim="evt.religion" :placeholder="t('person.eventFields.religionPlaceholder')" fluid />
        </div>
        <div class="form-group details-wide">
          <label :for="'enote' + ei">{{ t('person.eventFields.notes') }}</label>
          <Textarea :id="'enote' + ei" v-model="evt.notes" rows="1" auto-resize fluid />
        </div>
      </div>
    </div>

    <!-- Quellenangaben: nur vorhandene, neue per Knopf -->
    <div v-if="evt.citations.length" class="event-sub">
      <div class="event-sub-title">{{ t('person.citations.title') }}</div>
      <div v-for="(cit, ci) in evt.citations" :key="ci" class="sub-card">
        <CitationFields :citation="cit" :id-prefix="`e${ei}_${ci}_`" @remove="evt.citations.splice(ci, 1)" />
      </div>
    </div>

    <!-- Beteiligte Personen -->
    <div v-if="evt.associations.length" class="event-sub">
      <div class="event-sub-title">{{ t('person.associations.title') }}</div>
      <div v-for="(asso, ai) in evt.associations" :key="ai" class="sub-card">
        <AssociationFields :association="asso" :exclude-ids="excludeIds" @remove="evt.associations.splice(ai, 1)" />
      </div>
    </div>

    <!-- Medien zum Ereignis (Urkunde, Grabfoto …) -->
    <div v-if="evt.media.length || mediaOpen" class="event-sub">
      <div class="event-sub-title">{{ t('media.title') }}</div>
      <MediaStrip v-model="evt.media" />
    </div>

    <div class="event-editor-actions">
      <Button v-if="!showDetails" :label="t('person.eventFields.details')" icon="pi pi-plus" text size="small"
        @click="detailsOpen = true" />
      <Button v-if="!evt.media.length && !mediaOpen" :label="t('media.title')" icon="pi pi-image" text size="small"
        @click="mediaOpen = true" />
      <Button v-if="canEdit" :label="t('person.citations.add')" icon="pi pi-book" text size="small" @click="evt.citations.push(blankCitation())" />
      <Button v-if="canEdit" :label="t('person.associations.add')" icon="pi pi-users" text size="small" @click="evt.associations.push(blankAssociation())" />
      <span class="spacer" />
      <Button v-if="canEdit" icon="pi pi-trash" text rounded severity="danger" :aria-label="t('action.remove')"
        v-tooltip.top="t('action.remove')" @click="$emit('remove')" />
      <Button :label="t('person.nameFields.done')" icon="pi pi-check" size="small" outlined @click="$emit('done')" />
    </div>
  </div>
</template>
