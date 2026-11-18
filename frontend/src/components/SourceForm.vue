<script>
import { ref, reactive, computed, watch, onMounted, defineComponent } from 'vue'
import { createSource, updateSource } from '../api/sources.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { currentTreeId } from '../trees.js'
import { displayDate, nameLabel } from '../format.js'
import { useNotify } from '../composables/feedback.js'
import InputText from 'openvue/inputtext'
import Textarea  from 'openvue/textarea'
import Button    from 'openvue/button'
import LastChange from './LastChange.vue'
import Select    from 'openvue/select'
import { fetchRepositories } from '../api/repositories.js'
import MediaStrip from './MediaStrip.vue'

// GEDCOM-Medientypen (REPO.CALN.MEDI), siehe GedcomTags::MEDIA_TYPES
const MEDIA_TYPES = ['BOOK', 'MANUSCRIPT', 'FILM', 'FICHE', 'ELECTRONIC', 'PHOTO', 'NEWSPAPER', 'MAGAZINE',
                     'CARD', 'MAP', 'TOMBSTONE', 'AUDIO', 'VIDEO', 'OTHER']

const blank = () => ({
  title: '', abbreviation: '', author: '',
  publication: '', repository_id: null, call_number: '', media_type: null, source_text: '', notes: '',
  media: [],   // verknüpfte Medien (Scans, Fotos der Quelle)
})

export default defineComponent({
  components: { InputText, Textarea, Button, Select, MediaStrip, LastChange },
  props: { editSource: { type: Object, default: null } },
  emits: ['source-saved', 'cancel-edit', 'open-repository'],

  setup(props, { emit }) {
    const notify = useNotify()
    const form       = reactive(blank())
    const errors     = reactive({})
    const loading    = ref(false)

    const isEditing = () => props.editSource !== null

    // Archive des Baums für die Auswahl
    const repositories = ref([])
    onMounted(async () => { try { repositories.value = await fetchRepositories() } catch { /* ohne Auswahl */ } })
    const repositoryOptions = computed(() => repositories.value.map(r => ({ value: r.id, label: r.name })))
    const mediaOptions      = computed(() => MEDIA_TYPES.map(v => ({ value: v, label: t('source.media.' + v) })))

    function clearErrors() { Object.keys(errors).forEach(k => delete errors[k]) }

    function validate() {
      clearErrors()
      let ok = true
      if (!form.title.trim()) { errors.title = t('source.fields.titleError'); ok = false }
      return ok
    }

    async function submit() {
      if (!validate()) return
      loading.value  = true
      try {
        const source = isEditing()
          ? await updateSource(props.editSource.id, { ...form })
          : await createSource({ ...form })
        notify.success(t('source.saved', { title: source.title }))
        emit('source-saved', source)
        if (!isEditing()) {
          Object.assign(form, blank())
          clearErrors()
        }
      } catch {
        notify.error(t('action.saveError'))
      } finally {
        loading.value = false
      }
    }

    function reset() {
      if (isEditing()) {
        emit('cancel-edit')
      } else {
        Object.assign(form, blank())
        clearErrors()
      }
    }

    watch(() => props.editSource, (source) => {
      // Bearbeiten beendet bzw. „Neue Quelle“ → leeres Formular
      if (!source) {
        Object.assign(form, blank())
        clearErrors()
        return
      }
      Object.assign(form, {
        title:        source.title        ?? '',
        abbreviation: source.abbreviation ?? '',
        author:       source.author       ?? '',
        publication:  source.publication  ?? '',
        repository_id: source.repository_id ?? null,
        call_number:  source.call_number  ?? '',
        media_type:   source.media_type   ?? null,
        source_text:  source.source_text  ?? '',
        notes:        source.notes        ?? '',
        media:        (source.media ?? []).map(m => ({ id: m.id, title: m.title ?? '', mime_type: m.mime_type })),
      })
      clearErrors()
    }, { immediate: true })   // auch beim (Wieder-)Aufbau, z. B. nach Tab-Wechsel

    // ── Ereignisse, die diese Quelle zitieren ─────────────────
    const events     = computed(() => props.editSource?.events ?? [])
    const eventTotal = computed(() => props.editSource?.event_total ?? 0)
    const eventLabel = e => {
      const key = e.kind === 'family' ? `family.eventTypes.${e.event_type}` : `person.eventTypes.${e.event_type}`
      const label = t(key)
      return label === key ? e.event_type : label.replace(/\s*\([A-Z]+\)$/, '')
    }
    const ownersLabel = e => e.persons.map(nameLabel).join(' ⚭ ') || '–'
    // Person bzw. Familie in einem neuen Fenster öffnen (die App stellt die Ansicht aus der Adresse her)
    const eventHref = e => `${location.pathname}#/${currentTreeId.value}/${e.kind === 'family' ? 'families' : 'persons'}/${e.owner_id}`
    const fmt = n => n.toLocaleString()

    return { canEdit, t, form, errors, loading, isEditing, submit, reset, repositoryOptions, mediaOptions, emit,
             events, eventTotal, eventLabel, ownersLabel, eventHref, displayDate, fmt }
  },
})
</script>

<template>
<div class="card">
  <h2 class="card-title">{{ isEditing() ? (canEdit ? t('source.titleEdit') : form.title) : t('source.titleNew') }}</h2>

  <form :class="{ 'read-only': !canEdit }" @submit.prevent="submit" novalidate>

    <fieldset>
      <legend>{{ t('source.sections.main') }}</legend>
      <div class="form-row">
        <div class="form-group span-2">
          <label for="src_title">{{ t('source.fields.title') }} *</label>
          <InputText id="src_title" v-model.trim="form.title" :invalid="!!errors.title"
            :placeholder="t('source.fields.titlePlaceholder')" fluid />
          <small v-if="errors.title" class="error-msg">{{ errors.title }}</small>
        </div>
        <div class="form-group">
          <label for="src_abbr">{{ t('source.fields.abbreviation') }}</label>
          <InputText id="src_abbr" v-model.trim="form.abbreviation" :placeholder="t('source.fields.abbreviationPlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label for="src_author">{{ t('source.fields.author') }}</label>
          <InputText id="src_author" v-model.trim="form.author" :placeholder="t('source.fields.authorPlaceholder')" fluid />
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>{{ t('source.sections.publication') }}</legend>
      <div class="form-row">
        <div class="form-group span-2">
          <label for="src_publ">{{ t('source.fields.publication') }}</label>
          <Textarea id="src_publ" v-model="form.publication" rows="2" auto-resize
            :placeholder="t('source.fields.publicationPlaceholder')" fluid />
        </div>
        <div class="form-group span-2">
          <label for="src_repo">{{ t('source.fields.repository') }}</label>
          <div class="repo-select-row">
            <Select input-id="src_repo" v-model="form.repository_id" :options="repositoryOptions" option-label="label" option-value="value"
              :placeholder="t('source.fields.repositoryNone')" filter show-clear fluid />
            <Button v-if="form.repository_id" icon="pi pi-external-link" text rounded :aria-label="t('source.fields.openRepository')"
              v-tooltip.top="t('source.fields.openRepository')" @click="emit('open-repository', form.repository_id)" />
          </div>
        </div>
        <div class="form-group">
          <label for="src_caln">{{ t('source.fields.callNumber') }}</label>
          <InputText id="src_caln" v-model.trim="form.call_number" :placeholder="t('source.fields.callNumberPlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label for="src_medi">{{ t('source.fields.mediaType') }}</label>
          <Select input-id="src_medi" v-model="form.media_type" :options="mediaOptions" option-label="label" option-value="value"
            :placeholder="t('source.fields.mediaNone')" show-clear fluid />
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>{{ t('source.sections.transcription') }}</legend>
      <div class="form-group">
        <label for="src_text">{{ t('source.fields.sourceText') }}</label>
        <Textarea id="src_text" v-model="form.source_text" rows="5" auto-resize
          :placeholder="t('source.fields.sourceTextPlaceholder')" fluid />
      </div>
    </fieldset>

    <fieldset>
      <legend>{{ t('media.title') }}</legend>
      <MediaStrip v-model="form.media" size="md" />
      <p v-if="!form.media.length" class="field-hint">{{ t('media.sourceHint') }}</p>
    </fieldset>

    <fieldset>
      <legend>{{ t('source.sections.notes') }}</legend>
      <div v-if="canEdit" class="form-group">
        <label for="src_notes">{{ t('source.fields.notes') }}</label>
        <Textarea id="src_notes" v-model="form.notes" rows="3" auto-resize
          :placeholder="t('source.fields.notesPlaceholder')" fluid />
      </div>
    </fieldset>

    <div class="form-actions">
      <Button :label="isEditing() ? t('action.cancel') : t('action.reset')" severity="secondary" outlined @click="reset" />
      <Button type="submit" icon="pi pi-check" :loading="loading"
        :label="loading ? t('action.saving') : (isEditing() ? t('action.saveChanges') : t('action.save'))" />
    </div>

  </form>

  <fieldset v-if="isEditing()" class="place-section">
    <legend>{{ t('source.events', { n: fmt(eventTotal) }) }}</legend>
    <p v-if="!events.length" class="field-hint">{{ t('source.noEvents') }}</p>
    <ul v-else class="repo-sources">
      <li v-for="e in events" :key="e.kind + e.id">
        <a :href="eventHref(e)" target="_blank" rel="noopener" class="repo-source place-ev-link">
          <i :class="['pi', e.kind === 'family' ? 'pi-users' : 'pi-user']" />
          <span class="place-ev-type">{{ eventLabel(e) }}</span>
          <span class="repo-source-title">{{ ownersLabel(e) }}<span v-if="e.event_place" class="place-ev-sub"> · {{ e.event_place }}</span><span v-if="e.page" class="place-ev-sub"> · {{ e.page }}</span></span>
          <span class="repo-source-caln">{{ displayDate(e.event_date) }}</span>
          <i class="pi pi-external-link place-ev-ext" aria-hidden="true" />
        </a>
      </li>
    </ul>
    <p v-if="eventTotal > events.length" class="field-hint">
      {{ t('source.moreEvents', { n: fmt(events.length), total: fmt(eventTotal) }) }}
    </p>
  </fieldset>
  <LastChange type="source" :id="editSource?.id" :label="editSource?.title" :stamp="editSource" />
</div>
</template>
