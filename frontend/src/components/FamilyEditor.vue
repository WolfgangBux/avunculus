<script setup>
import { ref, computed, watch } from 'vue'
import Button from 'openvue/button'
import LastChange from './LastChange.vue'
import SaveStatus from './SaveStatus.vue'
import Textarea from 'openvue/textarea'
import Select from 'openvue/select'
import PersonSearch from './PersonSearch.vue'
import FamilyEvents from './FamilyEvents.vue'
import MediaStrip from './MediaStrip.vue'
import CitationFields from './CitationFields.vue'
import {
  fetchFamily, createFamily, updateFamily, deleteFamily,
  addFamilyMember, removeFamilyMember, updateFamilyMember,
} from '../api/families.js'
import { useAsk, useNotify } from '../composables/feedback.js'
import { useRecordAutosave } from '../composables/autosave.js'
import { isFilledCitation, citationMediaFromApi, citationToApi } from '../personModel.js'
import { sexClass } from '../format.js'
import { extractYear } from '../gedcom.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

const props = defineProps({
  familyId: { type: Number, default: null },
  creating: { type: Boolean, default: false },
})
const emit = defineEmits(['changed', 'created', 'deleted', 'cancel', 'show-person'])

const ask    = useAsk()
const notify = useNotify()

const family  = ref(null)
const loading = ref(false)
// Notizen, Quellenangaben direkt an der Familie (FAM.SOUR) und Medien (z. B. Hochzeitsfoto) – werden automatisch gespeichert
const form    = ref({ notes: '', citations: [], media: [] })
const mediaOf = fam => (fam?.media ?? []).map(m => ({ id: m.id, title: m.title ?? '', mime_type: m.mime_type }))

const blankCitation = () => ({ source_id: null, source_label: '', page: '', url: '', transcription: '', quality: null, media: [] })
// mit ID: das Backend gleicht ab, vorhandene Quellenangaben behalten ihre ID
const citationsOf   = fam => (fam?.citations ?? []).map(c => ({
  id: c.id ?? null, source_id: c.source_id, source_label: c.source_abbreviation || c.source_title || '', page: c.page ?? '', url: c.url ?? '', transcription: c.transcription ?? '', quality: c.quality ?? null,
  media: citationMediaFromApi(c),
}))
const adding  = ref(null)    // 'HUSB' | 'WIFE' | 'CHIL' | null

// Nach Änderungen an Mitgliedern/Ereignissen leise nachladen; ausstehende Notizen/Quellen bleiben (siehe useRecordAutosave)
async function load(quiet = false) {
  if (!props.familyId) { family.value = null; return }
  if (!quiet) loading.value = true
  try {
    family.value = await fetchFamily(props.familyId)
  } catch {
    notify.error(t('families.loadError'))
  } finally {
    loading.value = false
  }
}

watch(() => props.familyId, () => { adding.value = null; load() }, { immediate: true })

const membersOf  = role => family.value?.members.filter(m => m.role === role) ?? []

const PEDIGREES       = ['BIRTH', 'ADOPTED', 'FOSTER', 'SEALED', 'OTHER']
const pedigreeOptions = computed(() => PEDIGREES.map(v => ({ value: v, label: t('family.pedigree.' + v) })))

async function setPedigree(m, value) {
  try {
    await updateFamilyMember(props.familyId, m.person_id, { pedigree: value })
    await changed()
  } catch {
    notify.error(t('action.saveError'))
  }
}
const excludeIds = computed(() => family.value?.members.map(m => m.person_id) ?? [])

const { status, flush, discard } = useRecordAutosave({
  form,
  record:  () => family.value,
  enabled: () => canEdit.value,
  load(fam) {
    form.value = { notes: fam?.notes ?? '', citations: citationsOf(fam), media: mediaOf(fam) }
  },
  payload: f => ({ notes: f.notes, citations: f.citations.map(citationToApi), media: f.media.map(m => ({ id: m.id })) }),
  validate: () => true,
  save:    (id, data) => updateFamily(id, data),
  // neue Quellenangaben bekommen ihre ID (sonst beim nächsten Speichern doppelt); leere übergeht der Server
  before:  f => f.citations.filter(isFilledCitation),
  adopt(rows, data, fam) {
    const saved = fam.citations ?? []
    if (rows.length !== saved.length) return false
    const sent = data.citations.filter(isFilledCitation)
    rows.forEach((c, i) => { c.id = sent[i].id = saved[i].id })
  },
  saved(fam, shown) {
    if (shown) family.value = fam
    emit('changed')
  },
})

async function changed() {
  emit('changed')
  await load(true)
}

async function addMember(person, role) {
  adding.value = null
  try {
    await addFamilyMember(props.familyId, { person_id: person.id, role })
    await changed()
  } catch {
    notify.error(t('action.saveError'))
  }
}

async function removeMember(m) {
  if (!(await ask(t('families.removeConfirm', { name: m.display_name }), { acceptLabel: t('action.remove') }))) return
  try {
    await removeFamilyMember(props.familyId, m.person_id, { keep: true })
    await changed()
  } catch {
    notify.error(t('family.error.remove'))
  }
}

async function removeFamily() {
  const name = [...membersOf('HUSB'), ...membersOf('WIFE')].map(m => m.display_name).join(' & ') || t('families.noPartners')
  if (!(await ask(t('families.deleteConfirm', { name })))) return
  discard()
  try {
    await deleteFamily(props.familyId)
    emit('deleted', props.familyId)
  } catch {
    notify.error(t('family.error.delete'))
  }
}

async function createWith(person) {
  try {
    const fam = await createFamily({ members: [{ person_id: person.id, role: person.sex === 'F' ? 'WIFE' : 'HUSB' }] })
    notify.success(t('families.created'))
    emit('created', fam.id)
  } catch {
    notify.error(t('action.saveError'))
  }
}
</script>

<template>
  <div :class="['card', 'family-editor', { 'read-only': !canEdit }]">
    <!-- Neue Familie: erste Person wählen -->
    <template v-if="creating">
      <h2 class="card-title">{{ t('families.newTitle') }}</h2>
      <p class="muted dialog-intro">{{ t('families.newHint') }}</p>
      <PersonSearch :placeholder="t('person.search.placeholder')" @select="createWith" @cancel="emit('cancel')" />
    </template>

    <p v-else-if="loading" class="muted">{{ t('common.loading') }}</p>

    <template v-else-if="family">
      <div class="card-title-row">
        <h2 class="card-title">{{ t('families.editTitle') }}</h2>
        <SaveStatus v-if="canEdit" :status="status" @retry="flush" />
      </div>

      <!-- Partner -->
      <fieldset>
        <legend>{{ t('families.partners') }}</legend>
        <div class="partner-slots">
          <div v-for="role in ['HUSB', 'WIFE']" :key="role" class="partner-slot">
            <div class="partner-slot-label">{{ t('families.slot.' + role) }}</div>
            <div v-for="m in membersOf(role)" :key="m.person_id" :class="['member-chip', 'member-chip-lg', sexClass(m)]">
              <button class="chip-link" @click="emit('show-person', m)">{{ m.display_name }}</button>
              <span v-if="m.birth_date" class="chip-year muted">* {{ extractYear(m.birth_date) }}</span>
              <button v-if="canEdit" class="chip-remove" :title="t('action.remove')" @click="removeMember(m)"><i class="pi pi-times" /></button>
            </div>
            <PersonSearch v-if="adding === role" :exclude-ids="excludeIds"
              @select="p => addMember(p, role)" @cancel="adding = null" />
            <Button v-else-if="canEdit && (!membersOf(role).length)" :label="t('families.choose')" icon="pi pi-plus" text size="small"
              class="slot-add" @click="adding = role" />
          </div>
        </div>
      </fieldset>

      <!-- Kinder -->
      <fieldset>
        <legend>{{ t('family.children') }}</legend>
        <div class="chip-row">
          <div v-for="m in membersOf('CHIL')" :key="m.person_id" :class="['member-chip', 'child-chip', sexClass(m)]">
            <button class="chip-link" @click="emit('show-person', m)">{{ m.display_name }}</button>
            <span v-if="m.birth_date" class="chip-year muted">* {{ extractYear(m.birth_date) }}</span>
            <Select :model-value="m.pedigree || 'BIRTH'" :options="pedigreeOptions" option-label="label" option-value="value"
              size="small" :class="['chip-pedigree', { special: m.pedigree && m.pedigree !== 'BIRTH' }]"
              :aria-label="t('family.pedigree.label')" @update:model-value="v => setPedigree(m, v)" />
            <button v-if="canEdit" class="chip-remove" :title="t('action.remove')" @click="removeMember(m)"><i class="pi pi-times" /></button>
          </div>
          <span v-if="!membersOf('CHIL').length && adding !== 'CHIL'" class="muted">–</span>
        </div>
        <div v-if="adding === 'CHIL'" class="search-panel">
          <PersonSearch :exclude-ids="excludeIds" :placeholder="t('family.searchFor', { what: t('family.child') })"
            @select="p => addMember(p, 'CHIL')" @cancel="adding = null" />
        </div>
        <Button v-else-if="canEdit" :label="t('family.addChild')" icon="pi pi-plus" text size="small" class="slot-add" @click="adding = 'CHIL'" />
      </fieldset>

      <!-- Ereignisse -->
      <fieldset>
        <legend>{{ t('family.events.label') }}</legend>
        <FamilyEvents :family="family" bare @changed="changed" />
      </fieldset>

      <!-- Quellen der Familie (nicht an ein Ereignis gebunden) -->
      <fieldset>
        <legend>{{ t('families.sources') }}</legend>
        <div v-for="(cit, ci) in form.citations" :key="ci" class="sub-card">
          <CitationFields :citation="cit" :id-prefix="`f${ci}_`" @remove="form.citations.splice(ci, 1)" />
        </div>
        <p v-if="!form.citations.length" class="field-hint">{{ t('families.sourcesHint') }}</p>
        <Button v-if="canEdit" :label="t('person.citations.add')" icon="pi pi-book" text size="small" class="slot-add"
          @click="form.citations.push(blankCitation())" />
      </fieldset>

      <!-- Medien der Familie -->
      <fieldset>
        <legend>{{ t('media.title') }}</legend>
        <MediaStrip v-model="form.media" size="md" />
        <p v-if="!form.media.length" class="field-hint">{{ t('media.familyHint') }}</p>
      </fieldset>

      <!-- Notizen -->
      <fieldset>
        <legend>{{ t('person.sections.notes') }}</legend>
        <div class="form-group">
          <Textarea v-model="form.notes" rows="3" auto-resize :placeholder="t('families.notesPlaceholder')"
            :aria-label="t('person.notes.label')" fluid />
        </div>
      </fieldset>

      <div class="form-actions">
        <Button v-if="canEdit" :label="t('family.deleteFamily')" icon="pi pi-trash" severity="danger" text class="form-actions-left" @click="removeFamily" />
      </div>
      <LastChange type="family" :id="familyId" :stamp="family"
        :label="family.members.filter(m => m.role !== 'CHIL').map(m => m.display_name).join(' ⚭ ')" />
    </template>
  </div>
</template>
