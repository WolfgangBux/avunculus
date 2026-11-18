<script setup>
import { ref, reactive, computed, watch } from 'vue'
import InputText from 'openvue/inputtext'
import Textarea from 'openvue/textarea'
import Button from 'openvue/button'
import LastChange from './LastChange.vue'
import MediaThumb from './MediaThumb.vue'
import { updateMedia, deleteMedia, mediaFileUrl } from '../api/media.js'
import { toGedcomDate, isValidGedcomDate } from '../gedcom.js'
import { displayDate } from '../format.js'
import { useNotify, useAsk } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/** Ein Medium: Vorschau, Titel/Datum/Beschreibung, Dateiangaben und wo es verwendet wird */
const props = defineProps({ media: { type: Object, required: true } })
const emit  = defineEmits(['saved', 'deleted', 'open-person', 'open-family', 'open-source'])
const notify = useNotify()
const ask    = useAsk()

const form    = reactive({ title: '', media_date: '', notes: '' })
const error   = ref('')
const saving  = ref(false)
const snapshot = ref('')

watch(() => props.media, m => {
  Object.assign(form, { title: m.title ?? '', media_date: m.media_date ?? '', notes: m.notes ?? '' })
  error.value = ''
  snapshot.value = JSON.stringify(form)
}, { immediate: true })
const dirty = computed(() => JSON.stringify(form) !== snapshot.value)

const mime    = computed(() => props.media.mime_type ?? '')
const kind    = computed(() => mime.value.startsWith('image/') && !/tiff|heic/.test(mime.value) ? 'image'
  : mime.value === 'application/pdf' ? 'pdf'
  : mime.value.startsWith('video/') ? 'video'
  : mime.value.startsWith('audio/') ? 'audio' : 'other')
const fileUrl = computed(() => mediaFileUrl(props.media.id))

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
const fileInfo = computed(() => [
  props.media.original_name,
  formatSize(props.media.file_size ?? 0),
  props.media.width && props.media.height ? `${props.media.width} × ${props.media.height} px` : null,
].filter(Boolean).join(' · '))

function convertDate() { form.media_date = toGedcomDate(form.media_date) }

async function save() {
  convertDate()
  if (form.media_date && !isValidGedcomDate(form.media_date)) { error.value = t('person.eventFields.dateError'); return }
  saving.value = true
  try {
    const m = await updateMedia(props.media.id, { ...form })
    notify.success(t('media.saved'))
    emit('saved', m)
  } catch (e) {
    error.value = e?.errors?.media_date ? t('person.eventFields.dateError') : ''
    notify.error(t('action.saveError'))
  } finally {
    saving.value = false
  }
}

async function remove() {
  const n   = props.media.links?.length ?? 0
  const msg = n ? t('media.deleteConfirmLinked', { title: props.media.title || props.media.original_name, n })
                : t('media.deleteConfirm', { title: props.media.title || props.media.original_name })
  if (!(await ask(msg))) return
  await deleteMedia(props.media.id)
  notify.success(t('media.deleted'))
  emit('deleted', props.media)
}

// „Verwendet bei“: Person/Ereignis → Person öffnen, Familie(nereignis) → Familie, Quelle → Quelle
const links = computed(() => (props.media.links ?? []).map(l => ({
  ...l,
  icon: { person: 'pi-user', event: 'pi-calendar', family: 'pi-users', family_event: 'pi-heart', source: 'pi-book' }[l.kind],
  detail: l.kind === 'event' ? t('person.eventTypes.' + l.event_type)
        : l.kind === 'family_event' ? t('family.eventTypes.' + l.event_type)
        : l.kind === 'person' && l.is_primary ? t('media.primary') : '',
})))
function openLink(l) {
  if (l.kind === 'person' || l.kind === 'event') emit('open-person', l.target_id)
  else if (l.kind === 'source') emit('open-source', l.target_id)
  else emit('open-family', l.target_id)
}
</script>

<template>
  <div class="card media-form">
    <div class="media-preview">
      <a v-if="kind === 'image'" :href="fileUrl" target="_blank" rel="noopener" :title="t('media.open')">
        <img :src="fileUrl" :alt="form.title" />
      </a>
      <iframe v-else-if="kind === 'pdf'" :src="fileUrl" :title="form.title || media.original_name" />
      <video v-else-if="kind === 'video'" :src="fileUrl" controls preload="metadata" />
      <audio v-else-if="kind === 'audio'" :src="fileUrl" controls preload="metadata" />
      <div v-else class="media-preview-icon"><MediaThumb :media="media" size="lg" /></div>
    </div>

    <div class="media-file-line">
      <span class="muted">{{ fileInfo }}</span>
      <span class="media-file-actions">
        <Button as="a" :href="fileUrl" target="_blank" rel="noopener" icon="pi pi-external-link" :label="t('media.open')" text size="small" />
        <Button as="a" :href="mediaFileUrl(media.id, { download: true })" icon="pi pi-download" :label="t('media.download')" text size="small" />
      </span>
    </div>

    <form :class="{ 'read-only': !canEdit }" novalidate @submit.prevent="save">
      <fieldset>
        <legend>{{ t('media.sections.description') }}</legend>
        <div class="form-group">
          <label for="media-title">{{ t('media.fields.title') }}</label>
          <InputText id="media-title" v-model="form.title" :placeholder="media.original_name" fluid />
        </div>
        <div class="form-group">
          <label for="media-date">{{ t('media.fields.date') }}</label>
          <InputText id="media-date" v-model="form.media_date" class="gedcom-date" :invalid="!!error"
            :placeholder="t('person.eventFields.datePlaceholder')" autocomplete="off" spellcheck="false"
            @input="error = ''" @blur="convertDate" />
          <small v-if="error" class="error-msg">{{ error }}</small>
          <small v-else-if="form.media_date" class="field-hint">{{ displayDate(form.media_date) }}</small>
        </div>
        <div class="form-group">
          <label for="media-notes">{{ t('media.fields.notes') }}</label>
          <Textarea id="media-notes" v-model="form.notes" rows="3" auto-resize :placeholder="t('media.fields.notesPlaceholder')" fluid />
        </div>
      </fieldset>

      <fieldset>
        <legend>{{ t('media.sections.links') }}</legend>
        <p v-if="!links.length" class="field-hint">{{ t('media.noLinks') }}</p>
        <ul v-else class="media-links">
          <li v-for="(l, i) in links" :key="i" role="button" tabindex="0" @click="openLink(l)" @keydown.enter="openLink(l)">
            <i :class="['pi', l.icon]" />
            <span class="media-link-label">{{ l.label }}</span>
            <span v-if="l.detail" class="muted">{{ l.detail }}<template v-if="canEdit && (l.event_date)"> · {{ displayDate(l.event_date) }}</template></span>
            <i class="pi pi-angle-right media-link-go" />
          </li>
        </ul>
      </fieldset>

      <div class="form-actions">
        <Button :label="t('action.delete')" icon="pi pi-trash" severity="danger" text @click="remove" />
        <span class="spacer" />
        <span v-if="dirty" class="dirty-hint"><i class="pi pi-circle-fill" /> {{ t('person.dirty') }}</span>
        <Button type="submit" icon="pi pi-check" :loading="saving" :disabled="!dirty" :label="t('action.saveChanges')" />
      </div>
    </form>
    <LastChange type="media" :id="media.id" :label="media.title || media.original_name" :stamp="media" />
  </div>
</template>
