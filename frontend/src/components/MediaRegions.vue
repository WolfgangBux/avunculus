<script setup>
import { ref, computed } from 'vue'
import Button from 'openvue/button'
import PersonPicker from './PersonPicker.vue'
import { mediaFileUrl, saveMediaRegion } from '../api/media.js'
import { useNotify } from '../composables/feedback.js'
import { regionBox } from '../composables/regionDrag.js'
import { openLightbox } from '../composables/lightbox.js'
import { isPersonLink, fileSuggestions } from '../composables/personRegion.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/**
 * Bild mit markierten Personen (Medienansicht): je verknüpfter Person ein Rahmen (Rechteck oder Kreis/Oval),
 * der ihren Ausschnitt bestimmt – das Vorschaubild der Person (Liste, Stammbaum) zeigt dann nur diesen Teil.
 * Ein Klick aufs Bild öffnet die Großansicht; markiert wird dort mit dem Markierwerkzeug („Markieren“ bei
 * einer Person öffnet sie gleich dafür).
 *
 * Koordinaten sind Anteile (0–1) des angezeigten, also richtig gedrehten Bilds – so passt der Ausschnitt
 * unabhängig von der Auflösung, und das Vorschaubild schneidet nach dem Drehen genauso zu.
 *
 * Vorschläge: in der Bilddatei markierte Personen (XMP aus Lightroom, digiKam …, media.file_regions),
 * gestrichelt dargestellt. Passt der Name zu einer verknüpften Person, genügt ein Klick; sonst wird eine
 * Person gewählt und dabei mit dem Bild verknüpft. Beim Markieren lässt sich ein Vorschlag als Rahmen übernehmen.
 */
const props = defineProps({
  media: { type: Object, required: true },   // mit links (MediaController::show)
})
const emit   = defineEmits(['saved'])
const notify = useNotify()

const persons = computed(() => (props.media.links ?? []).filter(isPersonLink))
const shown   = computed(() => persons.value.filter(p => p.crop))
const hovered = ref(null)
const saving  = ref(false)

// Großansicht: Bearbeiter können dort alle verknüpften Personen markieren
const lightboxOpts = () => canEdit.value ? { marks: { persons: 'all', onSaved: m => emit('saved', m) } } : {}
const open = () => openLightbox(props.media, lightboxOpts())
const edit = personId => openLightbox(props.media, { ...lightboxOpts(), editNow: 'p' + personId })

defineExpose({ open })

/** Vorschlag direkt übernehmen (ohne Zeichnen) */
async function save(crop, personId, opts = {}) {
  saving.value = true
  try {
    const m = await saveMediaRegion(props.media.id, personId, crop, opts)
    notify.success(t('media.region.saved') + (m.file_written ? ' – ' + t('media.region.inFile') : ''))
    assigning.value = null
    emit('saved', m)
  } catch (e) {
    notify.error(e?.error || t('media.region.saveError'))
  } finally {
    saving.value = false
  }
}

// ── Vorschläge aus der Bilddatei ──
// „Marianne Nigl“ ↔ „Nigl, Marianne“: verknüpfte Person ohne Rahmen mit gleichem Namen
const norm = s => (s ?? '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim()
function matchPerson(name) {
  const n = norm(name)
  if (!n) return null
  return persons.value.find(l => {
    if (l.crop) return false
    const [sur, given = ''] = (l.label ?? '').split(',').map(x => x.trim())
    return norm(`${given} ${sur}`) === n || norm(`${sur} ${given}`) === n
  }) ?? null
}

const suggestions = computed(() => fileSuggestions(props.media).map((s, i) => ({ ...s, key: i, match: matchPerson(s.name) })))

const assigning = ref(null)   // Schlüssel des Vorschlags, für den gerade eine Person gewählt wird
const pickId    = ref('')
function startAssign(s) { assigning.value = s.key; pickId.value = '' }
function assign(s) {
  if (!pickId.value) return
  const id = Number(pickId.value)
  save({ ...s.crop }, id, { link: !persons.value.some(l => l.target_id === id) })
}

const box = regionBox
</script>

<template>
<div class="media-regions">
  <div class="region-stage" @click="open">
    <img :src="mediaFileUrl(media.id)" :alt="media.title || ''" draggable="false" />

    <!-- Vorschläge aus der Bilddatei -->
    <div v-for="s in suggestions" :key="'s' + s.key"
      :class="['region', 'region-suggest', { hovered: hovered === 's' + s.key || assigning === s.key }]" :style="box(s.crop)">
      <span v-if="s.name" class="region-label">{{ s.name }}?</span>
    </div>

    <!-- markierte Personen -->
    <div v-for="p in shown" :key="p.target_id" :class="['region', 'region-' + p.crop.shape, { hovered: hovered === p.target_id }]"
      :style="box(p.crop)">
      <span class="region-label">{{ p.label }}</span>
    </div>
  </div>

  <!-- Vorschläge aus der Bilddatei: Person zuordnen -->
  <div v-if="canEdit && suggestions.length" class="region-persons region-suggestions">
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

  <!-- Personen auf dem Bild; Markieren/Ändern öffnet die Großansicht -->
  <div v-if="persons.length && (canEdit || shown.length)" class="region-persons">
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
