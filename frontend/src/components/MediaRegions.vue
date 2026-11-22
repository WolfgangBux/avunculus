<script setup>
import { ref, computed, watch } from 'vue'
import Button from 'openvue/button'
import SelectButton from 'openvue/selectbutton'
import { mediaFileUrl, saveMediaRegion } from '../api/media.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/**
 * Bild mit markierten Personen: je verknüpfter Person ein Rahmen (Rechteck oder Kreis/Oval), der ihren
 * Ausschnitt bestimmt – das Vorschaubild der Person (Liste, Stammbaum) zeigt dann nur diesen Teil.
 * Gespeichert wird je Markierung sofort (PUT media/{id}/region).
 *
 * Koordinaten sind Anteile (0–1) des angezeigten, also richtig gedrehten Bilds – so passt der Ausschnitt
 * unabhängig von der Auflösung, und das Vorschaubild schneidet nach dem Drehen genauso zu.
 *
 * onlyPerson: nur diese Person (Dialog aus dem Personenformular) – startet gleich im Bearbeiten.
 */
const props = defineProps({
  media:      { type: Object, required: true },   // mit links (MediaController::show)
  onlyPerson: { type: Number, default: null },
})
const emit   = defineEmits(['saved'])
const notify = useNotify()

const persons = computed(() => (props.media.links ?? [])
  .filter(l => l.kind === 'person' && (props.onlyPerson == null || l.target_id === props.onlyPerson)))

const img      = ref(null)
const editing  = ref(null)    // Personen-ID, deren Rahmen gerade bearbeitet wird
const draft    = ref(null)    // { left, top, width, height, shape }
const hovered  = ref(null)
const saving   = ref(false)

const shapeOptions = computed(() => [
  { value: 'rect',    label: t('media.region.rect') },
  { value: 'ellipse', label: t('media.region.ellipse') },
])

function edit(personId) {
  const l = persons.value.find(p => p.target_id === personId)
  editing.value = personId
  draft.value   = l?.crop ? { ...l.crop } : null
}
function cancel() { editing.value = null; draft.value = null }

// Dialog: gleich bearbeiten; neues Medium (andere Datei) → Bearbeitung beenden
watch(() => [props.media.id, props.onlyPerson], () => {
  if (props.onlyPerson != null && canEdit.value) edit(props.onlyPerson)
  else cancel()
}, { immediate: true })

async function save(crop) {
  saving.value = true
  try {
    const m = await saveMediaRegion(props.media.id, editing.value, crop)
    notify.success(crop ? t('media.region.saved') : t('media.region.removed'))
    cancel()
    emit('saved', m)
  } catch (e) {
    notify.error(e?.error || t('media.region.saveError'))
  } finally {
    saving.value = false
  }
}

// ── Ziehen: neuen Rahmen aufziehen, verschieben oder an einer Ecke ändern ──
const MIN = 0.02
const clamp = (v, lo = 0, hi = 1) => Math.min(hi, Math.max(lo, v))
let drag = null

function point(e) {
  const r = img.value.getBoundingClientRect()
  return { x: clamp((e.clientX - r.left) / r.width), y: clamp((e.clientY - r.top) / r.height) }
}

function down(e) {
  if (editing.value == null || e.button > 0) return
  const p = point(e)
  const handle = e.target.dataset?.handle
  const shape  = draft.value?.shape ?? 'rect'
  if (handle && draft.value)                         drag = { mode: 'resize', handle, orig: { ...draft.value } }
  else if (e.target.dataset?.frame && draft.value)   drag = { mode: 'move', start: p, orig: { ...draft.value } }
  else { drag = { mode: 'draw', start: p, orig: draft.value }; draft.value = { left: p.x, top: p.y, width: 0, height: 0, shape } }
  e.currentTarget.setPointerCapture(e.pointerId)
  e.preventDefault()
}

function move(e) {
  if (!drag) return
  const p = point(e), o = drag.orig
  if (drag.mode === 'draw') {
    const s = drag.start
    draft.value = { ...draft.value, left: Math.min(s.x, p.x), top: Math.min(s.y, p.y), width: Math.abs(p.x - s.x), height: Math.abs(p.y - s.y) }
  } else if (drag.mode === 'move') {
    draft.value = { ...o, left: clamp(o.left + p.x - drag.start.x, 0, 1 - o.width), top: clamp(o.top + p.y - drag.start.y, 0, 1 - o.height) }
  } else {
    // gegenüberliegende Ecke bleibt stehen
    const fx = drag.handle.includes('w') ? o.left + o.width : o.left
    const fy = drag.handle.includes('n') ? o.top + o.height : o.top
    draft.value = { ...o, left: Math.min(fx, p.x), top: Math.min(fy, p.y), width: Math.abs(p.x - fx), height: Math.abs(p.y - fy) }
  }
}

function up() {
  if (!drag) return
  // Versehentlicher Klick ohne Ziehen: alten Rahmen behalten
  if (draft.value && (draft.value.width < MIN || draft.value.height < MIN)) draft.value = drag.orig
  drag = null
}

function setShape(shape) { if (draft.value) draft.value = { ...draft.value, shape } }
const draftShape = computed({ get: () => draft.value?.shape ?? 'rect', set: setShape })

const box = c => ({ left: c.left * 100 + '%', top: c.top * 100 + '%', width: c.width * 100 + '%', height: c.height * 100 + '%' })
const shown = computed(() => persons.value.filter(p => p.crop && p.target_id !== editing.value))
const editingLabel = computed(() => persons.value.find(p => p.target_id === editing.value)?.label ?? '')
const hasSaved = computed(() => !!persons.value.find(p => p.target_id === editing.value)?.crop)

defineExpose({ edit })
</script>

<template>
<div class="media-regions">
  <div :class="['region-stage', { editing: editing != null }]" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">
    <img ref="img" :src="mediaFileUrl(media.id)" :alt="media.title || ''" draggable="false" />

    <!-- gespeicherte Markierungen der übrigen Personen -->
    <div v-for="p in shown" :key="p.target_id" :class="['region', 'region-' + p.crop.shape, { hovered: hovered === p.target_id, dim: editing != null }]"
      :style="box(p.crop)">
      <span v-if="editing == null" class="region-label">{{ p.label }}</span>
    </div>

    <!-- Rahmen in Bearbeitung -->
    <div v-if="draft" :class="['region', 'region-edit', 'region-' + draft.shape]" :style="box(draft)" data-frame="1">
      <span v-for="h in ['nw', 'ne', 'sw', 'se']" :key="h" :class="['region-handle', 'region-handle-' + h]" :data-handle="h" />
    </div>
  </div>

  <!-- Bearbeiten: Form, Speichern, Entfernen -->
  <div v-if="editing != null" class="region-toolbar">
    <p class="region-hint">
      <strong>{{ editingLabel }}</strong> – {{ draft ? t('media.region.hintAdjust') : t('media.region.hintDraw') }}
    </p>
    <div class="region-actions">
      <SelectButton v-model="draftShape" :options="shapeOptions" option-label="label" option-value="value" :allow-empty="false"
        size="small" :disabled="!draft" :aria-label="t('media.region.shape')" />
      <span class="spacer" />
      <Button v-if="hasSaved" :label="t('media.region.remove')" icon="pi pi-trash" severity="danger" text size="small"
        :disabled="saving" @click="save(null)" />
      <Button v-if="onlyPerson == null" :label="t('action.cancel')" text size="small" :disabled="saving" @click="cancel" />
      <Button :label="t('action.save')" icon="pi pi-check" size="small" :disabled="!draft" :loading="saving" @click="save(draft)" />
    </div>
  </div>

  <!-- Personen auf dem Bild -->
  <div v-else-if="persons.length && (canEdit || shown.length)" class="region-persons">
    <span class="muted">{{ t('media.region.persons') }}</span>
    <span v-for="p in persons" :key="p.target_id" :class="['region-chip', 'sex-' + (p.sex || 'U'), { marked: p.crop }]"
      @mouseenter="hovered = p.target_id" @mouseleave="hovered = null">
      <i v-if="p.crop" class="pi pi-user" :title="t('media.region.marked')" />
      {{ p.label }}
      <button v-if="canEdit" type="button" class="icon-btn" :title="p.crop ? t('media.region.change') : t('media.region.mark')"
        :aria-label="p.crop ? t('media.region.change') : t('media.region.mark')" @click="edit(p.target_id)">
        <i :class="['pi', p.crop ? 'pi-pencil' : 'pi-expand']" />
      </button>
    </span>
  </div>
</div>
</template>
