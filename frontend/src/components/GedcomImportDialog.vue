<script setup>
import { ref, reactive, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import Select from 'openvue/select'
import SelectButton from 'openvue/selectbutton'
import ProgressBar from 'openvue/progressbar'
import Message from 'openvue/message'
import { fetchTrees, uploadGedcom, commitGedcom } from '../api/trees.js'
import { sexClass, displayDate } from '../format.js'
import { t } from '../i18n/index.js'
import { useNotify } from '../composables/feedback.js'

const visible = defineModel('visible', { type: Boolean, default: false })
const props   = defineProps({ currentTreeId: { type: Number, default: null } })
const emit    = defineEmits(['open', 'imported'])
const notify  = useNotify()

const PAGE = 20

const file      = ref(null)
const name      = ref('')
const mode      = ref('new')        // new | existing
const targetId  = ref(null)
const trees     = ref([])
const dragging  = ref(false)
const phase     = ref('select')     // select | upload | analyze | review | import | done
const uploaded  = ref(0)
const error     = ref('')
const analysis  = ref(null)
const decisions = reactive({})      // xref → Personen-ID, 0 = neu anlegen
const page      = ref(0)
const result    = ref(null)
const fileInput = ref(null)

const busy = computed(() => ['upload', 'analyze', 'import'].includes(phase.value))

watch(visible, async v => {
  if (!v || busy.value) return
  reset()
  try { trees.value = await fetchTrees() } catch { trees.value = [] }
  targetId.value = props.currentTreeId ?? trees.value[0]?.id ?? null
})

function reset() {
  file.value = null; name.value = ''; mode.value = 'new'; phase.value = 'select'
  uploaded.value = 0; error.value = ''; analysis.value = null; result.value = null; page.value = 0
  for (const k of Object.keys(decisions)) delete decisions[k]
}

const modeOptions = computed(() => [
  { value: 'new',      label: t('gedcom.modeNew') },
  { value: 'existing', label: t('gedcom.modeExisting') },
])
const treeOptions = computed(() => trees.value.map(tr => ({
  value: tr.id, label: `${tr.name} (${t('file.meta', { persons: tr.person_count, families: tr.family_count, sources: tr.source_count })})`,
})))

function pick(f) {
  if (!f) return
  file.value  = f
  error.value = ''
  if (!name.value) name.value = f.name.replace(/\.zip$/i, '').replace(/\.(ged|gedcom|gdz)$/i, '').replace(/[_]+/g, ' ').trim()
}

function onDrop(e) {
  dragging.value = false
  pick(e.dataTransfer?.files?.[0])
}

function formatSize(bytes) {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

const onProgress = next => p => {
  uploaded.value = Math.round(p.uploaded * 100)
  if (p.importing) phase.value = next
}

// Abbrechen geht beim Hochladen und während der Dublettenprüfung (die schreibt nichts).
// Der Import selbst läuft auf dem Server in einem Rutsch – ein Abbruch im Browser hielte ihn nicht auf.
let controller = null
const canCancel = computed(() => phase.value === 'upload' || phase.value === 'analyze')
function cancelUpload() { controller?.abort() }

async function start() {
  if (!file.value) { error.value = t('gedcom.noFile'); return }
  if (mode.value === 'existing' && !targetId.value) { error.value = t('gedcom.noTarget'); return }
  error.value = ''
  phase.value = 'upload'
  controller  = new AbortController()
  try {
    if (mode.value === 'new') {
      result.value = await uploadGedcom(file.value, { name: name.value.trim() || file.value.name }, onProgress('import'), controller.signal)
      finish()
    } else {
      analysis.value = await uploadGedcom(file.value, { treeId: targetId.value }, onProgress('analyze'), controller.signal)
      for (const c of analysis.value.candidates) decisions[c.xref] = c.preselect ?? 0
      page.value  = 0
      phase.value = 'review'
    }
  } catch (e) {
    error.value = e?.name === 'AbortError' ? '' : (e?.error || t('gedcom.failed'))
    if (e?.name === 'AbortError') notify.info(t('gedcom.cancelled'))
    phase.value = 'select'
  } finally {
    controller = null
  }
}

async function commit() {
  phase.value = 'import'
  try {
    result.value = await commitGedcom(analysis.value.upload, analysis.value.tree.id, { ...decisions })
    finish()
  } catch (e) {
    error.value = e?.error || t('gedcom.failed')
    phase.value = 'review'
  }
}

function finish() {
  phase.value = 'done'
  emit('imported', result.value.tree)
}

// ── Prüfliste ────────────────────────────────────────────
const candidates = computed(() => analysis.value?.candidates ?? [])
const pages      = computed(() => Math.max(1, Math.ceil(candidates.value.length / PAGE)))
const pageItems  = computed(() => candidates.value.slice(page.value * PAGE, (page.value + 1) * PAGE))
const mergeCount = computed(() => Object.values(decisions).filter(Boolean).length)
const newCount   = computed(() => (analysis.value?.persons_in_file ?? 0) - mergeCount.value)

function optionsFor(c) {
  return [
    { value: 0, label: t('gedcom.review.createNew') },
    ...c.candidates.map(x => ({ value: x.id, label: `${x.name} · ${x.sure ? t('gedcom.review.sure') : t('gedcom.review.possible')} (${x.score})` })),
  ]
}
const chosen = c => c.candidates.find(x => x.id === decisions[c.xref]) ?? null

function setAll(sureOnly) {
  for (const c of candidates.value) {
    decisions[c.xref] = sureOnly ? (c.preselect ?? 0) : 0
  }
}

function lifeLine(p) {
  return [p.birth && `* ${displayDate(p.birth)}${p.birth_place ? ', ' + p.birth_place : ''}`, p.death && `† ${displayDate(p.death)}`]
    .filter(Boolean).join('   ')
}

// ── Ergebnis ─────────────────────────────────────────────
const isMerge = computed(() => !!analysis.value)

const statRows = computed(() => {
  const s = result.value?.stats
  if (!s) return []
  const rows = isMerge.value
    ? [['persons_new', s.persons], ['persons_merged', s.persons_merged], ['families', s.families],
       ['families_merged', s.families_merged], ['events_added', s.events + s.events_added],
       ['events_completed', s.events_completed], ['names_added', s.names_added], ['sources', s.sources]]
    : [['persons', s.persons], ['families', s.families], ['sources', s.sources],
       ['events', s.events], ['family_events', s.family_events ?? 0], ['citations', s.citations]]
  if (s.media) rows.push(['media', s.media])
  return rows.map(([k, n]) => ({ label: t('gedcom.stats.' + k), n: n ?? 0 }))
})

const conflicts = computed(() => (result.value?.conflicts ?? []).map(c => ({
  ...c,
  label: t((c.scope === 'family' ? 'family.eventTypes.' : 'person.eventTypes.') + c.type),
})))

const skippedRows = computed(() =>
  Object.entries(result.value?.skipped ?? {}).map(([tag, n]) => {
    const key   = 'gedcom.tags.' + tag.replace(/^FAM\./, '')
    const label = t(key)
    return { tag, n, label: label === key ? (tag.replace(/^FAM\./, '').startsWith('_') ? t('gedcom.tags.vendor') : '') : label }
  }))

function openTree() {
  emit('open', result.value.tree)
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('gedcom.title')"
    :closable="!busy" :close-on-escape="!busy"
    :style="{ width: phase === 'review' ? '62rem' : '38rem' }" :breakpoints="{ '1000px': '95vw' }">

    <!-- 1. Datei und Ziel wählen -->
    <template v-if="phase === 'select'">
      <div
        :class="['drop-zone', { dragging, filled: file }]"
        role="button" tabindex="0"
        @click="fileInput.click()" @keydown.enter="fileInput.click()"
        @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop"
      >
        <i :class="['pi', file ? 'pi-file' : 'pi-upload']" />
        <template v-if="file">
          <strong>{{ file.name }}</strong>
          <span class="muted">{{ formatSize(file.size) }} · {{ t('gedcom.change') }}</span>
        </template>
        <template v-else>
          <strong>{{ t('gedcom.drop') }}</strong>
          <span class="muted">{{ t('gedcom.dropHint') }}</span>
        </template>
        <input ref="fileInput" type="file" accept=".ged,.gedcom,.zip,.gdz,text/plain,application/zip" hidden @change="pick($event.target.files[0])" />
      </div>

      <div class="form-group import-name">
        <label>{{ t('gedcom.target') }}</label>
        <SelectButton v-model="mode" :options="modeOptions" option-label="label" option-value="value" :allow-empty="false" />
      </div>

      <div v-if="mode === 'new'" class="form-group import-name">
        <label for="import-name">{{ t('gedcom.treeName') }}</label>
        <InputText id="import-name" v-model="name" :placeholder="t('file.namePlaceholder')" fluid />
      </div>
      <div v-else class="form-group import-name">
        <label for="import-target">{{ t('gedcom.targetTree') }}</label>
        <Select input-id="import-target" v-model="targetId" :options="treeOptions" option-label="label" option-value="value" fluid />
        <small class="field-hint">{{ t('gedcom.existingHint') }}</small>
      </div>

      <Message v-if="error" severity="error" class="import-msg">{{ error }}</Message>
    </template>

    <!-- Fortschritt -->
    <div v-else-if="busy" class="import-progress">
      <p>{{ t('gedcom.phase.' + phase) }}</p>
      <ProgressBar v-if="phase === 'upload'" :value="uploaded" />
      <ProgressBar v-else mode="indeterminate" style="height: 6px" />
      <p v-if="phase === 'import'" class="muted import-no-cancel">{{ t('gedcom.noCancel') }}</p>
    </div>

    <!-- 2. Prüfliste der möglichen Dubletten -->
    <div v-else-if="phase === 'review'" class="dup-review">
      <p class="dup-summary">
        {{ t('gedcom.review.summary', { file: analysis.persons_in_file, n: candidates.length, tree: analysis.tree.name }) }}
      </p>

      <Message v-if="!candidates.length" severity="success" class="import-msg">{{ t('gedcom.review.none') }}</Message>

      <template v-else>
        <div class="dup-toolbar">
          <Button :label="t('gedcom.review.allSure')" icon="pi pi-check-square" size="small" outlined @click="setAll(true)" />
          <Button :label="t('gedcom.review.allNew')" icon="pi pi-plus-circle" size="small" outlined severity="secondary" @click="setAll(false)" />
          <span class="dup-counts">{{ t('gedcom.review.counts', { merge: mergeCount, created: newCount }) }}</span>
        </div>

        <div class="dup-head">
          <span>{{ t('gedcom.review.inFile') }}</span>
          <span>{{ t('gedcom.review.decision') }}</span>
          <span>{{ t('gedcom.review.inTree', { tree: analysis.tree.name }) }}</span>
        </div>

        <div v-for="c in pageItems" :key="c.xref" :class="['dup-row', { merged: decisions[c.xref] }]">
          <div :class="['dup-person', sexClass(c.file)]">
            <div class="dup-name">{{ c.file.name }}</div>
            <div v-if="lifeLine(c.file)" class="dup-meta">{{ lifeLine(c.file) }}</div>
            <div v-if="c.file.parents" class="dup-meta"><i class="pi pi-sitemap" /> {{ c.file.parents }}</div>
            <div v-if="c.file.spouses" class="dup-meta"><i class="pi pi-heart" /> {{ c.file.spouses }}</div>
          </div>

          <div class="dup-decision">
            <i :class="['pi', decisions[c.xref] ? 'pi-arrows-h' : 'pi-plus']" />
            <Select v-model="decisions[c.xref]" :options="optionsFor(c)" option-label="label" option-value="value"
              size="small" fluid :aria-label="t('gedcom.review.decision')" />
          </div>

          <div v-if="chosen(c)" :class="['dup-person', sexClass(chosen(c))]">
            <div class="dup-name">
              {{ chosen(c).name }}
              <span :class="['dup-badge', chosen(c).sure ? 'sure' : 'maybe']">
                {{ chosen(c).sure ? t('gedcom.review.sure') : t('gedcom.review.possible') }}
              </span>
            </div>
            <div v-if="lifeLine(chosen(c))" class="dup-meta">{{ lifeLine(chosen(c)) }}</div>
            <div v-if="chosen(c).parents" class="dup-meta"><i class="pi pi-sitemap" /> {{ chosen(c).parents }}</div>
            <div v-if="chosen(c).spouses" class="dup-meta"><i class="pi pi-heart" /> {{ chosen(c).spouses }}</div>
          </div>
          <div v-else class="dup-person dup-new">{{ t('gedcom.review.willCreate') }}</div>
        </div>

        <div v-if="pages > 1" class="dup-pager">
          <Button icon="pi pi-angle-left" text rounded size="small" :disabled="page === 0" :aria-label="t('gedcom.review.prev')" @click="page--" />
          <span>{{ t('gedcom.review.page', { n: page + 1, total: pages }) }}</span>
          <Button icon="pi pi-angle-right" text rounded size="small" :disabled="page >= pages - 1" :aria-label="t('gedcom.review.next')" @click="page++" />
        </div>
      </template>

      <p class="field-hint dup-note">{{ t('gedcom.review.mergeRule') }}</p>
      <Message v-if="error" severity="error" class="import-msg">{{ error }}</Message>
    </div>

    <!-- 3. Ergebnis -->
    <div v-else-if="phase === 'done' && result" class="import-result">
      <Message severity="success" class="import-msg">
        {{ t(isMerge ? 'gedcom.doneMerge' : 'gedcom.done', { name: result.tree.name }) }}
      </Message>

      <div class="import-stats">
        <div v-for="r in statRows" :key="r.label" class="import-stat">
          <span class="import-stat-n">{{ r.n.toLocaleString() }}</span>
          <span class="import-stat-label">{{ r.label }}</span>
        </div>
      </div>

      <p v-if="result.header?.source" class="field-hint">
        {{ t('gedcom.origin', { source: result.header.source, version: result.header.version || '?', charset: result.header.charset }) }}
      </p>

      <Message v-for="(w, i) in result.warnings" :key="i" severity="warn" class="import-msg">{{ w }}</Message>

      <details v-if="conflicts.length" class="import-skipped" open>
        <summary>{{ t('gedcom.conflicts', { n: result.stats.conflicts }) }}</summary>
        <p class="field-hint">{{ t('gedcom.conflictsHint') }}</p>
        <ul>
          <li v-for="(c, i) in conflicts" :key="i" class="conflict-row">
            <strong>{{ c.name }}</strong> · {{ c.label }}:
            <span>{{ t('gedcom.conflictValues', { existing: c.existing || '–', file: c.file || '–' }) }}</span>
          </li>
        </ul>
      </details>

      <details v-if="skippedRows.length" class="import-skipped" open>
        <summary>{{ t('gedcom.skipped', { n: skippedRows.length }) }}</summary>
        <ul>
          <li v-for="r in skippedRows" :key="r.tag">
            <code>{{ r.tag }}</code>
            <span v-if="r.label" class="muted"> – {{ r.label }}</span>
            <span class="import-skipped-n">{{ r.n.toLocaleString() }}×</span>
          </li>
        </ul>
      </details>
    </div>

    <template #footer>
      <template v-if="phase === 'select'">
        <Button :label="t('action.cancel')" severity="secondary" outlined @click="visible = false" />
        <Button :label="mode === 'new' ? t('gedcom.start') : t('gedcom.analyze')" :icon="mode === 'new' ? 'pi pi-upload' : 'pi pi-search'"
          :disabled="!file" @click="start" />
      </template>
      <template v-else-if="phase === 'review'">
        <Button :label="t('action.cancel')" severity="secondary" outlined @click="visible = false" />
        <Button :label="t('gedcom.review.commit', { merge: mergeCount, created: newCount })" icon="pi pi-check" @click="commit" />
      </template>
      <template v-else-if="canCancel">
        <Button :label="t('gedcom.cancelUpload')" icon="pi pi-times" severity="secondary" outlined @click="cancelUpload" />
      </template>
      <template v-else-if="phase === 'done'">
        <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
        <Button :label="t('gedcom.openTree')" icon="pi pi-sitemap" @click="openTree" />
      </template>
    </template>
  </Dialog>
</template>
