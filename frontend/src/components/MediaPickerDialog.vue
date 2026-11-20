<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import SelectButton from 'openvue/selectbutton'
import ProgressBar from 'openvue/progressbar'
import MediaThumb from './MediaThumb.vue'
import { searchMedia, uploadMedia } from '../api/media.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

/** Medien aus der Mediathek wählen oder neue Dateien hochladen */
const visible = defineModel('visible', { type: Boolean, default: false })
const props   = defineProps({ excludeIds: { type: Array, default: () => [] } })
const emit    = defineEmits(['select'])
const notify  = useNotify()

const type = ref('all')
const typeOptions = computed(() => [
  { value: 'all',      label: t('media.filter.all') },
  { value: 'image',    label: t('media.filter.image') },
  { value: 'document', label: t('media.filter.document') },
])
const { query, items, total, loading, reload, loadMore } =
  usePagedSearch((q, opts) => searchMedia(q, { ...opts, type: type.value === 'all' ? '' : type.value }), { pageSize: 48 })
watch(type, reload)

const selected = ref(new Map())   // id → Medium (Reihenfolge der Auswahl)
const excluded = computed(() => new Set(props.excludeIds.map(Number)))

watch(visible, v => {
  if (!v) return
  selected.value = new Map()
  query.value = ''
  reload()
})

function toggle(m) {
  if (excluded.value.has(m.id)) return
  const s = new Map(selected.value)
  s.has(m.id) ? s.delete(m.id) : s.set(m.id, m)
  selected.value = s
}

// ── Hochladen ────────────────────────────────────────────
const fileInput = ref(null)
const dragging  = ref(false)
const uploading = ref(null)   // { name, progress, index, count }

let controller = null
async function upload(files) {
  const list = [...(files ?? [])]
  if (!list.length) return
  controller = new AbortController()   // Abbrechen stoppt die laufende Datei und alle folgenden
  for (const [i, f] of list.entries()) {
    if (controller.signal.aborted) break
    uploading.value = { name: f.name, progress: 0, index: i + 1, count: list.length }
    try {
      const m = await uploadMedia(f, p => { uploading.value = { ...uploading.value, progress: Math.round(p * 100) } }, controller.signal)
      if (!excluded.value.has(m.id)) selected.value = new Map(selected.value).set(m.id, m)
      if (m.duplicate) notify.success(t('media.duplicate', { title: m.title || m.original_name }))
    } catch (e) {
      if (e?.name === 'AbortError') { notify.info(t('media.uploadCancelled')); break }
      notify.error(e?.error || t('media.uploadError', { name: f.name }))
    }
  }
  controller = null
  uploading.value = null
  reload()
}
function onDrop(e) { dragging.value = false; upload(e.dataTransfer?.files) }

function confirm() {
  emit('select', [...selected.value.values()])
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('media.picker.title')"
    :style="{ width: '52rem' }" :breakpoints="{ '900px': '95vw' }">
    <div :class="['media-picker', { dragging }]"
      @dragover.prevent="dragging = true" @dragleave.self="dragging = false" @drop.prevent="onDrop">
      <div class="list-toolbar">
        <IconField class="list-search">
          <InputIcon class="pi pi-search" />
          <InputText v-model="query" type="search" :placeholder="t('media.list.search')" fluid />
        </IconField>
        <SelectButton v-model="type" :options="typeOptions" option-label="label" option-value="value" :allow-empty="false" size="small" />
        <Button :label="t('media.upload')" icon="pi pi-upload" size="small" @click="fileInput.click()" />
        <input ref="fileInput" type="file" multiple hidden @change="upload($event.target.files); $event.target.value = ''" />
      </div>

      <div v-if="uploading" class="media-upload-progress">
        <span>{{ t('media.uploading', { name: uploading.name, n: uploading.index, total: uploading.count }) }}</span>
        <ProgressBar :value="uploading.progress" style="height: 6px" :show-value="false" />
        <Button :label="t('action.cancel')" icon="pi pi-times" size="small" text severity="secondary" class="media-upload-cancel" @click="controller?.abort()" />
      </div>

      <p v-if="!loading && !items.length" class="muted media-picker-empty">
        <i class="pi pi-images" /> {{ query ? t('person.list.noMatch') : t('media.picker.empty') }}
      </p>
      <ul v-else class="media-grid media-grid-sm">
        <li v-for="m in items" :key="m.id"
          :class="['media-tile', { selected: selected.has(m.id), disabled: excluded.has(m.id) }]"
          :title="excluded.has(m.id) ? t('media.picker.already') : (m.title || m.original_name)"
          tabindex="0" @click="toggle(m)" @keydown.enter="toggle(m)" @keydown.space.prevent="toggle(m)">
          <MediaThumb :media="m" />
          <span class="media-tile-title">{{ m.title || m.original_name }}</span>
          <i v-if="selected.has(m.id)" class="pi pi-check-circle media-tile-check" />
          <i v-else-if="excluded.has(m.id)" class="pi pi-link media-tile-check" />
        </li>
      </ul>
      <div v-if="items.length < total" class="list-more">
        <Button :label="t('list.loadMore', { n: Math.min(48, total - items.length), rest: (total - items.length).toLocaleString() })"
          icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
      </div>
      <p class="field-hint">{{ t('media.picker.dropHint') }}</p>
    </div>

    <template #footer>
      <Button :label="t('action.cancel')" severity="secondary" outlined @click="visible = false" />
      <Button :label="t('media.picker.add', { n: selected.size })" icon="pi pi-check" :disabled="!selected.size" @click="confirm" />
    </template>
  </Dialog>
</template>
