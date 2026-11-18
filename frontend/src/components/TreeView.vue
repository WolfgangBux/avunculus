<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import Select from 'openvue/select'
import Button from 'openvue/button'
import { fetchPedigree } from '../api/families.js'
import { mediaThumbUrl } from '../api/media.js'
import PersonPicker from './PersonPicker.vue'
import TreeHoverCard from './TreeHoverCard.vue'
import { fetchPerson } from '../api/persons.js'
import { t } from '../i18n/index.js'
import { canEdit, isAdmin } from '../auth.js'
import { nameLabel, nameParts, lifeSpan, sexClass, displayDate } from '../format.js'
import { extractYear } from '../gedcom.js'

const props = defineProps({
  rootId:  { type: [Number, String], default: null },
  startId: { type: Number, default: null },   // Startperson des Baums
  refresh: Number,
})
const emit = defineEmits(['select-root', 'edit', 'family', 'create', 'set-start'])

// ── Geometrie ────────────────────────────────────────────
const CARD_W = 176   // Kartenbreite
const CARD_H = 68    // Kartenhöhe
const PAD    = 48    // Rand um den Baum

// Das Layout rechnet in „Breite“ (Geschwister/Partner nebeneinander) und „Tiefe“ (Generationen).
// Von unten nach oben: Breite = x, Tiefe = y. Von links nach rechts: gedreht, Vorfahren rechts.
const GEOMETRY = {
  vertical:   { B: CARD_W, D: CARD_H, GAP: 18, COUPLE_GAP: 40, ROW_GAP: 64 },
  horizontal: { B: CARD_H, D: CARD_W, GAP: 26, COUPLE_GAP: 20, ROW_GAP: 56 },
}

const ORIENT_KEY  = 'avunculus.treeOrientation'
const orientation = ref((() => { try { return localStorage.getItem(ORIENT_KEY) === 'horizontal' ? 'horizontal' : 'vertical' } catch { return 'vertical' } })())
function toggleOrientation() {
  orientation.value = orientation.value === 'vertical' ? 'horizontal' : 'vertical'
  try { localStorage.setItem(ORIENT_KEY, orientation.value) } catch { /* kein Storage */ }
}

// ── Daten ────────────────────────────────────────────────
// Der Server liefert nur den sichtbaren Ausschnitt (Vorfahren, Partner, Kinder der Hauptperson)
const persons  = ref([])
const families = ref([])
const total    = ref(0)
const rootIdLoaded = ref(null)
const loading  = ref(false)
const error    = ref('')

const generations = ref(3)
const generationOptions = [1, 2, 3, 4, 5, 6, 7, 8].map(n => ({ value: n, label: String(n) }))

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true
  error.value   = ''
  try {
    const res = await fetchPedigree(props.rootId ? Number(props.rootId) : null, generations.value)
    if (my !== seq) return
    persons.value      = res.persons
    families.value     = res.families
    total.value        = res.total
    rootIdLoaded.value = res.root
    // Gewählte Person gibt es nicht (mehr) → Server hat eine andere gewählt
    if (res.root && Number(props.rootId) !== res.root) emit('select-root', res.root)
  } catch {
    if (my === seq) error.value = t('tree.loadError')
  } finally {
    if (my === seq) loading.value = false
  }
}

onMounted(load)
watch(() => [props.refresh, generations.value], load)
// Neu laden nur, wenn sich die Hauptperson wirklich ändert (nicht, wenn der Server sie gerade gewählt hat)
watch(() => props.rootId, id => { if (Number(id) !== rootIdLoaded.value) load() })

const byId = computed(() => new Map(persons.value.map(p => [Number(p.id), p])))

/** Abstammungsart eines Kindes in einer Familie (PEDI) */
const pedigreeIn    = (family, childId) => family?.members.find(m => m.role === 'CHIL' && m.person_id === childId)?.pedigree ?? null
const notBiological = pedigree => !!pedigree && pedigree !== 'BIRTH'

/** Eltern je Kind und Partnerschaften je Person aus den Familien ableiten */
const relations = computed(() => {
  const parents = new Map()   // childId  -> { father, mother, family }
  const unions  = new Map()   // personId -> [{ partner, children, family }]
  for (const f of families.value) {
    const husb = f.members.find(m => m.role === 'HUSB')?.person_id ?? null
    const wife = f.members.find(m => m.role === 'WIFE')?.person_id ?? null
    const kids = f.members.filter(m => m.role === 'CHIL').map(m => m.person_id)
    for (const k of kids) {
      if (!parents.has(k)) parents.set(k, { father: husb, mother: wife, family: f, pedigree: pedigreeIn(f, k) })
    }
    for (const [self, partner] of [[husb, wife], [wife, husb]]) {
      if (self == null) continue
      if (!unions.has(self)) unions.set(self, [])
      unions.get(self).push({ partner, children: kids, family: f })
    }
  }
  return { parents, unions }
})

const root = computed(() => byId.value.get(Number(rootIdLoaded.value)) ?? null)

// ── Layout ───────────────────────────────────────────────
//
// Kompakte Anordnung über Konturen: Jeder Teilbaum kennt je Reihe seine linke und rechte
// Kante. Zwei Teilbäume rücken so dicht zusammen, wie es die tatsächlich vorhandenen
// Karten erlauben – fehlende Vorfahren bzw. Nachkommen hinterlassen keine Lücken.
//
// Vorfahren: Eltern als Paar mittig über dem Kind, Vater links, Mutter rechts.
// Die Geschwister der Hauptperson stehen in ihrer Reihe (ältere links, jüngere rechts
// hinter den Partnern). Nachkommen mit Partnern bilden darunter einen Baum: so viele
// Generationen, wie die Vorfahren von der gewählten Anzahl übrig lassen, mindestens die Kinder.
//
// Gerechnet wird in x = Breite, y = Tiefe (Vorfahren oben); erst am Ende wird je nach
// Ausrichtung auf Bildschirmkoordinaten abgebildet.
const layout = computed(() => {
  const r = root.value
  if (!r) return null
  const horizontal = orientation.value === 'horizontal'
  const { B, D, GAP, COUPLE_GAP, ROW_GAP } = GEOMETRY[orientation.value]
  const SLOT = B + GAP
  const SEP  = GAP + COUPLE_GAP     // Abstand zwischen Karten verschiedener Familien

  const { parents, unions } = relations.value
  const get = id => (id == null ? null : byId.value.get(Number(id)) ?? null)
  const rootId = Number(r.id)

  function depth(id, g) {
    if (g >= generations.value) return 0
    const p = parents.get(id)
    if (!p || (p.father == null && p.mother == null)) return 0
    return 1 + Math.max(
      p.father != null ? depth(p.father, g + 1) : 0,
      p.mother != null ? depth(p.mother, g + 1) : 0,
    )
  }
  const ancestorDepth = depth(rootId, 0)
  const G = Math.max(1, ancestorDepth)
  const descLevels = Math.max(1, generations.value - ancestorDepth)

  const rowY = g => -g * (D + ROW_GAP)   // g > 0: Vorfahren, g < 0: Nachkommen, 0: Hauptperson
  const midY = y => y + D / 2

  // dashed: Kind ist nicht leiblich (adoptiert, Pflegekind …)
  function elbow(x1, y1, x2, y2, dashed = false) {
    const ym = y2 - ROW_GAP / 2
    return { pts: [[x1, y1], [x1, ym], [x2, ym], [x2, y2]], dashed }
  }

  function label(key, family, x, y) {
    const evs = family?.events ?? []
    const marr = evs.find(e => e.event_type === 'MARR')
    const div  = evs.find(e => ['DIV', 'ANUL'].includes(e.event_type))
    if (!marr && !div) return null
    return {
      key, x, y,
      married:  marr ? (extractYear(marr.event_date) ?? '') : null,
      divorced: div  ? (extractYear(div.event_date)  ?? '') : null,
      title:    [marr && [t('family.eventTypes.MARR'), displayDate(marr.event_date), marr.event_place].filter(Boolean).join(' · '),
                 div  && [t('family.eventTypes.' + div.event_type), displayDate(div.event_date), div.event_place].filter(Boolean).join(' · ')]
                .filter(Boolean).join('\n'),
    }
  }

  // ── Teilbäume mit Konturen ──
  // contour: Map y → [linke Kante, rechte Kante]
  const empty = () => ({ nodes: [], lines: [], labels: [], contour: new Map() })
  function addNode(sub, node, width = B) {
    sub.nodes.push(node)
    const c = sub.contour.get(node.y)
    const l = node.x - B / 2, rr = node.x - B / 2 + width
    sub.contour.set(node.y, c ? [Math.min(c[0], l), Math.max(c[1], rr)] : [l, rr])
  }
  function shift(sub, dx) {
    for (const n of sub.nodes)  n.x += dx
    for (const l of sub.lines)  for (const p of l.pts) p[0] += dx
    for (const l of sub.labels) l.x += dx
    for (const c of sub.contour.values()) { c[0] += dx; c[1] += dx }
    return sub
  }
  function merge(a, b) {
    a.nodes.push(...b.nodes); a.lines.push(...b.lines); a.labels.push(...b.labels)
    for (const [y, c] of b.contour) {
      const o = a.contour.get(y)
      a.contour.set(y, o ? [Math.min(o[0], c[0]), Math.max(o[1], c[1])] : [...c])
    }
    return a
  }
  // Wie weit b nach rechts muss, damit es in keiner Reihe a überlappt
  function separation(a, b, gapAt) {
    let s = -Infinity
    for (const [y, c] of b.contour) {
      const o = a.contour.get(y)
      if (o) s = Math.max(s, o[1] - c[0] + gapAt(y))
    }
    return s
  }

  // ── Vorfahren über einer Person (ohne die Person selbst), Person bei x = 0 ──
  // Ehepaare stehen immer direkt nebeneinander; reicht der Platz für die Vorfahren
  // darüber nicht, weichen diese nach außen aus (die Linie zum Kind knickt dann).
  // Rückgabe: { sub, from } – from = Ausgangspunkt der (vom Aufrufer gezogenen) Linie zur Person
  function ancestors(id, g, path) {
    const sub = empty()
    if (g >= G) return { sub, from: null }
    const p      = parents.get(id)
    const father = get(p?.father)
    const mother = get(p?.mother)
    if (!father && !mother && g > 0) return { sub, from: null }
    const y = rowY(g + 1)

    // An der Hauptperson fehlende Eltern als „hinzufügen“-Karte
    const cards = [
      father ? { key: `a${path}f`, person: father, kind: 'ancestor' } : g === 0 ? { key: 'add-father', person: null, kind: 'add', role: 'father' } : null,
      mother ? { key: `a${path}m`, person: mother, kind: 'ancestor' } : g === 0 ? { key: 'add-mother', person: null, kind: 'add', role: 'mother' } : null,
    ]
    const above = [father, mother].map((pp, n) => pp ? ancestors(Number(pp.id), g + 1, path + 'fm'[n]) : null)

    const xs = cards[0] && cards[1] ? [-SLOT / 2, SLOT / 2] : [0, 0]
    const dx = [...xs]
    if (above[0] && above[1]) {
      const extra = Math.max(0, separation(above[0].sub, above[1].sub, () => SEP) - SLOT)
      dx[0] -= extra / 2; dx[1] += extra / 2
    }
    cards.forEach((c, n) => {
      if (!c) return
      addNode(sub, { ...c, x: xs[n], y })
      const a = above[n]
      if (!a) return
      merge(sub, shift(a.sub, dx[n]))
      if (a.from) sub.lines.push(elbow(a.from[0] + dx[n], a.from[1], xs[n], y, notBiological(parents.get(Number(cards[n].person.id))?.pedigree)))
    })

    let from = null
    if (father && mother) {
      sub.lines.push({ pts: [[xs[0] + B / 2, midY(y)], [xs[1] - B / 2, midY(y)]] })
      const lb = label(`l${path}`, p.family, 0, y)
      if (lb) sub.labels.push(lb)
      from = [0, midY(y)]
    } else if (father || mother) {
      from = [father ? xs[0] : xs[1], y + D]
    }
    return { sub, from }
  }

  // ── Nachkommen: Person mit Partnern und Kindern darunter, verankert an der Personenkarte ──
  const seen = new Set([rootId])
  function descendants(id, level, kind, key) {
    const person = get(id)
    const y   = rowY(-level)
    const sub = empty()
    const spouses = []
    const kids    = []
    if (level < descLevels) {
      for (const u of unions.get(id) ?? []) {
        const sp = get(u.partner)
        if (sp && !spouses.some(s => s.person === sp)) spouses.push({ person: sp, union: u })
        for (const k of u.children) {
          if (seen.has(k) || !get(k)) continue
          seen.add(k)
          kids.push({ id: k, union: u })
        }
      }
    }
    addNode(sub, { key, person, kind, x: 0, y }, B + spouses.length * SLOT)
    const spouseX = new Map()
    spouses.forEach((s, n) => {
      const x = (n + 1) * SLOT
      spouseX.set(s.person, x)
      sub.nodes.push({ key: `s${id}-${s.person.id}`, person: s.person, kind: 'spouse', x, y })
    })
    if (spouses.length) {
      sub.lines.push({ pts: [[B / 2, midY(y)], [SLOT - B / 2, midY(y)]] })
      const lb = label(`l${id}`, spouses[0].union.family, SLOT / 2, y)
      if (lb) sub.labels.push(lb)
    }
    if (!kids.length) return sub

    // Kinder nebeneinander, so dicht wie möglich, dann mittig unter das Paar
    const childY = rowY(-level - 1)
    const row = empty()
    const kidX = []
    let prevHasSpouse = false
    for (const k of kids) {
      const ks = descendants(k.id, level + 1, 'child', `d${k.id}`)
      const hasSpouse = ks.nodes.some(n => n.kind === 'spouse' && n.y === childY)
      if (row.nodes.length) {
        const gapOwn = prevHasSpouse ? SEP : GAP
        shift(ks, separation(row, ks, yy => (yy === childY ? gapOwn : SEP)))
      }
      kidX.push(ks.nodes[0].x)
      merge(row, ks)
      prevHasSpouse = hasSpouse
    }
    const anchor = spouses.length ? SLOT / 2 : 0
    const dx = anchor - (kidX[0] + kidX[kidX.length - 1]) / 2
    shift(row, dx)
    kids.forEach((k, n) => {
      const kx = kidX[n] + dx
      const dashed = notBiological(pedigreeIn(k.union.family, k.id))
      // Paarlinie nur zum ersten Partner – Kinder anderer Partnerschaften hängen am jeweiligen Partner
      const sp = spouses.find(s => Number(s.person.id) === Number(k.union.partner))
      if (sp && sp === spouses[0]) row.lines.push(elbow(SLOT / 2, midY(y), kx, childY, dashed))
      else if (sp)                 row.lines.push(elbow(spouseX.get(sp.person), y + D, kx, childY, dashed))
      else                         row.lines.push(elbow(0, y + D, kx, childY, dashed))
    })
    return merge(sub, row)
  }

  // Hauptperson bei x = 0: Vorfahren darüber, Partner und Nachkommen
  const anc = ancestors(rootId, 0, '')
  const all = anc.sub
  const rootParents = parents.get(rootId)
  const rootLink = anc.from ? { from: anc.from, family: rootParents.family } : null
  if (rootLink) all.lines.push(elbow(...anc.from, 0, 0, notBiological(rootParents.pedigree)))
  const sibFamily = rootLink?.family ?? null
  const sibIds = sibFamily ? sibFamily.members.filter(m => m.role === 'CHIL').map(m => m.person_id) : []
  for (const id of sibIds) seen.add(id)   // Geschwister nicht als Nachkommen
  const desc = descendants(rootId, 0, 'root', 'root')
  merge(all, desc)
  const nSpouses = desc.nodes.filter(n => n.kind === 'spouse' && n.y === 0).length

  // Geschwister: ältere links, jüngere rechts hinter den Partnern
  const at = sibIds.indexOf(rootId)
  if (rootLink && at >= 0) {
    const older   = sibIds.slice(0, at).filter(id => get(id)).reverse()
    const younger = sibIds.slice(at + 1).filter(id => get(id))
    const add = (id, x) => {
      all.nodes.push({ key: `b${id}`, person: get(id), kind: 'sibling', x, y: 0 })
      all.lines.push(elbow(...rootLink.from, x, 0, notBiological(pedigreeIn(sibFamily, id))))
    }
    older.forEach((id, n) => add(id, -(n + 1) * SLOT))
    const after = nSpouses * SLOT + (nSpouses ? COUPLE_GAP : 0)
    younger.forEach((id, n) => add(id, after + (n + 1) * SLOT))
  }

  const { nodes, lines, labels } = all
  const rx = 0, ry = 0

  // Auf positive Koordinaten verschieben, dann in Bildschirmkoordinaten abbilden
  const xs     = nodes.map(n => n.x)
  const minX   = Math.min(...xs) - B / 2
  const maxX   = Math.max(...xs) + B / 2
  const minY   = Math.min(...nodes.map(n => n.y))
  const bottom = Math.max(...nodes.map(n => n.y)) + D - minY
  // Punkt (Breite, Tiefe) → Bildschirm
  const pt = horizontal
    ? (x, y) => [PAD + bottom - (y - minY), PAD + x - minX]
    : (x, y) => [PAD + x - minX, PAD + y - minY]
  // linke obere Ecke einer Karte mit Mitte x (Breite) und Oberkante y (Tiefe)
  const corner = horizontal
    ? (x, y) => pt(x - B / 2, y + D)
    : (x, y) => pt(x - B / 2, y)

  const [rcx, rcy] = pt(rx, midY(ry))
  return {
    horizontal,
    width:  (horizontal ? bottom : maxX - minX) + 2 * PAD,
    height: (horizontal ? maxX - minX : bottom) + 2 * PAD,
    root:   { x: rcx, y: rcy },
    nodes:  nodes.map(n => { const [left, top] = corner(n.x, n.y); return { ...n, left, top } }),
    paths:  lines.map(l => ({ d: 'M' + l.pts.map(([x, y]) => pt(x, y).join(',')).join(' L'), dashed: !!l.dashed })),
    // senkrecht: unter dem Paar; waagrecht: zwischen den Partnern
    labels: labels.map(l => { const [left, top] = horizontal ? pt(l.x, midY(l.y)) : pt(l.x, l.y + D + 6); return { ...l, left, top } }),
    shown:  nodes.filter(n => n.person).length,
  }
})

// ── Zoom & Verschieben ───────────────────────────────────
const zoom     = ref(1)
const viewport = ref(null)

function setZoom(z) {
  const vp = viewport.value
  const old = zoom.value
  const nz  = Math.min(1.6, Math.max(0.3, Math.round(z * 100) / 100))
  if (!vp) { zoom.value = nz; return }
  // Mittelpunkt des Ausschnitts beibehalten
  const cx = (vp.scrollLeft + vp.clientWidth / 2) / old
  const cy = (vp.scrollTop + vp.clientHeight / 2) / old
  zoom.value = nz
  nextTick(() => {
    vp.scrollLeft = cx * nz - vp.clientWidth / 2
    vp.scrollTop  = cy * nz - vp.clientHeight / 2
  })
}

function fit() {
  const vp = viewport.value
  if (!vp || !layout.value) return
  const z = Math.min(vp.clientWidth / layout.value.width, vp.clientHeight / layout.value.height, 1)
  zoom.value = Math.max(0.3, Math.floor(z * 100) / 100)
  nextTick(centerRoot)
}

function centerRoot() {
  const vp = viewport.value
  if (!vp || !layout.value) return
  const { x, y } = layout.value.root
  vp.scrollLeft = x * zoom.value - vp.clientWidth / 2
  vp.scrollTop  = y * zoom.value - vp.clientHeight / 2
}

watch(() => [root.value?.id, generations.value, orientation.value, layout.value?.width], () => nextTick(centerRoot))

function onWheel(e) {
  hoverEnd()
  if (!e.ctrlKey) return
  e.preventDefault()
  setZoom(zoom.value * (e.deltaY < 0 ? 1.1 : 1 / 1.1))
}

let drag = null
function onPointerDown(e) {
  hoverEnd()
  if (e.button !== 0 || e.target.closest('.tree-node')) return
  drag = { x: e.clientX, y: e.clientY, left: viewport.value.scrollLeft, top: viewport.value.scrollTop }
  viewport.value.setPointerCapture(e.pointerId)
  viewport.value.classList.add('dragging')
}
function onPointerMove(e) {
  if (!drag) return
  viewport.value.scrollLeft = drag.left - (e.clientX - drag.x)
  viewport.value.scrollTop  = drag.top  - (e.clientY - drag.y)
}
function onPointerUp() {
  drag = null
  viewport.value?.classList.remove('dragging')
}

// ── Info-Karte beim Verweilen ────────────────────────────
const hover   = ref(null)          // { person, rect, details }
const details = new Map()          // Personen-ID → volle Person (bis zum nächsten Neuladen)
let hoverTimer = null
watch(() => props.refresh, () => details.clear())

function hoverStart(e, n) {
  if (!n.person || e.pointerType === 'touch' || drag) return
  clearTimeout(hoverTimer)
  const el = e.currentTarget
  hoverTimer = setTimeout(async () => {
    const id = Number(n.person.id)
    hover.value = { person: n.person, rect: el.getBoundingClientRect(), details: details.get(id) ?? null }
    if (details.has(id) || n.person.private) return
    try {
      const full = await fetchPerson(id)
      details.set(id, full)
      if (hover.value && Number(hover.value.person.id) === id) hover.value = { ...hover.value, details: full }
    } catch { /* nur die schlanken Daten zeigen */ }
  }, 350)
}
function hoverEnd() {
  clearTimeout(hoverTimer)
  hover.value = null
}

function onNodeClick(n) {
  if (n.person) emit('select-root', Number(n.person.id))
  else          emit('family', root.value)
}
</script>

<template>
  <div class="card tree-wrap">
    <div class="tree-toolbar">
      <div class="tree-toolbar-group">
        <span class="tree-label">{{ t('tree.root') }}</span>
        <PersonPicker class="tree-root-select" :model-value="root ? Number(root.id) : ''" :label="root ? nameLabel(root) : ''"
          :placeholder="t('person.search.placeholder')" @update:model-value="v => v && emit('select-root', v)" />
        <Button v-if="root" :icon="canEdit ? 'pi pi-pencil' : 'pi pi-eye'" text rounded :aria-label="canEdit ? t('action.edit') : t('action.show')"
          v-tooltip.bottom="canEdit ? t('action.edit') : t('action.show')" @click="emit('edit', root)" />
        <Button v-if="canEdit && (root)" icon="pi pi-users" text rounded :aria-label="t('person.list.relations')"
          v-tooltip.bottom="t('person.list.relations')" @click="emit('family', root)" />
        <!-- Startperson: hinspringen bzw. (Administratoren) die angezeigte Person dazu machen -->
        <Button v-if="startId && root && Number(root.id) !== startId" icon="pi pi-home" text rounded
          :aria-label="t('startPerson.goto')" v-tooltip.bottom="t('startPerson.goto')" @click="emit('select-root', startId)" />
        <Button v-if="isAdmin && root" :icon="Number(root.id) === startId ? 'pi pi-flag-fill' : 'pi pi-flag'" text rounded
          :class="{ 'is-start': Number(root.id) === startId }"
          :aria-label="Number(root.id) === startId ? t('startPerson.isStart') : t('startPerson.set')"
          v-tooltip.bottom="Number(root.id) === startId ? t('startPerson.isStart') : t('startPerson.set')"
          @click="Number(root.id) !== startId && emit('set-start', Number(root.id))" />
      </div>

      <div class="tree-toolbar-group">
        <span v-if="layout" class="list-count">{{ t('tree.count', { n: layout.shown, total: total.toLocaleString() }) }}</span>
        <label class="tree-label" for="tree-gen">{{ t('tree.generations') }}</label>
        <Select input-id="tree-gen" v-model="generations" :options="generationOptions"
          option-label="label" option-value="value" class="tree-gen-select" />
        <Button :icon="orientation === 'vertical' ? 'pi pi-arrow-right' : 'pi pi-arrow-up'" text rounded
          :aria-label="t(orientation === 'vertical' ? 'tree.horizontal' : 'tree.vertical')"
          v-tooltip.bottom="t(orientation === 'vertical' ? 'tree.horizontal' : 'tree.vertical')" @click="toggleOrientation" />
        <div class="tree-zoom">
          <Button icon="pi pi-minus" text rounded :aria-label="t('tree.zoomOut')" @click="setZoom(zoom / 1.2)" />
          <button class="tree-zoom-value" :title="t('tree.zoomReset')" @click="setZoom(1)">{{ Math.round(zoom * 100) }} %</button>
          <Button icon="pi pi-plus" text rounded :aria-label="t('tree.zoomIn')" @click="setZoom(zoom * 1.2)" />
          <Button icon="pi pi-expand" text rounded :aria-label="t('tree.fit')" v-tooltip.bottom="t('tree.fit')" @click="fit" />
        </div>
      </div>
    </div>

    <p v-if="loading && !layout" class="muted tree-msg">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error tree-msg">{{ error }}</div>
    <div v-else-if="!loading && !total" class="tree-empty">
      <i class="pi pi-sitemap" />
      <p>{{ t('tree.empty') }}</p>
      <Button v-if="canEdit" :label="t('person.titleNew')" icon="pi pi-plus" @click="emit('create')" />
    </div>

    <div
      v-else-if="layout"
      ref="viewport"
      class="tree-viewport"
      @scroll.passive="hoverEnd"
      @wheel="onWheel"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
    >
      <div class="tree-sizer" :style="{ width: layout.width * zoom + 'px', height: layout.height * zoom + 'px' }">
        <div class="tree-canvas" :style="{ width: layout.width + 'px', height: layout.height + 'px', transform: `scale(${zoom})` }">
          <svg class="tree-lines" :width="layout.width" :height="layout.height" aria-hidden="true">
            <path v-for="(l, i) in layout.paths" :key="i" :d="l.d" :class="{ dashed: l.dashed }" />
          </svg>

          <div
            v-for="l in layout.labels" :key="l.key"
            :class="['tree-marriage', { 'tree-marriage-h': layout.horizontal }]" :style="{ left: l.left + 'px', top: l.top + 'px' }" :title="l.title"
          >
            <template v-if="l.married !== null"><i class="pi pi-heart" />{{ l.married }}</template>
            <template v-if="l.divorced !== null">
              <span v-if="l.married !== null" class="tree-marriage-sep" />
              <i class="pi pi-ban" />{{ l.divorced }}
            </template>
          </div>

          <div
            v-for="n in layout.nodes" :key="n.key"
            :class="['tree-node', n.person ? sexClass(n.person) : 'tree-node-add', 'kind-' + n.kind]"
            :style="{ left: n.left + 'px', top: n.top + 'px', width: CARD_W + 'px', height: CARD_H + 'px' }"
            role="button" tabindex="0"
            @click="hoverEnd(); onNodeClick(n)"
            @keydown.enter="onNodeClick(n)"
            @pointerenter="hoverStart($event, n)"
            @pointerleave="hoverEnd"
          >
            <template v-if="n.person">
              <div :class="['avatar', 'avatar-sm', { 'avatar-photo': n.person.photo_id }]" aria-hidden="true">
                <img v-if="n.person.photo_id" :src="mediaThumbUrl(n.person.photo_id)" alt="" loading="lazy" />
                <svg v-else viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="12" r="6"/><path d="M4 32c0-7 5.4-11 12-11s12 4 12 11z"/></svg>
              </div>
              <div class="tree-node-info">
                <div class="tree-node-given">{{ nameParts(n.person).given || '–' }}<i v-if="n.person.restriction" class="pi pi-lock lock-icon" :title="n.person.restriction" /></div>
                <div class="tree-node-surname">{{ nameParts(n.person).surname }}</div>
                <div class="tree-node-years">{{ lifeSpan(n.person) }}</div>
              </div>
              <div class="tree-node-actions" @click.stop>
                <button class="icon-btn" :title="canEdit ? t('action.edit') : t('action.show')" :aria-label="canEdit ? t('action.edit') : t('action.show')" @click="emit('edit', n.person)"><i :class="['pi', canEdit ? 'pi-pencil' : 'pi-eye']" /></button>
                <button v-if="canEdit" class="icon-btn" :title="t('person.list.relations')" :aria-label="t('person.list.relations')" @click="emit('family', n.person)"><i class="pi pi-users" /></button>
              </div>
            </template>
            <template v-else>
              <i class="pi pi-plus" />
              <span>{{ t(n.role === 'father' ? 'tree.addFather' : 'tree.addMother') }}</span>
            </template>
          </div>
        </div>
      </div>
    </div>
    <TreeHoverCard v-if="hover" :person="hover.person" :details="hover.details" :rect="hover.rect" />
  </div>
</template>
