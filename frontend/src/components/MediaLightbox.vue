<script setup>
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { lightbox } from '../composables/lightbox.js'
import { mediaFileUrl, fetchMedia } from '../api/media.js'
import { t } from '../i18n/index.js'

/**
 * Großansicht eines Mediums über der ganzen App (geöffnet mit openLightbox()).
 * Bilder: höchstens so groß wie der Anzeigebereich, zoombar per Mausrad, Knöpfen, Tasten (+ − 0)
 * und zwei Fingern; gezoomt per Ziehen verschiebbar. Ein Klick ohne Ziehen oder Esc schließt.
 * Mit crop beginnt die Ansicht auf diesem Bereich (die zitierte Stelle einer Quellenangabe).
 * Auf dem Bild markierte Personen erscheinen als Rahmen mit Namen (ausblendbar).
 * PDF, Video und Audio im Browser-eigenen Anzeiger; schließen per Klick daneben oder Esc.
 */
const PAD = 24          // Abstand zum Rand des Anzeigebereichs (px)
const media = computed(() => lightbox.value?.media ?? null)
const kind  = computed(() => {
  const mime = media.value?.mime_type ?? ''
  return mime.startsWith('image/') ? 'image' : mime === 'application/pdf' ? 'pdf'
       : mime.startsWith('video/') ? 'video' : mime.startsWith('audio/') ? 'audio' : 'other'
})
const url = computed(() => media.value ? mediaFileUrl(media.value.id) : '')

function close() { lightbox.value = null }

// ── Zoomen und Verschieben (Bild) ──
const stage = ref(null)
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

/** Ganzes Bild einpassen – oder, mit Bereich, diesen Bereich füllend in die Mitte */
function reset(crop = null) {
  if (!nat.value || !stage.value) return
  const [vw, vh] = size(), [w, h] = nat.value
  const aw = vw - 2 * PAD, ah = vh - 2 * PAD
  limits.fit = Math.min(aw / w, ah / h, 1)               // höchstens Originalgröße
  limits.max = Math.max(limits.fit * 10, 4)
  if (crop) {
    // Bereich füllt gut drei Viertel – rundherum bleibt etwas Umgebung sichtbar
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
}

function onWheel(e) {
  if (kind.value !== 'image' || !nat.value) return
  const r = stage.value.getBoundingClientRect()
  zoomAt(Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top)
}

// Zeiger: einer verschiebt, zwei zoomen; Klick ohne Bewegung schließt
const pointers = new Map()
let moved = false
let pinch = null
function down(e) {
  if (e.button > 0) return
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (pointers.size === 1) moved = false
  if (pointers.size === 2) pinch = pinchState()
  e.currentTarget.setPointerCapture(e.pointerId)
}
function pinchState() {
  const [a, b] = [...pointers.values()]
  const r = stage.value.getBoundingClientRect()
  return { d: Math.hypot(a.x - b.x, a.y - b.y), cx: (a.x + b.x) / 2 - r.left, cy: (a.y + b.y) / 2 - r.top }
}
function move(e) {
  const p = pointers.get(e.pointerId)
  if (!p) return
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
  if (pointers.size < 2) pinch = null
  if (pointers.size === 0 && !moved && e.type === 'pointerup') close()
}

function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); close() }
  else if (kind.value !== 'image' || !nat.value) return
  else if (e.key === '+' || e.key === '=') zoomCenter(1.25)
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
    window.addEventListener('resize', onResize)
  } else if (!v && old) {
    document.body.style.overflow = ''
    window.removeEventListener('keydown', onKey, true)
    window.removeEventListener('resize', onResize)
  }
  nat.value = null
  if (v) { await nextTick(); closeBtn.value?.focus() }
})
onBeforeUnmount(() => { if (lightbox.value) close() })

// Rahmen auf dem Bild (Bereich der Quellenangabe, markierte Personen) – bewegen sich mit dem Bild
function boxStyle(c) {
  if (!c || !nat.value) return null
  const [w, h] = nat.value
  return { left: view.x + c.left * w * view.s + 'px', top: view.y + c.top * h * view.s + 'px',
           width: c.width * w * view.s + 'px', height: c.height * h * view.s + 'px' }
}
const frameStyle = computed(() => boxStyle(lightbox.value?.crop))

// Markierte Personen: aus den Verknüpfungen des Mediums (nur direkt verknüpfte Personen mit Ausschnitt)
const persons     = ref([])
const showPersons = ref(true)
watch(() => kind.value === 'image' && media.value?.id, async id => {
  persons.value = []
  if (!id) return
  try {
    const m = await fetchMedia(id)
    if (lightbox.value?.media?.id !== id) return   // inzwischen anderes Medium
    persons.value = (m.links ?? []).filter(l => l.kind === 'person' && !l.citation && l.crop)
  } catch { /* ohne Markierungen anzeigen */ }
})

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
      <div v-if="media" class="lightbox" role="dialog" aria-modal="true" :aria-label="media.title || ''">
        <!-- Inhalt -->
        <div ref="stage" :class="['lightbox-stage', 'lightbox-' + kind, { zoomed }]"
          @wheel.prevent="onWheel" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">
          <img v-if="kind === 'image'" :key="url" :src="url" :alt="media.title || ''" :style="imgStyle" draggable="false" @load="onLoad" />
          <template v-if="kind === 'image' && nat && showPersons">
            <div v-for="p in persons" :key="p.target_id" :class="['region', 'lightbox-person', 'region-' + p.crop.shape]"
              :style="boxStyle(p.crop)">
              <span :class="['region-label', 'sex-' + (p.sex || 'U')]">{{ p.label }}</span>
            </div>
          </template>
          <div v-if="kind === 'image' && frameStyle" class="lightbox-frame" :style="frameStyle" />
          <iframe v-else-if="kind === 'pdf'" :src="url" :title="media.title || ''" @pointerdown.stop />
          <video v-else-if="kind === 'video'" :src="url" controls autoplay @pointerdown.stop />
          <audio v-else-if="kind === 'audio'" :src="url" controls autoplay @pointerdown.stop />
        </div>

        <!-- Knöpfe -->
        <div class="lightbox-tools" @pointerdown.stop>
          <template v-if="kind === 'image'">
            <button type="button" class="lightbox-btn" :title="t('media.lightbox.zoomOut')" :aria-label="t('media.lightbox.zoomOut')"
              :disabled="!zoomed" @click="zoomCenter(0.8)"><i class="pi pi-search-minus" /></button>
            <button type="button" class="lightbox-btn" :title="t('media.lightbox.zoomIn')" :aria-label="t('media.lightbox.zoomIn')"
              @click="zoomCenter(1.25)"><i class="pi pi-search-plus" /></button>
            <button type="button" class="lightbox-btn" :title="t('media.lightbox.fit')" :aria-label="t('media.lightbox.fit')"
              :disabled="!zoomed" @click="reset()"><i class="pi pi-arrows-alt" /></button>
            <button v-if="persons.length" type="button" :class="['lightbox-btn', { active: showPersons }]"
              :title="showPersons ? t('media.lightbox.hidePersons') : t('media.lightbox.showPersons')"
              :aria-label="showPersons ? t('media.lightbox.hidePersons') : t('media.lightbox.showPersons')" :aria-pressed="showPersons"
              @click="showPersons = !showPersons"><i class="pi pi-users" /></button>
          </template>
          <a class="lightbox-btn" :href="url" target="_blank" rel="noopener" :title="t('media.lightbox.newTab')"
            :aria-label="t('media.lightbox.newTab')" @click="close"><i class="pi pi-external-link" /></a>
          <button ref="closeBtn" type="button" class="lightbox-btn" :title="t('action.close') + ' (Esc)'" :aria-label="t('action.close')"
            @click="close"><i class="pi pi-times" /></button>
        </div>

        <div class="lightbox-caption">
          <strong v-if="media.title">{{ media.title }}</strong>
          <span>{{ kind === 'image' ? t('media.lightbox.hint') : t('media.lightbox.hintOther') }}</span>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
