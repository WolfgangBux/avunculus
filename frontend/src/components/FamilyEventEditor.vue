<script setup>
import Select from 'openvue/select'
import SourcePicker from './SourcePicker.vue'
import InputText from 'openvue/inputtext'
import Textarea from 'openvue/textarea'
import Button from 'openvue/button'
import PlacePicker from './PlacePicker.vue'
import MediaStrip from './MediaStrip.vue'
import PersonPicker from './PersonPicker.vue'
import QualitySelect from './QualitySelect.vue'
import { ref, computed } from 'vue'
import { t } from '../i18n/index.js'
import { toGedcomDate, normalizeGedcomDate, isValidGedcomDate } from '../gedcom.js'

/** Formular für ein Familienereignis inkl. Quellenangaben (wird von FamilyEvents genutzt) */
const props = defineProps({
  model:         { type: Object,  required: true },   // { event_type, event_date, place_id, event_place, Details …, citations }
  typeOptions:   { type: Array,   required: true },
  error:         { type: String,  default: '' },
  saving:        { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'cancel', 'clear-error'])

const blankCitation = () => ({ source_id: null, source_label: '', page: '', url: '', transcription: '', quality: null })

// „1960-1970“ beim Wohnort als Zeitraum, sonst als unsichere Angabe (z. B. Heirat zwischen 1960 und 1970)
const opts       = () => ({ period: props.model.event_type === 'RESI' })
const convert    = () => { props.model.event_date = toGedcomDate(props.model.event_date, opts()) }
const preview    = () => {
  const d = props.model.event_date
  if (!d?.trim()) return ''
  const out = toGedcomDate(d, opts())
  return out !== normalizeGedcomDate(d) && isValidGedcomDate(out) ? out : ''
}

// Details aufgeklappt, wenn schon etwas eingetragen ist
const DETAIL_FIELDS = ['event_time', 'husb_age', 'wife_age', 'cause', 'agency', 'religion', 'notes']
const showDetails   = ref(DETAIL_FIELDS.some(f => props.model[f]))
const showMedia     = ref(false)

// Beteiligte Personen (Trauzeugen …); Rollen wie bei Personenereignissen (GEDCOM 7 ROLE)
const ROLE_VALUES = ['WITN', 'CLERGY', 'OFFICIATOR', 'GODP', 'FATH', 'MOTH', 'PARENT', 'CHIL', 'FRIEND', 'NGHBR', 'MULTIPLE', 'OTHER']
const roleOptions = computed(() => ROLE_VALUES.map(v => ({ value: v, label: t('person.associations.roles.' + v) })))
const addAssociation = () => props.model.associations.push({ person_id: '', person_label: '', role: 'WITN', role_phrase: '' })
</script>

<template>
  <form class="fam-event-editor" novalidate @submit.prevent="emit('save')">
    <div class="fam-event-form">
      <Select v-model="model.event_type" :options="typeOptions" option-label="label" option-value="value"
        size="small" fluid :aria-label="t('family.events.type')" />
      <InputText v-model="model.event_date" size="small" fluid class="gedcom-date" :invalid="!!error"
        :placeholder="t('person.eventFields.datePlaceholder')" :aria-label="t('person.eventFields.dateLabel')"
        autocomplete="off" spellcheck="false" @input="emit('clear-error')" @blur="convert" />
      <PlacePicker v-model:place-id="model.place_id" v-model:place-name="model.event_place" size="small"
        :placeholder="t('person.eventFields.placePlaceholder')" />
      <span class="fam-event-form-actions">
        <Button type="submit" icon="pi pi-check" size="small" rounded :loading="saving" :aria-label="t('action.save')" />
        <Button icon="pi pi-times" size="small" rounded text severity="secondary" :aria-label="t('action.cancel')" @click="emit('cancel')" />
      </span>
      <small v-if="error" class="error-msg fam-event-error">{{ error }}</small>
      <small v-else-if="preview()" class="date-preview fam-event-error"><i class="pi pi-arrow-right" /> {{ t('person.eventFields.datePreview') }} <strong>{{ preview() }}</strong></small>
      <small v-else class="field-hint fam-event-error">{{ t('person.eventFields.dateHint') }}</small>
    </div>

    <!-- Sonstiges Ereignis: Beschreibung und genauere Art -->
    <div v-if="model.event_type === 'EVEN'" class="details-grid fam-even-grid">
      <div class="form-group">
        <label>{{ t('person.eventFields.description') }}</label>
        <InputText v-model="model.event_value" size="small" :placeholder="t('family.events.valuePlaceholder')" fluid />
      </div>
      <div class="form-group">
        <label>{{ t('person.eventFields.descriptor') }}</label>
        <InputText v-model="model.event_descriptor" size="small" :placeholder="t('family.events.descriptorPlaceholder')" fluid />
      </div>
    </div>

    <!-- Details: Alter beider Partner, Ursache, zuständige Stelle, Notiz -->
    <div v-if="showDetails" class="event-sub">
      <div class="event-sub-title">{{ t('person.eventFields.details') }}</div>
      <div class="fam-details-grid">
        <div v-if="model.event_date" class="form-group">
          <label>{{ t('person.eventFields.time') }}</label>
          <InputText v-model.trim="model.event_time" size="small" placeholder="14:30" autocomplete="off" fluid />
        </div>
        <div class="form-group">
          <label>{{ t('family.events.husbAge') }}</label>
          <InputText v-model="model.husb_age" size="small" :placeholder="t('person.eventFields.agePlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label>{{ t('family.events.wifeAge') }}</label>
          <InputText v-model="model.wife_age" size="small" :placeholder="t('person.eventFields.agePlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label>{{ t('person.eventFields.cause') }}</label>
          <InputText v-model="model.cause" size="small" fluid />
        </div>
        <div class="form-group">
          <label>{{ t('person.eventFields.agency') }}</label>
          <InputText v-model="model.agency" size="small" :placeholder="t('person.eventFields.agencyPlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label>{{ t('person.eventFields.religion') }}</label>
          <InputText v-model="model.religion" size="small" :placeholder="t('person.eventFields.religionPlaceholder')" fluid />
        </div>
        <div class="form-group fam-details-wide">
          <label>{{ t('person.eventFields.notes') }}</label>
          <Textarea v-model="model.notes" rows="1" auto-resize fluid />
        </div>
      </div>
    </div>

    <!-- Quellenangaben -->
    <div v-if="model.citations.length" class="event-sub">
      <div class="event-sub-title">{{ t('person.citations.title') }}</div>
      <div v-for="(cit, ci) in model.citations" :key="ci" class="sub-card">
        <div class="sub-grid">
          <div class="form-group">
            <label>{{ t('person.citations.sourceLabel') }}</label>
            <SourcePicker v-model:source-id="cit.source_id" v-model:label="cit.source_label" size="small" />
          </div>
          <div class="form-group">
            <label>{{ t('person.citations.pageLabel') }}</label>
            <InputText v-model="cit.page" size="small" :placeholder="t('person.citations.pagePlaceholder')" fluid />
          </div>
          <Button icon="pi pi-trash" severity="danger" text rounded size="small" class="sub-remove"
            :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="model.citations.splice(ci, 1)" />
          <div class="form-group">
            <label>{{ t('person.citations.quality.label') }}</label>
            <QualitySelect v-model="cit.quality" size="small" />
          </div>
          <div class="form-group sub-wide">
            <label>{{ t('person.citations.urlLabel') }}</label>
            <InputText v-model="cit.url" type="url" size="small" placeholder="https://…" fluid />
          </div>
          <div class="form-group sub-wide">
            <label>{{ t('person.citations.transcriptionLabel') }}</label>
            <Textarea v-model="cit.transcription" rows="1" auto-resize
              :placeholder="t('person.citations.transcriptionPlaceholder')" fluid />
          </div>
        </div>
      </div>
    </div>
    <!-- Beteiligte Personen (Trauzeugen …) -->
    <div v-if="model.associations.length" class="event-sub">
      <div class="event-sub-title">{{ t('person.associations.title') }}</div>
      <div v-for="(asso, ai) in model.associations" :key="ai" class="sub-card">
        <div class="sub-grid">
          <div class="form-group">
            <label>{{ t('person.associations.personLabel') }}</label>
            <PersonPicker v-model="asso.person_id" v-model:label="asso.person_label" :placeholder="t('person.associations.personNone')" />
          </div>
          <div class="form-group">
            <label>{{ t('person.associations.roleLabel') }}</label>
            <Select v-model="asso.role" :options="roleOptions" option-label="label" option-value="value"
              :placeholder="t('person.associations.roleNone')" filter show-clear size="small" fluid />
          </div>
          <Button icon="pi pi-trash" severity="danger" text rounded size="small" class="sub-remove"
            :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="model.associations.splice(ai, 1)" />
          <div v-if="asso.role === 'OTHER'" class="form-group sub-wide">
            <label>{{ t('person.associations.rolePhraseLabel') }}</label>
            <InputText v-model="asso.role_phrase" size="small" :placeholder="t('person.associations.rolePhrasePlaceholder')" fluid />
          </div>
        </div>
      </div>
    </div>

    <!-- Medien (Heiratsurkunde, Hochzeitsfoto …) -->
    <div v-if="model.media.length || showMedia" class="event-sub">
      <div class="event-sub-title">{{ t('media.title') }}</div>
      <MediaStrip v-model="model.media" />
    </div>
    <Button v-if="!showDetails" :label="t('person.eventFields.details')" icon="pi pi-plus" text size="small" class="fam-event-add"
      @click="showDetails = true" />
    <Button :label="t('person.associations.add')" icon="pi pi-users" text size="small" class="fam-event-add" @click="addAssociation" />
    <Button v-if="!model.media.length && !showMedia" :label="t('media.title')" icon="pi pi-image" text size="small" class="fam-event-add"
      @click="showMedia = true" />
    <Button :label="t('person.citations.add')" icon="pi pi-book" text size="small" class="fam-event-add"
      @click="model.citations.push(blankCitation())" />
  </form>
</template>
