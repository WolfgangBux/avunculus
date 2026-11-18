<script setup>
import { ref, computed, watch } from 'vue'
import Button from 'openvue/button'
import LastChange from './LastChange.vue'
import Textarea from 'openvue/textarea'
import Select from 'openvue/select'
import InputText from 'openvue/inputtext'
import PersonSearch from './PersonSearch.vue'
import FamilyEvents from './FamilyEvents.vue'
import MediaStrip from './MediaStrip.vue'
import QualitySelect from './QualitySelect.vue'
import SourcePicker from './SourcePicker.vue'
import {
  fetchFamily, createFamily, updateFamily, deleteFamily,
  addFamilyMember, removeFamilyMember, updateFamilyMember,
} from '../api/families.js'
import { useAsk, useNotify } from '../composables/feedback.js'
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
const notes     = ref('')
const citations = ref([])    // Quellenangaben direkt an der Familie (FAM.SOUR)
const media     = ref([])    // Medien der Familie (z. B. Hochzeitsfoto)
const mediaOf   = fam => (fam?.media ?? []).map(m => ({ id: m.id, title: m.title ?? '', mime_type: m.mime_type }))
const saving    = ref(false)

const blankCitation = () => ({ source_id: null, source_label: '', page: '', url: '', transcription: '', quality: null })
// mit ID: das Backend gleicht ab, vorhandene Quellenangaben behalten ihre ID
const citationsOf   = fam => (fam?.citations ?? []).map(c => ({
  id: c.id ?? null, source_id: c.source_id, source_label: c.source_abbreviation || c.source_title || '', page: c.page ?? '', url: c.url ?? '', transcription: c.transcription ?? '', quality: c.quality ?? null,
}))
const adding  = ref(null)    // 'HUSB' | 'WIFE' | 'CHIL' | null

async function load(quiet = false) {
  if (!props.familyId) { family.value = null; return }
  if (!quiet) loading.value = true
  // Nach Änderungen an Mitgliedern/Ereignissen leise nachladen, ohne ungespeicherte Notizen/Quellen zu verwerfen
  const keepEdits = quiet && dirty.value
  try {
    family.value = await fetchFamily(props.familyId)
    if (!keepEdits) {
      notes.value     = family.value.notes ?? ''
      citations.value = citationsOf(family.value)
      media.value     = mediaOf(family.value)
    }
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
const dirty = computed(() => !!family.value && (
  (family.value.notes ?? '') !== notes.value ||
  JSON.stringify(citationsOf(family.value)) !== JSON.stringify(citations.value) ||
  JSON.stringify(mediaOf(family.value).map(m => m.id)) !== JSON.stringify(media.value.map(m => m.id))))

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

async function saveNotes() {
  saving.value = true
  try {
    // Antwort des Servers übernehmen: neue Quellenangaben bekommen dort ihre ID (sonst beim nächsten Speichern doppelt)
    family.value    = await updateFamily(props.familyId, { notes: notes.value, citations: citations.value, media: media.value.map(m => ({ id: m.id })) })
    notes.value     = family.value.notes ?? ''
    citations.value = citationsOf(family.value)
    media.value     = mediaOf(family.value)
    notify.success(t('families.notesSaved'))
    await changed()
  } catch {
    notify.error(t('action.saveError'))
  } finally {
    saving.value = false
  }
}

async function removeFamily() {
  const name = [...membersOf('HUSB'), ...membersOf('WIFE')].map(m => m.display_name).join(' & ') || t('families.noPartners')
  if (!(await ask(t('families.deleteConfirm', { name })))) return
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
      <h2 class="card-title">{{ t('families.editTitle') }}</h2>

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
        <div v-for="(cit, ci) in citations" :key="ci" class="sub-card">
          <div class="sub-grid">
            <div class="form-group">
              <label>{{ t('person.citations.sourceLabel') }}</label>
              <SourcePicker v-model:source-id="cit.source_id" v-model:label="cit.source_label" />
            </div>
            <div class="form-group">
              <label>{{ t('person.citations.pageLabel') }}</label>
              <InputText v-model="cit.page" :placeholder="t('person.citations.pagePlaceholder')" fluid />
            </div>
            <Button v-if="canEdit" icon="pi pi-trash" severity="danger" text rounded class="sub-remove"
              :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="citations.splice(ci, 1)" />
            <div class="form-group">
              <label>{{ t('person.citations.quality.label') }}</label>
              <QualitySelect v-model="cit.quality" />
            </div>
            <div class="form-group sub-wide">
              <label>{{ t('person.citations.urlLabel') }}</label>
              <InputText v-model="cit.url" type="url" placeholder="https://…" fluid />
            </div>
            <div class="form-group sub-wide">
              <label>{{ t('person.citations.transcriptionLabel') }}</label>
              <Textarea v-model="cit.transcription" rows="1" auto-resize
                :placeholder="t('person.citations.transcriptionPlaceholder')" fluid />
            </div>
          </div>
        </div>
        <p v-if="!citations.length" class="field-hint">{{ t('families.sourcesHint') }}</p>
        <Button v-if="canEdit" :label="t('person.citations.add')" icon="pi pi-book" text size="small" class="slot-add"
          @click="citations.push(blankCitation())" />
      </fieldset>

      <!-- Medien der Familie -->
      <fieldset>
        <legend>{{ t('media.title') }}</legend>
        <MediaStrip v-model="media" size="md" />
        <p v-if="!media.length" class="field-hint">{{ t('media.familyHint') }}</p>
      </fieldset>

      <!-- Notizen -->
      <fieldset>
        <legend>{{ t('person.sections.notes') }}</legend>
        <div class="form-group">
          <Textarea v-model="notes" rows="3" auto-resize :placeholder="t('families.notesPlaceholder')"
            :aria-label="t('person.notes.label')" fluid />
        </div>
      </fieldset>

      <div class="form-actions">
        <Button v-if="canEdit" :label="t('family.deleteFamily')" icon="pi pi-trash" severity="danger" text class="form-actions-left" @click="removeFamily" />
        <span v-if="dirty" class="dirty-hint"><i class="pi pi-circle-fill" /> {{ t('person.dirty') }}</span>
        <Button v-if="canEdit" :label="t('families.saveNotes')" icon="pi pi-check" :disabled="!dirty" :loading="saving" @click="saveNotes" />
      </div>
      <LastChange type="family" :id="familyId" :stamp="family"
        :label="family.members.filter(m => m.role !== 'CHIL').map(m => m.display_name).join(' ⚭ ')" />
    </template>
  </div>
</template>
