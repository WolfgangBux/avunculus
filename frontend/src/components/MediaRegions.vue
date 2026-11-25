<script setup>
import { ref, computed, watch } from 'vue'
import Button from 'openvue/button'
import SelectButton from 'openvue/selectbutton'
import PersonPicker from './PersonPicker.vue'
import { mediaFileUrl, saveMediaRegion } from '../api/media.js'
import { useNotify } from '../composables/feedback.js'
import { useRegionDrag, regionBox } from '../composables/regionDrag.js'
import { openLightbox } from '../composables/lightbox.js'
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
 *
 * Vorschläge: in der Bilddatei markierte Personen (XMP aus Lightroom, digiKam …, media.file_regions),
 * gestrichelt dargestellt. Passt der Name zu einer verknüpften Person, genügt ein Klick; sonst wird eine
 * Person gewählt und dabei mit dem Bild verknüpft. Beim Bearbeiten lässt sich ein Vorschlag als Rahmen übernehmen.
 */
const props = defineProps({
  media:      { type: Object, required: true },   // mit links (MediaController::show)
  onlyPerson: { type: Number, default: null },
})
const emit   = defineEmits(['saved'])
const notify = useNotify()

// Person direkt mit dem Bild verknüpft (nicht nur über eine ihrer Quellenangaben)
const isPersonLink = l => l.kind === 'person' && !l.citation

const persons = computed(() => (props.media.links ?? [])
  .filter(l => isPersonLink(l) && (props.onlyPerson == null || l.target_id === props.onlyPerson)))

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

async function save(crop, personId = editing.value, opts = {}) {
  saving.value = true
  try {
    const m = await saveMediaRegion(props.media.id, personId, crop, opts)
    notify.success((crop ? t('media.region.saved') : t('media.region.removed'))
                   + (m.file_written ? ' – ' + t('media.region.inFile') : ''))
    cancel()
    assigning.value = null
    emit('saved', m)
  } catch (e) {
    notify.error(e?.error || t('media.region.saveError'))
  } finally {
    saving.value = false
  }
}

// ── Ziehen: neuen Rahmen aufziehen, verschieben oder an einer Ecke ändern ──
const { down, move, up } = useRegionDrag(img, draft, () => editing.value != null)

function setShape(shape) { if (draft.value) draft.value = { ...draft.value, shape } }
const draftShape = computed({ get: () => draft.value?.shape ?? 'rect', set: setShape })

// ── Vorschläge aus der Bilddatei ──
// Schon erledigt, wenn eine markierte Person fast denselben Rahmen hat
function overlap(a, b) {
  const w = Math.min(a.left + a.width, b.left + b.width) - Math.max(a.left, b.left)
  const h = Math.min(a.top + a.height, b.top + b.height) - Math.max(a.top, b.top)
  if (w <= 0 || h <= 0) return 0
  return (w * h) / (a.width * a.height + b.width * b.height - w * h)
}

// „Marianne Nigl“ ↔ „Nigl, Marianne“: verknüpfte Person ohne Rahmen mit gleichem Namen
const norm = s => (s ?? '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim()
function matchPerson(name) {
  const n = norm(name)
  if (!n) return null
  return (props.media.links ?? []).find(l => {
    if (!isPersonLink(l) || l.crop) return false
    const [sur, given = ''] = (l.label ?? '').split(',').map(x => x.trim())
    return norm(`${given} ${sur}`) === n || norm(`${sur} ${given}`) === n
  }) ?? null
}

const suggestions = computed(() => (props.media.file_regions ?? [])
  .filter(s => !(props.media.links ?? []).some(l => isPersonLink(l) && l.crop && overlap(l.crop, s.crop) > 0.5))
  .map((s, i) => ({ ...s, key: i, match: matchPerson(s.name) })))

const assigning = ref(null)   // Schlüssel des Vorschlags, für den gerade eine Person gewählt wird
const pickId    = ref('')
function startAssign(s) { assigning.value = s.key; pickId.value = '' }
function assign(s) {
  if (!pickId.value) return
  const id = Number(pickId.value)
  const linked = (props.media.links ?? []).some(l => isPersonLink(l) && l.target_id === id)
  save({ ...s.crop }, id, { link: !linked })
}
function takeOver(s) { draft.value = { ...s.crop, shape: draft.value?.shape ?? 'rect' } }

const box = regionBox
const shown = computed(() => persons.value.filter(p => p.crop && p.target_id !== editing.value))
const editingLabel = computed(() => persons.value.find(p => p.target_id === editing.value)?.label ?? '')
const hasSaved = computed(() => !!persons.value.find(p => p.target_id === editing.value)?.crop)

defineExpose({ edit })
</script>

<template>
<div class="media-regions">
  <div :class="['region-stage', { editing: editing != null }]" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up"
    @click="editing == null && openLightbox(media)">
    <img ref="img" :src="mediaFileUrl(media.id)" :alt="media.title || ''" draggable="false" />

    <!-- Vorschläge aus der Bilddatei -->
    <div v-for="s in suggestions" :key="'s' + s.key"
      :class="['region', 'region-suggest', { hovered: hovered === 's' + s.key || assigning === s.key, dim: editing != null }]"
      :style="box(s.crop)">
      <span v-if="editing == null && s.name" class="region-label">{{ s.name }}?</span>
    </div>

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
    <p v-if="suggestions.length" class="region-takeover">
      <span class="muted">{{ t('media.region.fromFile') }}</span>
      <Button v-for="s in suggestions" :key="s.key" :label="s.name || t('media.region.unnamed')" icon="pi pi-arrow-down-left" text size="small"
        :title="t('media.region.takeOver')" @mouseenter="hovered = 's' + s.key" @mouseleave="hovered = null" @click="takeOver(s)" />
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

  <!-- Vorschläge aus der Bilddatei: Person zuordnen -->
  <div v-if="editing == null && canEdit && suggestions.length && onlyPerson == null" class="region-persons region-suggestions">
    <span class="muted">{{ t('media.region.fromFile') }}</span>
    <template v-for="s in suggestions" :key="s.key">
      <span v-if="assigning !== s.key" class="region-chip region-chip-suggest"
        @mouseenter="hovered = 's' + s.key" @mouseleave="hovered = null">
        {{ s.name || t('media.region.unnamed') }}
        <Button v-if="s.match" :label="t('media.region.takeFor', { name: s.match.label })" icon="pi pi-check" text size="small"
          :loading="saving" @click="save({ ...s.crop }, s.match.target_id)" />
        <Button v-else :label="t('media.region.assign')" icon="pi pi-user-plus" text size="small" @click="startAssign(s)" />
      </span>
      <span v-else class="region-assign">
        <span>{{ t('media.region.assignFor', { name: s.name || t('media.region.unnamed') }) }}</span>
        <PersonPicker v-model="pickId" :placeholder="t('media.region.choose')" class="region-picker" />
        <Button :label="t('action.cancel')" text size="small" @click="assigning = null" />
        <Button :label="t('media.region.assign')" icon="pi pi-check" size="small" :disabled="!pickId" :loading="saving" @click="assign(s)" />
      </span>
    </template>
  </div>

  <!-- Personen auf dem Bild -->
  <div v-if="editing == null && persons.length && (canEdit || shown.length)" class="region-persons">
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
