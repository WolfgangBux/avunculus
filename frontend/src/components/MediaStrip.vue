<script setup>
import { ref, computed } from 'vue'
import Button from 'openvue/button'
import MediaThumb from './MediaThumb.vue'
import MediaPickerDialog from './MediaPickerDialog.vue'
import { mediaFileUrl, isImage, uploadMedia } from '../api/media.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/**
 * Verknüpfte Medien eines Datensatzes (Person, Ereignis, Familie, Quelle) als Vorschaubilder.
 * v-model: [{ id, title, mime_type, is_primary }]; gespeichert wird mit dem Datensatz.
 */
const model = defineModel({ type: Array, default: () => [] })
const props = defineProps({
  allowPrimary: { type: Boolean, default: false },   // Hauptbild wählbar (Person)
  size:         { type: String,  default: 'sm' },
  readonly:     { type: Boolean, default: false },   // nur anzeigen (Leseansicht)
  regions:      { type: Boolean, default: false },   // Bereich markierbar (Person auf dem Bild, Stelle einer Quellenangabe)
  regionLabel:  { type: String,  default: '' },      // Beschriftung des Markieren-Knopfs (Standard: Person markieren)
})
const emit = defineEmits(['region'])
const notify = useNotify()

const showPicker = ref(false)
const ids = computed(() => model.value.map(m => m.id))

function add(list) {
  const next = [...model.value]
  for (const m of list) {
    if (next.some(x => x.id === m.id)) continue
    next.push({ id: m.id, title: m.title || m.original_name, mime_type: m.mime_type, is_primary: false })
  }
  // erstes Bild einer Person wird automatisch Hauptbild
  if (props.allowPrimary && !next.some(m => m.is_primary)) {
    const first = next.find(isImage)
    if (first) first.is_primary = true
  }
  model.value = next
}

function remove(i) {
  const next = model.value.filter((_, k) => k !== i)
  if (props.allowPrimary && model.value[i].is_primary) {
    const first = next.find(isImage)
    if (first) next[next.indexOf(first)] = { ...first, is_primary: true }
  }
  model.value = next
}

function makePrimary(i) {
  model.value = model.value.map((m, k) => ({ ...m, is_primary: k === i }))
}

// Dateien direkt auf die Leiste ziehen
const dragging  = ref(false)
const uploading = ref(false)
async function onDrop(e) {
  if (!canEdit.value) return
  dragging.value = false
  const files = [...(e.dataTransfer?.files ?? [])]
  if (!files.length) return
  uploading.value = true
  const added = []
  for (const f of files) {
    try { added.push(await uploadMedia(f)) }
    catch (err) { notify.error(err?.error || t('media.uploadError', { name: f.name })) }
  }
  uploading.value = false
  add(added)
}
</script>

<template>
  <div :class="['media-strip', { dragging }]"
    @dragover.prevent="dragging = !readonly" @dragleave.self="dragging = false" @drop.prevent="!readonly && onDrop($event)">
    <div v-for="(m, i) in model" :key="m.id" :class="['media-strip-item', { primary: m.is_primary }]">
      <a :href="mediaFileUrl(m.id)" target="_blank" rel="noopener" :title="m.title || ''">
        <MediaThumb :media="m" :size="size" />
      </a>
      <span v-if="!readonly" class="media-strip-actions">
        <button v-if="canEdit && (allowPrimary && isImage(m))" type="button" :class="['icon-btn', { active: m.is_primary }]"
          :title="m.is_primary ? t('media.primary') : t('media.makePrimary')" :aria-label="t('media.makePrimary')"
          @click="makePrimary(i)"><i :class="['pi', m.is_primary ? 'pi-star-fill' : 'pi-star']" /></button>
        <button v-if="canEdit && regions && isImage(m)" type="button" :class="['icon-btn', { active: m.crop }]"
          :title="regionLabel || t('media.region.markPerson')" :aria-label="regionLabel || t('media.region.markPerson')"
          @click="emit('region', m)"><i class="pi pi-expand" /></button>
        <button v-if="canEdit" type="button" class="icon-btn danger" :title="t('action.remove')" :aria-label="t('action.remove')"
          @click="remove(i)"><i class="pi pi-times" /></button>
      </span>
      <span v-if="m.is_primary" class="media-strip-badge"><i class="pi pi-star-fill" /></span>
    </div>
    <Button v-if="canEdit && !readonly" type="button" :class="['media-strip-add', 'media-thumb-' + size]" icon="pi pi-plus" text :loading="uploading"
      :aria-label="t('media.add')" v-tooltip.top="t('media.addHint')" @click="showPicker = true" />
    <MediaPickerDialog v-if="!readonly" v-model:visible="showPicker" :exclude-ids="ids" @select="add" />
  </div>
</template>
