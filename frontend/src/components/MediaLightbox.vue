<script setup>
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import PersonPicker from './PersonPicker.vue'
import { lightbox } from '../composables/lightbox.js'
import { useRegionDrag } from '../composables/regionDrag.js'
import { isPersonLink, fileSuggestions } from '../composables/personRegion.js'
import { useNotify } from '../composables/feedback.js'
import { mediaFileUrl, fetchMedia, saveMediaRegion } from '../api/media.js'
import { t } from '../i18n/index.js'

/**
 * Großansicht eines Mediums über der ganzen App (openLightbox()).
 * Bilder: höchstens so groß wie der Anzeigebereich, zoombar per Mausrad, Knöpfen, Tasten (+ − 0)
 * und zwei Fingern; gezoomt per Ziehen verschiebbar. Ein Klick ohne Ziehen oder Esc schließt.
 * Mit crop beginnt die Ansicht auf diesem Bereich (die zitierte Stelle einer Quellenangabe).
 * Auf dem Bild markierte Personen erscheinen als Rahmen mit Namen (ausblendbar).
 *
 * Markierwerkzeug (oben rechts, wenn marks erlaubt): Person bzw. Bereich der Transkription wählen,
 * dann zieht Ziehen einen Rahmen auf, verschiebt ihn oder ändert ihn an den Ecken; das Bild verschiebt
 * sich dann mit Leertaste + Ziehen, mittlerer Maustaste oder zwei Fingern. Ein Klick schließt dabei nicht;
 * Übernehmen speichert, Abbrechen/Esc kehrt zur Ansicht zurück. Mit persons: 'all' lässt sich auch eine
 * weitere Person markieren und zuordnen – sie wird dabei mit dem Bild verknüpft.
 *
 * PDF, Video und Audio im Browser-eigenen Anzeiger; schließen per Klick daneben oder Esc.
 */
const PAD = 24          // Abstand zum Rand des Anzeigebereichs (px)
const notify = useNotify()
const media = computed(() => lightbox.value?.media ?? null)
const marks = computed(() => lightbox.value?.marks ?? null)
const kind  = computed(() => {
  const mime = media.value?.mime_type ?? ''
  return mime.startsWith('image/') ? 'image' : mime === 'application/pdf' ? 'pdf'
       : mime.startsWith('video/') ? 'video' : mime.startsWith('audio/') ? 'audio' : 'other'
})
const url = computed(() => media.value ? mediaFileUrl(media.value.id) : '')

function close() { lightbox.value = null }

// ── Zoomen und Verschieben (Bild) ──
const stage = ref(null)
const img   = ref(null)
const nat   = ref(null)                  // [Breite, Höhe] des angezeigten (richtig gedrehten) Bilds
const view  = reactive({ s: 1, x: 0, y: 0 })
const limits = reactive({ fit: 1, max: 4 })
const zoomed = computed(() => view.s > limits.fit * 1.01)

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))
const size  = () => { const r = stage.value.getBoundingClientRect(); return [r.width, r.height] }

/** Bild nicht aus dem Bild schieben; kleiner als der Anzeigebereich = mittig */
function constrain() {
  const [vw, vh] = size(), [w, h] = nat.value
  const iw = w * view.s, ih = h * view.s
  view.x = iw <= vw - 2 * PAD ? (vw - iw) / 2 : clamp(view.x, vw - iw - PAD, PAD)
  view.y = ih <= vh - 2 * PAD ? (vh - ih) / 2 : clamp(view.y, vh - ih - PAD, PAD)
}

/** Ganzes Bild einpassen – oder, mit Bereich, diesen Bereich gut drei Viertel füllend in die Mitte */
function reset(crop = null) {
  if (!nat.value || !stage.value) return
  const [vw, vh] = size(), [w, h] = nat.value
  const aw = vw - 2 * PAD, ah = vh - 2 * PAD
  limits.fit = Math.min(aw / w, ah / h, 1)               // höchstens Originalgröße
  limits.max = Math.max(limits.fit * 10, 4)
  if (crop) {
    view.s = clamp(0.8 * Math.min(aw / (crop.width * w), ah / (crop.height * h)), limits.fit, limits.max)
    view.x = vw / 2 - (crop.left + crop.width / 2) * w * view.s
    view.y = vh / 2 - (crop.top + crop.height / 2) * h * view.s
  } else {
    view.s = limits.fit
  }
  constrain()
}

function zoomAt(factor, cx, cy) {
  const s = clamp(view.s * factor, limits.fit, limits.max)
  view.x = cx - (cx - view.x) * (s / view.s)
  view.y = cy - (cy - view.y) * (s / view.s)
  view.s = s
  constrain()
}
function zoomCenter(factor) { const [vw, vh] = size(); zoomAt(factor, vw / 2, vh / 2) }

function onLoad(e) {
  nat.value = [e.target.naturalWidth, e.target.naturalHeight]
  reset(lightbox.value?.crop)
  startPending()
}

function onWheel(e) {
  if (kind.value !== 'image' || !nat.value) return
  const r = stage.value.getBoundingClientRect()
  zoomAt(Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top)
}

// ── Medium mit Verknüpfungen: markierte Personen, Vorschläge aus der Bilddatei ──
const full        = ref(null)
const showPersons = ref(true)
watch(() => kind.value === 'image' && media.value?.id, async id => {
  full.value = null
  if (!id) return
  try {
    const m = await fetchMedia(id)
    if (lightbox.value?.media?.id !== id) return   // inzwischen anderes Medium
    full.value = m
    startPending()
  } catch { /* ohne Markierungen anzeigen */ }
})
const persons = computed(() => (full.value?.links ?? []).filter(l => isPersonLink(l) && l.crop))

// ── Markierwerkzeug ──
const viewCrop   = ref(null)      // umrahmter Bereich (Quellenangabe); nach dem Markieren aktualisiert
const editingKey = ref(null)      // 'citation' | 'p<ID>' | null
const menuOpen   = ref(false)

/** Was sich markieren lässt – je Eintrag Titel, bisheriger Rahmen, Formen, Hinweise */
const targets = computed(() => {
  const m = marks.value
  if (!m || kind.value !== 'image') return []
  const out = []
  if (m.citation) out.push({
    key: 'citation', title: t('media.citationRegion.title'), crop: viewCrop.value, shapes: ['rect'],
    hintDraw: t('media.citationRegion.hintDraw'), hintAdjust: t('media.citationRegion.hintAdjust'),
    applyLabel: t('media.citationRegion.apply'), removeLabel: t('media.citationRegion.remove'), suggestions: [],
  })
  if (m.persons != null && full.value) {
    const suggestions = fileSuggestions(full.value)
    for (const l of full.value.links.filter(isPersonLink)) {
      if (m.persons !== 'all' && l.target_id !== m.persons) continue
      out.push({
        key: 'p' + l.target_id, personId: l.target_id, title: l.label, sex: l.sex, crop: l.crop, shapes: ['rect', 'ellipse'],
        hintDraw: t('media.region.hintDraw'), hintAdjust: t('media.region.hintAdjust'),
        applyLabel: t('action.save'), removeLabel: t('media.region.remove'), suggestions,
      })
    }
    // weitere Person: markieren und aus der Personenliste zuordnen (wird mit dem Bild verknüpft)
    if (m.persons === 'all') out.push({
      key: 'new', isNew: true, title: t('media.lightbox.newPerson'), crop: null, shapes: ['rect', 'ellipse'],
      hintDraw: t('media.lightbox.newPersonDraw'), hintAdjust: t('media.lightbox.newPersonAdjust'),
      applyLabel: t('action.save'), suggestions,
    })
  }
  return out
})
const edit = computed(() => targets.value.find(x => x.key === editingKey.value) ?? null)

const newPersonId    = ref('')
const newPersonLabel = ref('')
// schon markierte Personen nicht anbieten (die ändert man über ihren eigenen Eintrag)
const markedIds = computed(() => persons.value.map(p => p.target_id))

function startEdit(key) {
  const tg = targets.value.find(x => x.key === key)
  if (!tg) return
  menuOpen.value   = false
  newPersonId.value = ''
  newPersonLabel.value = ''
  editingKey.value = key
  draft.value      = tg.crop ? { ...tg.crop, shape: tg.crop.shape ?? tg.shapes[0] } : null
}
function stopEdit() { editingKey.value = null; draft.value = null; saving.value = false; space.value = false }
function onMarkTool() {
  if (edit.value) return stopEdit()
  if (targets.value.length === 1) startEdit(targets.value[0].key)
  else menuOpen.value = !menuOpen.value
}
// gleich markieren (z. B. „Markierung ändern“ in der Medienansicht), sobald Bild und Verknüpfungen da sind
function startPending() {
  const key = lightbox.value?.editNow
  if (!key || !nat.value || !targets.value.some(x => x.key === key)) return
  lightbox.value.editNow = null
  startEdit(key)
}

// ── Rahmen bearbeiten ──
const draft  = ref(null)          // { left, top, width, height, shape }
const saving = ref(false)
const space  = ref(false)         // Leertaste gedrückt: Ziehen verschiebt das Bild
const regionDrag = useRegionDrag(img, draft)
let drawing = false
let beforeDraw = null

const shape = computed({
  get: () => draft.value?.shape ?? edit.value?.shapes[0] ?? 'rect',
  set: v => { if (draft.value) draft.value = { ...draft.value, shape: v } },
})
function takeOver(s) { draft.value = { ...s.crop, shape: shape.value } }

async function apply(crop) {
  const e = edit.value
  if (!e || saving.value) return
  if (e.key === 'citation') {
    const c = crop && { left: crop.left, top: crop.top, width: crop.width, height: crop.height }
    marks.value.citation.save(c)
    viewCrop.value = c
    stopEdit()
    return
  }
  const personId = e.isNew ? Number(newPersonId.value) : e.personId
  if (!personId) return
  // neue Person: erst mit dem Bild verknüpfen (wird damit auch bei der Person als Medium geführt)
  const link = e.isNew && !(full.value?.links ?? []).some(l => isPersonLink(l) && l.target_id === personId)
  saving.value = true
  try {
    const m = await saveMediaRegion(media.value.id, personId, crop, { link })
    notify.success((link ? t('media.lightbox.linked', { name: newPersonLabel.value }) : crop ? t('media.region.saved') : t('media.region.removed'))
                   + (m.file_written ? ' – ' + t('media.region.inFile') : ''))
    full.value = m
    marks.value.onSaved?.(m)
    stopEdit()
  } catch (err) {
    notify.error(err?.error || t('media.region.saveError'))
    saving.value = false
  }
}

// Zeiger: Klick ohne Bewegung schließt (nicht beim Markieren), einer verschiebt bzw. zeichnet, zwei zoomen
const pointers = new Map()
let moved = false
let pinch = null
const panGesture = e => !edit.value || space.value || e.button === 1
function down(e) {
  if (e.button > 1 || (e.button === 1 && !edit.value)) return
  menuOpen.value = false
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (pointers.size === 1) {
    moved = false
    if (!panGesture(e) && kind.value === 'image' && nat.value) {
      beforeDraw = draft.value
      drawing = true
      regionDrag.down(e)          // setzt selbst die Zeigererfassung
      return
    }
  }
  if (pointers.size === 2) {
    // zweiter Finger: Zeichnen abbrechen, stattdessen zoomen
    if (drawing) { regionDrag.up(); draft.value = beforeDraw; drawing = false }
    pinch = pinchState()
  }
  e.currentTarget.setPointerCapture(e.pointerId)
  if (e.button === 1) e.preventDefault()   // kein Auto-Scrollen
}
function pinchState() {
  const [a, b] = [...pointers.values()]
  const r = stage.value.getBoundingClientRect()
  return { d: Math.hypot(a.x - b.x, a.y - b.y), cx: (a.x + b.x) / 2 - r.left, cy: (a.y + b.y) / 2 - r.top }
}
function move(e) {
  const p = pointers.get(e.pointerId)
  if (!p) return
  if (drawing) { regionDrag.move(e); p.x = e.clientX; p.y = e.clientY; return }
  const dx = e.clientX - p.x, dy = e.clientY - p.y
  if (!moved && Math.hypot(dx, dy) < 4) return
  moved = true
  p.x = e.clientX; p.y = e.clientY
  if (kind.value !== 'image' || !nat.value) return
  if (pointers.size === 1) {
    view.x += dx; view.y += dy
    constrain()
  } else if (pointers.size === 2 && pinch) {
    const n = pinchState()
    zoomAt(n.d / pinch.d, n.cx, n.cy)
    pinch = n
  }
}
function up(e) {
  if (!pointers.delete(e.pointerId)) return
  if (drawing) { drawing = false; regionDrag.up(); return }
  if (pointers.size < 2) pinch = null
  if (pointers.size === 0 && !moved && e.type === 'pointerup' && !edit.value) close()
}

function onKey(e) {
  if (e.key === 'Escape') {
    if (e.type !== 'keydown') return
    e.preventDefault()
    if (menuOpen.value) menuOpen.value = false
    else if (edit.value) stopEdit()
    else close()
    return
  }
  if (kind.value !== 'image' || !nat.value) return
  if (/^(INPUT|TEXTAREA)$/.test(e.target?.tagName)) return   // z. B. Personensuche
  if (edit.value && e.key === ' ') { e.preventDefault(); space.value = e.type === 'keydown'; return }
  if (e.type !== 'keydown') return
  if (e.key === '+' || e.key === '=') zoomCenter(1.25)
  else if (e.key === '-') zoomCenter(0.8)
  else if (e.key === '0') reset()
}
const onResize = () => reset()

// Öffnen/Schließen: Seite darunter nicht scrollen, Tasten abfangen
const closeBtn = ref(null)
watch(lightbox, async (v, old) => {
  if (v && !old) {
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey, true)
    window.addEventListener('keyup', onKey, true)
    window.addEventListener('resize', onResize)
  } else if (!v && old) {
    document.body.style.overflow = ''
    window.removeEventListener('keydown', onKey, true)
    window.removeEventListener('keyup', onKey, true)
    window.removeEventListener('resize', onResize)
  }
  if (v?.media?.id !== old?.media?.id) nat.value = null
  viewCrop.value = v?.crop ?? null
  stopEdit()
  menuOpen.value = false
  pointers.clear()
  drawing = false
  if (v) { await nextTick(); closeBtn.value?.focus() }
})
onBeforeUnmount(() => { if (lightbox.value) close() })

// Rahmen auf dem Bild (Bereich, markierte Personen, Vorschläge) – bewegen sich mit dem Bild
function boxStyle(c) {
  if (!c || !nat.value) return null
  const [w, h] = nat.value
  return { left: view.x + c.left * w * view.s + 'px', top: view.y + c.top * h * view.s + 'px',
           width: c.width * w * view.s + 'px', height: c.height * h * view.s + 'px' }
}
const frameStyle   = computed(() => edit.value?.key === 'citation' ? null : boxStyle(viewCrop.value))
// beim Markieren ohne die gerade bearbeitete Person
const shownPersons = computed(() => persons.value.filter(p => p.target_id !== edit.value?.personId))

const imgStyle = computed(() => ({
  width: nat.value ? nat.value[0] + 'px' : undefined,
  height: nat.value ? nat.value[1] + 'px' : undefined,
  transform: `translate(${view.x}px, ${view.y}px) scale(${view.s})`,
  visibility: nat.value ? 'visible' : 'hidden',
}))
</script>

<template>
  <Teleport to="body">
    <Transition name="lightbox">
      <div v-if="media" :class="['lightbox', { 'lightbox-editing': edit }]" role="dialog" aria-modal="true" :aria-label="media.title || ''">
        <!-- Inhalt -->
        <div ref="stage" :class="['lightbox-stage', 'lightbox-' + kind, { zoomed, panning: space }]"
          @wheel.prevent="onWheel" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">
          <img v-if="kind === 'image'" ref="img" :key="url" :src="url" :alt="media.title || ''" :style="imgStyle" draggable="false" @load="onLoad" />
          <template v-if="kind === 'image' && nat">
            <template v-if="showPersons">
              <div v-for="p in shownPersons" :key="p.target_id"
                :class="['region', 'lightbox-person', 'region-' + p.crop.shape, { dim: edit }]" :style="boxStyle(p.crop)">
                <span :class="['region-label', 'sex-' + (p.sex || 'U')]">{{ p.label }}</span>
              </div>
            </template>
            <div v-if="frameStyle" :class="['lightbox-frame', { dim: edit }]" :style="frameStyle" />
            <template v-if="edit">
              <div v-for="(s, i) in edit.suggestions" :key="'s' + i" class="region region-suggest" :style="boxStyle(s.crop)">
                <span v-if="s.name" class="region-label">{{ s.name }}?</span>
              </div>
              <div v-if="draft" :class="['region', 'region-edit', 'region-' + draft.shape]" :style="boxStyle(draft)" data-frame="1">
                <span v-for="h in ['nw', 'ne', 'sw', 'se']" :key="h" :class="['region-handle', 'region-handle-' + h]" :data-handle="h" />
              </div>
            </template>
          </template>
          <iframe v-else-if="kind === 'pdf'" :src="url" :title="media.title || ''" @pointerdown.stop />
          <video v-else-if="kind === 'video'" :src="url" controls autoplay @pointerdown.stop />
          <audio v-else-if="kind === 'audio'" :src="url" controls autoplay @pointerdown.stop />
        </div>

        <!-- Werkzeuge -->
        <div class="lightbox-tools" @pointerdown.stop>
          <template v-if="kind === 'image'">
            <button type="button" class="lightbox-btn" :title="t('media.lightbox.zoomOut')" :aria-label="t('media.lightbox.zoomOut')"
              :disabled="!zoomed" @click="zoomCenter(0.8)"><i class="pi pi-search-minus" /></button>
            <button type="button" class="lightbox-btn" :title="t('media.lightbox.zoomIn')" :aria-label="t('media.lightbox.zoomIn')"
              @click="zoomCenter(1.25)"><i class="pi pi-search-plus" /></button>
            <button type="button" class="lightbox-btn" :title="t('media.lightbox.fit')" :aria-label="t('media.lightbox.fit')"
              :disabled="!zoomed" @click="reset()"><i class="pi pi-arrows-alt" /></button>
            <button v-if="shownPersons.length" type="button" :class="['lightbox-btn', { active: showPersons }]"
              :title="showPersons ? t('media.lightbox.hidePersons') : t('media.lightbox.showPersons')"
              :aria-label="showPersons ? t('media.lightbox.hidePersons') : t('media.lightbox.showPersons')" :aria-pressed="showPersons"
              @click="showPersons = !showPersons"><i class="pi pi-users" /></button>
            <!-- Markierwerkzeug: eine Möglichkeit → gleich markieren, mehrere → Auswahl -->
            <span v-if="targets.length" class="lightbox-tool-wrap">
              <button type="button" :class="['lightbox-btn', { active: edit || menuOpen }]"
                :title="targets.length === 1 ? t('media.lightbox.markOne', { name: targets[0].title }) : t('media.lightbox.mark')"
                :aria-label="t('media.lightbox.mark')" :aria-pressed="!!edit" :aria-expanded="targets.length > 1 ? menuOpen : undefined"
                @click="onMarkTool"><i class="pi pi-expand" /></button>
              <div v-if="menuOpen" class="lightbox-menu" role="menu">
                <div class="lightbox-menu-title">{{ t('media.lightbox.markWhat') }}</div>
                <button v-for="tg in targets" :key="tg.key" type="button" role="menuitem"
                  :class="['lightbox-menu-item', { 'lightbox-menu-new': tg.isNew }]" @click="startEdit(tg.key)">
                  <i :class="['pi', tg.key === 'citation' ? 'pi-book' : tg.isNew ? 'pi-user-plus' : 'pi-user']" />
                  <span>{{ tg.title }}</span>
                  <i v-if="tg.crop" class="pi pi-check lightbox-menu-marked" :title="t('media.region.marked')" />
                </button>
              </div>
            </span>
          </template>
          <a v-if="!edit" class="lightbox-btn" :href="url" target="_blank" rel="noopener" :title="t('media.lightbox.newTab')"
            :aria-label="t('media.lightbox.newTab')" @click="close"><i class="pi pi-external-link" /></a>
          <button ref="closeBtn" type="button" class="lightbox-btn" :title="t('action.close') + ' (Esc)'" :aria-label="t('action.close')"
            @click="close"><i class="pi pi-times" /></button>
        </div>

        <!-- Markieren: Hinweis, Form, Vorschläge, Übernehmen -->
        <div v-if="edit" class="lightbox-editbar" @pointerdown.stop>
          <p class="lightbox-edit-hint">
            <strong>{{ edit.title }}</strong> – {{ draft ? edit.hintAdjust : edit.hintDraw }}
            <span class="lightbox-edit-pan">{{ t('media.lightbox.panHint') }}</span>
          </p>
          <div class="lightbox-edit-actions">
            <span v-if="edit.isNew" class="lightbox-person-pick">
              <PersonPicker v-model="newPersonId" v-model:label="newPersonLabel" :exclude-ids="markedIds" append-to="self"
                :placeholder="t('media.region.choose')" />
            </span>
            <span v-if="edit.shapes.length > 1" class="lightbox-shapes" role="group" :aria-label="t('media.region.shape')">
              <button v-for="s in edit.shapes" :key="s" type="button" :class="['lightbox-chip', { active: shape === s }]"
                :disabled="!draft" :aria-pressed="shape === s" @click="shape = s">{{ t('media.region.' + s) }}</button>
            </span>
            <span v-if="edit.suggestions.length" class="lightbox-suggest">
              <span>{{ t('media.region.fromFile') }}</span>
              <button v-for="(s, i) in edit.suggestions" :key="i" type="button" class="lightbox-chip" :title="t('media.region.takeOver')"
                @click="takeOver(s)"><i class="pi pi-arrow-down-left" /> {{ s.name || t('media.region.unnamed') }}</button>
            </span>
            <span class="spacer" />
            <button v-if="edit.crop" type="button" class="lightbox-chip danger" :disabled="saving" @click="apply(null)">
              <i class="pi pi-trash" /> {{ edit.removeLabel }}</button>
            <button type="button" class="lightbox-chip" :disabled="saving" @click="stopEdit">{{ t('action.cancel') }}</button>
            <button type="button" class="lightbox-chip primary" :disabled="!draft || saving || (edit.isNew && !newPersonId)"
              :title="edit.isNew && !newPersonId ? t('media.lightbox.choosePerson') : undefined" @click="apply(draft)">
              <i :class="['pi', saving ? 'pi-spin pi-spinner' : 'pi-check']" /> {{ edit.applyLabel }}</button>
          </div>
        </div>
        <div v-else class="lightbox-caption">
          <strong v-if="media.title">{{ media.title }}</strong>
          <span>{{ kind === 'image' ? t('media.lightbox.hint') : t('media.lightbox.hintOther') }}</span>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
