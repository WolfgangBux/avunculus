<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import Button from 'openvue/button'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import Select from 'openvue/select'
import ProgressBar from 'openvue/progressbar'
import MediaThumb from './MediaThumb.vue'
import { searchMedia, uploadMedia } from '../api/media.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/** Mediathek: alle Medien des Baums als Kacheln, mit Suche, Filter und Hochladen per Drag & Drop */
const props = defineProps({
  refresh:    Number,
  selectedId: { type: [Number, String], default: null },
})
const emit   = defineEmits(['select', 'uploaded'])
const notify = useNotify()
const PAGE   = 60

const filter = ref('all')
const filterOptions = computed(() => [
  { value: 'all',      label: t('media.filter.all') },
  { value: 'image',    label: t('media.filter.image') },
  { value: 'document', label: t('media.filter.document') },
  { value: 'av',       label: t('media.filter.av') },
  { value: 'unlinked', label: t('media.filter.unlinked') },
])
const { query, items, total, loading, error, reload, loadMore } = usePagedSearch(
  (q, opts) => searchMedia(q, { ...opts, type: ['all', 'unlinked'].includes(filter.value) ? '' : filter.value, unlinked: filter.value === 'unlinked' }),
  { pageSize: PAGE })
watch(filter, reload)
watch(() => props.refresh, reload)
onMounted(reload)

const isSelected = m => props.selectedId != null && String(m.id) === String(props.selectedId)

// ── Hochladen ────────────────────────────────────────────
const fileInput = ref(null)
const dragging  = ref(false)
const uploading = ref(null)

async function upload(files) {
  const list = [...(files ?? [])]
  if (!list.length) return
  let last = null
  for (const [i, f] of list.entries()) {
    uploading.value = { name: f.name, progress: 0, index: i + 1, count: list.length }
    try {
      last = await uploadMedia(f, p => { uploading.value = { ...uploading.value, progress: Math.round(p * 100) } })
      if (last.duplicate) notify.success(t('media.duplicate', { title: last.title || last.original_name }))
    } catch (e) {
      notify.error(e?.error || t('media.uploadError', { name: f.name }))
    }
  }
  uploading.value = null
  await reload()
  if (last) emit('uploaded', last)
}
function onDrop(e) { dragging.value = false; if (canEdit.value) upload(e.dataTransfer?.files) }
</script>

<template>
  <div :class="['card', 'media-library', { dragging }]"
    @dragover.prevent="dragging = canEdit" @dragleave.self="dragging = false" @drop.prevent="onDrop">
    <div class="list-header">
      <h2 class="card-title">{{ t('media.list.title') }}</h2>
      <span v-if="total || query || filter !== 'all'" class="list-count">{{ t('media.list.total', { n: total.toLocaleString() }) }}</span>
    </div>

    <div class="list-toolbar">
      <IconField class="list-search">
        <InputIcon :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
        <InputText v-model="query" type="search" :placeholder="t('media.list.search')" fluid />
      </IconField>
      <Select v-model="filter" :options="filterOptions" option-label="label" option-value="value" size="small"
        :aria-label="t('media.filter.label')" class="media-filter" />
      <Button v-if="canEdit" :label="t('media.upload')" icon="pi pi-upload" size="small" @click="fileInput.click()" />
      <input ref="fileInput" type="file" multiple hidden @change="upload($event.target.files); $event.target.value = ''" />
    </div>

    <div v-if="uploading" class="media-upload-progress">
      <span>{{ t('media.uploading', { name: uploading.name, n: uploading.index, total: uploading.count }) }}</span>
      <ProgressBar :value="uploading.progress" style="height: 6px" :show-value="false" />
    </div>

    <p v-if="loading && !items.length" class="muted">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ t('media.list.loadError') }}</div>
    <div v-else-if="canEdit && (!items.length && !query && filter === 'all')" class="media-empty" role="button" tabindex="0"
      @click="fileInput.click()" @keydown.enter="fileInput.click()">
      <i class="pi pi-images" />
      <strong>{{ t('media.list.empty') }}</strong>
      <span class="muted">{{ t('media.list.emptyHint') }}</span>
    </div>
    <p v-else-if="!items.length" class="muted">{{ t('person.list.noMatch') }}</p>

    <ul v-else class="media-grid">
      <li v-for="m in items" :key="m.id" :class="['media-tile', { selected: isSelected(m) }]"
        :title="m.title || m.original_name" tabindex="0" @click="emit('select', m.id)" @keydown.enter="emit('select', m.id)">
        <MediaThumb :media="m" />
        <span class="media-tile-title">{{ m.title || m.original_name }}</span>
        <span v-if="!m.link_count" class="media-tile-flag" :title="t('media.filter.unlinked')"><i class="pi pi-link" /></span>
      </li>
    </ul>
    <div v-if="items.length < total" class="list-more">
      <Button :label="t('list.loadMore', { n: Math.min(PAGE, total - items.length), rest: (total - items.length).toLocaleString() })"
        icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
    </div>
    <div v-if="dragging" class="media-drop-overlay"><i class="pi pi-upload" /> {{ t('media.dropHere') }}</div>
  </div>
</template>
