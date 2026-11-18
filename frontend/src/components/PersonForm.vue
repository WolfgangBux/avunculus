<script setup>
// Person anzeigen und bearbeiten. Die Abschnitte (Namen, Ereignisse, Quellen, verknüpfte Personen)
// sind eigene Komponenten; hier: Kopf, Laden, Prüfen, Speichern, Wechsel zu Verwandten.
import { ref, reactive, computed, watch, onMounted } from 'vue'
import MultiSelect  from 'openvue/multiselect'
import Textarea     from 'openvue/textarea'
import Button       from 'openvue/button'
import LastChange from './LastChange.vue'
import SexSelect from './SexSelect.vue'
import PersonNames        from './PersonNames.vue'
import PersonEvents       from './PersonEvents.vue'
import PersonCitations    from './PersonCitations.vue'
import PersonAssociations from './PersonAssociations.vue'
import PersonRelations    from './PersonRelations.vue'
import PersonPlacesMap    from './PersonPlacesMap.vue'
import MediaStrip         from './MediaStrip.vue'
import { createPerson, updatePerson } from '../api/persons.js'
import { mediaThumbUrl } from '../api/media.js'
import { isValidGedcomDate, extractYear } from '../gedcom.js'
import { t } from '../i18n/index.js'
import { displayDate } from '../format.js'
import { canEdit, isAdmin } from '../auth.js'
import { useNotify, useAsk } from '../composables/feedback.js'
import {
  RESTRICTION_VALUES, personFromApi, personToApi, normalizeEventDate, fullName, listName,
} from '../personModel.js'

const props = defineProps({
  editPerson:    { type: Object, default: null },
  startPersonId: { type: Number, default: null },   // Startperson des Baums
  refresh:       Number,   // ändert sich, wenn Beziehungen anderswo geändert wurden
})
const emit = defineEmits(['person-saved', 'cancel-edit', 'edit-person', 'edit-family', 'relations', 'relatives-tree', 'set-start', 'open-place'])

const notify = useNotify()
const ask    = useAsk()

// ── Zustand ───────────────────────────────────────────────
const form    = ref(personFromApi(null))   // sex, restriction, notes, names, events, citations, associations, media
const loading = ref(false)
const isEditing = computed(() => props.editPerson !== null)
const personId  = computed(() => props.editPerson ? Number(props.editPerson.id) : null)

// Welcher Name / welches Ereignis ist zum Bearbeiten aufgeklappt, was ist in der Leseansicht offen
const openName     = ref(0)
const openEvent    = ref(0)
const expanded     = ref({})   // Ereignisse
const assoExpanded = ref({})   // verknüpfte Personen
// Neu aufbauen beim Laden: setzt den Bedienzustand der Abschnitte zurück (z. B. aufgeklappte Quellen)
const formKey = ref(0)

// Fehler
const errors      = reactive({})
const nameErrors  = ref([])
const eventErrors = ref([])
function clearErrors() {
  Object.keys(errors).forEach(k => delete errors[k])
  nameErrors.value  = []
  eventErrors.value = []
}

// Ungespeicherte Änderungen erkennen
const snapshot     = ref('')
const takeSnapshot = () => { snapshot.value = JSON.stringify(form.value) }
const dirty        = computed(() => JSON.stringify(form.value) !== snapshot.value)

/** Person ins Formular laden (null = leeres Formular für eine neue Person) */
function load(person) {
  form.value         = personFromApi(person)
  openName.value     = person ? null : 0
  openEvent.value    = person ? null : 0
  expanded.value     = {}
  assoExpanded.value = {}
  formKey.value++
  clearErrors()
  takeSnapshot()
}
// auch beim (Wieder-)Aufbau, z. B. nach Tab-Wechsel
watch(() => props.editPerson, load, { immediate: true })
onMounted(takeSnapshot)

// ── Kopf ──────────────────────────────────────────────────
const restrictionOptions = computed(() => RESTRICTION_VALUES.map(v => ({ value: v, label: t('person.restriction.' + v) })))
const photo      = computed(() => form.value.media.find(m => m.is_primary) ?? null)
const headerName = computed(() => fullName(form.value.names[0] ?? {}) || t('person.unnamed'))
const headerLife = computed(() => {
  const date = type => form.value.events.find(e => e.event_type === type && e.event_date)?.event_date
  const b = date('BIRT') ?? date('BAPM')
  const d = date('DEAT') ?? date('BURI')
  return [b && `* ${displayDate(b)}`, d && `† ${displayDate(d)}`].filter(Boolean).join('   ')
})

// „Alles aufklappen“: Ereignisse und verknüpfte Personen in der Leseansicht
const allExpanded = computed(() => form.value.events.length > 0
  && form.value.events.every((_, i) => expanded.value[i])
  && form.value.associations.every((_, i) => assoExpanded.value[i]))
function toggleAll() {
  const open = !allExpanded.value
  expanded.value     = Object.fromEntries(form.value.events.map((_, i) => [i, open]))
  assoExpanded.value = Object.fromEntries(form.value.associations.map((_, i) => [i, open]))
}

// ── Prüfen und Speichern ──────────────────────────────────
function validate() {
  clearErrors()
  let ok = true
  const { names, events } = form.value

  if (!form.value.sex) { errors.sex = t('sex.error'); ok = false }

  nameErrors.value = names.map((name, i) => {
    if (i > 0 || name.given_name.trim() || name.surname.trim()) return {}
    ok = false
    return { given_name: t('person.nameFields.primaryRequired'), surname: t('person.nameFields.primaryRequired') }
  })

  eventErrors.value = events.map(evt => {
    const val = evt.event_date.trim()
    if (!val || isValidGedcomDate(val)) return {}
    ok = false
    return { event_date: t('person.eventFields.dateError') }
  })

  const birt = events.find(e => e.event_type === 'BIRT')
  const deat = events.find(e => e.event_type === 'DEAT')
  if (birt && deat) {
    const by = extractYear(birt.event_date)
    const dy = extractYear(deat.event_date)
    if (by && dy && by > dy) {
      const di = events.indexOf(deat)
      eventErrors.value[di] = { ...eventErrors.value[di], event_date: t('person.eventFields.yearOrderError') }
      ok = false
    }
  }

  // Fehlerhaftes gleich zum Bearbeiten aufklappen
  if (nameErrors.value[0]?.given_name) openName.value = 0
  const firstBad = eventErrors.value.findIndex(e => e?.event_date)
  if (firstBad >= 0) openEvent.value = firstBad

  return ok
}

async function submit() {
  form.value.events.forEach(normalizeEventDate)
  if (!validate()) return
  loading.value = true
  try {
    const person = isEditing.value
      ? await updatePerson(props.editPerson.id, personToApi(form.value))
      : await createPerson(personToApi(form.value))
    notify.success(t('person.saved', { name: listName(person) }))
    emit('person-saved', person)
    if (!isEditing.value) load(null)   // nächste neue Person
    takeSnapshot()
  } catch {
    notify.error(t('action.saveError'))
  } finally {
    loading.value = false
  }
}

function reset() {
  if (isEditing.value) emit('cancel-edit')
  else load(null)
}

// ── Wechsel zu Verwandten – bei ungespeicherten Änderungen erst nachfragen ──
async function leaveTo(event, payload) {
  if (dirty.value && !(await ask(t('person.discardConfirm'), { acceptLabel: t('person.discard') }))) return
  emit(event, payload)
}
const goPerson = id => leaveTo('edit-person', id)
const goFamily = id => leaveTo('edit-family', id)
const goPlace  = id => leaveTo('open-place', id)
// Neuer Stammbaum mit allen Verwandten dieser Person (nur Administratoren legen Bäume an)
const relativesTree = () => emit('relatives-tree', { id: personId.value, name: headerName.value })
</script>

<template>
<div class="card person-form">
  <form :class="{ 'read-only': !canEdit }" @submit.prevent="submit" novalidate>

    <!-- ── Kopf: wer wird bearbeitet? ────────────────────── -->
    <div class="person-header">
      <div :class="['avatar', 'avatar-lg', 'sex-' + (form.sex || 'U'), { 'avatar-photo': photo }]" aria-hidden="true">
        <img v-if="photo" :src="mediaThumbUrl(photo.id)" alt="" />
        <svg v-else viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="12" r="6"/><path d="M4 32c0-7 5.4-11 12-11s12 4 12 11z"/></svg>
      </div>
      <div class="person-header-text">
        <div v-if="canEdit" class="person-header-kicker">{{ isEditing ? t('person.titleEdit') : t('person.titleNew') }}</div>
        <h2 class="person-header-name">{{ headerName }}</h2>
        <div v-if="headerLife" class="person-header-life">{{ headerLife }}</div>
      </div>
      <div class="person-header-sex">
        <SexSelect v-model="form.sex" :invalid="!!errors.sex" />
        <small v-if="errors.sex" class="error-msg">{{ errors.sex }}</small>
        <MultiSelect v-if="canEdit" v-model="form.restriction" :options="restrictionOptions" option-label="label" option-value="value"
          :placeholder="t('person.restriction.none')" :show-toggle-all="false" display="comma" size="small"
          :class="['restriction-select', { active: form.restriction.length }]" :aria-label="t('person.restriction.label')">
          <template #dropdownicon><i :class="['pi', form.restriction.length ? 'pi-lock' : 'pi-lock-open']" /></template>
        </MultiSelect>
      </div>
    </div>

    <p v-if="editPerson?.private" class="alert privacy-hint"><i class="pi pi-lock" /> {{ t('privacy.hiddenPerson') }}</p>

    <div v-if="isEditing" class="form-toolbar">
      <span v-if="personId === startPersonId" class="start-badge" v-tooltip.bottom="t('startPerson.isStartHint')">
        <i class="pi pi-flag-fill" /> {{ t('startPerson.isStart') }}</span>
      <Button v-else-if="isAdmin" :label="t('startPerson.set')" icon="pi pi-flag" text size="small"
        v-tooltip.bottom="t('startPerson.setHint')" @click="emit('set-start', personId)" />
      <Button v-if="isAdmin" :label="t('relativesTree.button')" icon="pi pi-sitemap" text size="small"
        v-tooltip.bottom="t('relativesTree.buttonHint')" @click="relativesTree" />
      <Button :label="allExpanded ? t('person.collapseAll') : t('person.expandAll')"
        :icon="allExpanded ? 'pi pi-angle-double-up' : 'pi pi-angle-double-down'" text size="small" @click="toggleAll" />
    </div>

    <PersonNames :key="'n' + formKey" v-model="form.names" v-model:open="openName"
      :errors="nameErrors" :editing="isEditing" @reordered="clearErrors" />

    <PersonEvents :key="'e' + formKey" v-model="form.events" v-model:open="openEvent" v-model:expanded="expanded"
      :person-id="personId" :errors="eventErrors" :refresh="refresh"
      @go-person="goPerson" @go-family="goFamily" />

    <PersonCitations :key="'c' + formKey" v-model="form.citations" />

    <PersonAssociations :key="'a' + formKey" v-model="form.associations" v-model:expanded="assoExpanded"
      :person-id="personId" @go-person="goPerson" />

    <!-- ── Medien der Person: Fotos, Dokumente; ★ = Hauptbild ── -->
    <fieldset>
      <legend>{{ t('media.title') }}</legend>
      <MediaStrip v-model="form.media" allow-primary size="md" />
      <p v-if="!form.media.length" class="field-hint">{{ t('media.personHint') }}</p>
    </fieldset>

    <!-- ── Anmerkungen ───────────────────────────────────── -->
    <fieldset>
      <legend>{{ t('person.sections.notes') }}</legend>
      <Textarea id="notes" v-model="form.notes" rows="2" auto-resize :placeholder="t('person.notes.placeholder')"
        :aria-label="t('person.notes.label')" fluid />
    </fieldset>

    <!-- ── Beziehungen: Eltern, Geschwister, Partner, Kinder (anklickbar) ── -->
    <PersonRelations class="relations-bottom" v-if="isEditing" :person-id="personId" :person="editPerson" :refresh="refresh"
      @edit-person="goPerson" @edit-family="goFamily" @manage="emit('relations', editPerson)" />

    <!-- ── Karte: alle Orte der Person und ihrer Familien ── -->
    <PersonPlacesMap v-if="isEditing" :person-id="personId" :person="editPerson" :refresh="refresh"
      @open-place="goPlace" />

    <LastChange type="person" :id="editPerson?.id" :label="headerName" :stamp="editPerson" />

    <div v-if="canEdit" class="form-actions form-actions-sticky">
      <span v-if="dirty" class="dirty-hint"><i class="pi pi-circle-fill" /> {{ t('person.dirty') }}</span>
      <Button :label="isEditing ? t('action.cancel') : t('action.reset')" severity="secondary" outlined @click="reset" />
      <Button type="submit" icon="pi pi-check" :loading="loading"
        :label="loading ? t('action.saving') : (isEditing ? t('action.saveChanges') : t('action.save'))" />
    </div>

  </form>
</div>
</template>
