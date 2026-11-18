<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import Tree from 'openvue/tree'
import Select from 'openvue/select'
import InputText from 'openvue/inputtext'
import Button from 'openvue/button'
import { fetchPlaces, createPlace, deleteUnusedPlaces } from '../api/places.js'
import WikidataMatchDialog from './WikidataMatchDialog.vue'
import { useNotify, useAsk } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/** Ortshierarchie als aufklappbarer Baum */
const props = defineProps({
  refresh:    Number,
  selectedId: { type: [Number, String], default: null },
})
const emit = defineEmits(['select', 'created', 'bulk-updated', 'bulk-deleted'])
const notify = useNotify()
const ask    = useAsk()

const places  = ref([])
const loading = ref(false)
const error   = ref('')
const show    = ref('all')

const SHOW_OPTIONS = computed(() => [
  { value: 'all',       label: t('place.show.all') },
  { value: 'noCoords',  label: t('place.show.noCoords') },
  { value: 'noLinks',   label: t('place.show.noLinks') },
  { value: 'withData',  label: t('place.show.withData') },
  { value: 'unused',    label: t('place.show.unused') },
  { value: 'unusedAll', label: t('place.show.unusedAll') },
])

async function load() {
  loading.value = true
  error.value   = ''
  try   { places.value = await fetchPlaces() }
  catch { error.value = t('place.loadError') }
  finally { loading.value = false }
}
onMounted(load)
watch(() => props.refresh, load)

const byId = computed(() => new Map(places.value.map(p => [p.id, p])))

// Filter „ohne Koordinaten“ usw.: passende Orte samt ihren Oberorten zeigen
const visibleIds = computed(() => {
  const test = {
    noCoords: p => !p.has_coords,
    noLinks:  p => !p.has_gov && !p.has_wikidata,
    withData: p => p.has_coords || p.has_gov || p.has_wikidata || p.alt_names || p.place_type,
    unused:   p => !p.total,
    unusedAll: p => !p.used,
  }[show.value]
  if (!test) return null
  const ids = new Set()
  for (const p of places.value) {
    if (!test(p)) continue
    for (let n = p; n && !ids.has(n.id); n = byId.value.get(n.parent_id)) ids.add(n.id)
  }
  return ids
})

const collator = new Intl.Collator('de', { sensitivity: 'base', numeric: true })

const nodes = computed(() => {
  const kids = new Map()
  for (const p of places.value) {
    if (visibleIds.value && !visibleIds.value.has(p.id)) continue
    const node = {
      key:   String(p.id),
      label: p.name,
      alt:   p.alt_names ?? '',
      data:  p,
      children: [],
    }
    if (!kids.has(p.parent_id)) kids.set(p.parent_id, [])
    kids.get(p.parent_id).push(node)
  }
  const build = list => list
    .map(n => {
      n.children = build(kids.get(n.data.id) ?? [])
      n.leaf = !n.children.length
      return n
    })
    .sort((a, b) => collator.compare(a.label, b.label))
  // Oben: Orte mit Unterorten (Länder …) vor einzelnen, unvollständigen Angaben („Aa“)
  return build(kids.get(null) ?? []).sort((a, b) => a.leaf - b.leaf)
})

// ── Auswahl und Aufklappen ────────────────────────────────
const selectionKeys = ref({})
const expandedKeys  = ref({})
const treeEl        = ref(null)

function onSelect(node) { emit('select', node.data.id) }

// Gewählten Ort (auch von außen: Adresse, Pfad im Formular) sichtbar machen
watch([() => props.selectedId, () => places.value.length], async () => {
  const id = props.selectedId != null ? Number(props.selectedId) : null
  selectionKeys.value = id ? { [id]: true } : {}
  if (!id) return
  const expanded = { ...expandedKeys.value }
  for (let n = byId.value.get(byId.value.get(id)?.parent_id); n; n = byId.value.get(n.parent_id)) expanded[n.id] = true
  expandedKeys.value = expanded
  await nextTick()
  // nur die Liste scrollen, nicht das Fenster
  const box = treeEl.value?.$el?.querySelector('.p-tree-root')
  const el  = box?.querySelector('.p-tree-node-selected')
  if (!el) return
  const b = box.getBoundingClientRect(), r = el.getBoundingClientRect()
  if (r.top < b.top || r.bottom > b.bottom) box.scrollTop += r.top - b.top - b.height / 3
}, { immediate: true })

const total = computed(() => places.value.length)

// Orte, die in keinem Stammbaum benutzt werden, auf einmal löschen
const unusedCount = computed(() => places.value.filter(p => !p.used).length)
const deleting    = ref(false)
async function removeUnused() {
  if (!(await ask(t('place.deleteUnusedConfirm', { n: fmt(unusedCount.value) })))) return
  deleting.value = true
  try {
    const { deleted } = await deleteUnusedPlaces()
    notify.success(t('place.unusedDeleted', { n: fmt(deleted) }))
    show.value = 'all'
    await load()
    emit('bulk-deleted', new Set(places.value.map(p => p.id)))
  } catch (e) {
    notify.error(e?.error ?? t('action.saveError'))
  } finally {
    deleting.value = false
  }
}

// Wikidata-Abgleich für alle Orte
const showMatch = ref(false)
function onMatched() { load(); emit('bulk-updated') }

// Neuer Ort auf oberster Ebene (Unterorte legt man beim Oberort an)
const adding  = ref(false)
const newName = ref('')
async function addRoot() {
  const name = newName.value.trim()
  if (!name) return
  try {
    const created = await createPlace({ name, parent_id: null })
    notify.success(t('place.created', { name: created.name }))
    newName.value = ''
    adding.value  = false
    emit('created', created)
  } catch (e) {
    notify.error(e?.error ?? t('action.saveError'))
  }
}
const fmt = n => n.toLocaleString()
</script>

<template>
  <div class="card place-list">
    <div class="list-header">
      <h2 class="card-title">{{ t('place.title') }}</h2>
      <span v-if="total" class="list-count">{{ t('place.total', { n: fmt(total) }) }}</span>
    </div>

    <div class="list-toolbar">
      <Select v-if="total" v-model="show" :options="SHOW_OPTIONS" option-label="label" option-value="value"
        size="small" class="place-show" :aria-label="t('place.show.label')" />
      <Button v-if="canEdit" icon="pi pi-sync" size="small" severity="secondary" outlined v-tooltip.bottom="t('place.match.title')"
        :aria-label="t('place.match.title')" @click="showMatch = true" />
      <Button v-if="canEdit" :label="t('place.new')" icon="pi pi-plus" size="small" @click="adding = !adding" />
    </div>
    <div v-if="show === 'unusedAll' && unusedCount" class="place-unused-bar">
      <span>{{ t('place.unusedCount', { n: fmt(unusedCount) }) }}</span>
      <Button v-if="canEdit" :label="t('place.deleteUnused')" icon="pi pi-trash" size="small" severity="danger" outlined
        :loading="deleting" @click="removeUnused" />
    </div>
    <WikidataMatchDialog v-model:visible="showMatch" @applied="onMatched" />
    <div v-if="adding" class="place-add-row">
      <InputText v-model="newName" size="small" autofocus :placeholder="t('place.newPlaceholder')"
        @keydown.enter.prevent="addRoot" @keydown.esc="adding = false" />
      <Button icon="pi pi-check" size="small" :disabled="!newName.trim()" :aria-label="t('action.save')" @click="addRoot" />
    </div>

    <p v-if="loading && !total" class="muted">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ error }}</div>
    <p v-else-if="!total" class="muted">{{ t('place.empty') }}</p>
    <p v-else-if="!nodes.length" class="muted">{{ t('person.list.noMatch') }}</p>

    <Tree v-else ref="treeEl" :value="nodes" v-model:selection-keys="selectionKeys" v-model:expanded-keys="expandedKeys"
      selection-mode="single" :meta-key-selection="false"
      filter filter-mode="lenient" filter-by="label,alt" :filter-placeholder="t('place.search')"
      class="place-tree" @node-select="onSelect">
      <template #default="{ node }">
        <span class="place-node">
          <span class="place-node-name">{{ node.label }}</span>
          <span v-if="node.data.place_type" class="place-node-type">{{ node.data.place_type }}</span>
          <span class="place-node-flags">
            <i v-if="node.data.has_coords" class="pi pi-map-marker" :title="t('place.flags.coords')" />
            <span v-if="node.data.has_gov" class="place-flag" :title="t('place.flags.gov')">GOV</span>
            <span v-if="node.data.has_wikidata" class="place-flag" :title="t('place.flags.wikidata')">WD</span>
          </span>
          <span v-if="node.data.total" class="place-node-count" :title="t('place.eventCount', { n: fmt(node.data.total) })">
            {{ fmt(node.data.total) }}
          </span>
        </span>
      </template>
    </Tree>
  </div>
</template>
