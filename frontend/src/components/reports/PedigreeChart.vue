<script setup>
import { computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { fetchAncestorReport } from '../../api/reports.js'
import { useReportData, nameLabel, nameWithSpan, factLines } from './reportFormat.js'
import SymbolLegend from './SymbolLegend.vue'
import { t } from '../../i18n/index.js'

/**
 * Grafische Ahnentafel (Querformat): links die Ausgangsperson, nach rechts je Generation eine Spalte,
 * Kästchen mit Kekule-Nummer. Unbekannte Eltern bekannter Personen bleiben als leere Kästchen zum
 * Ergänzen stehen. Ahnenschwund: weitere Nennungen verweisen auf die erste Nummer.
 */
const props = defineProps({ params: { type: Object, required: true } })
const emit  = defineEmits(['title'])

const gens = Math.max(2, Math.min(6, Number(props.params.gen) || 5))
const { data, error } = useReportData(() => fetchAncestorReport(props.params.person, gens))
const person = id => data.value?.persons?.[id]

watch(data, d => d && emit('title', t('reports.titles.pedigree', { name: nameLabel(person(d.root)) })))

// Querformat nur für diese Seite
let pageStyle
onMounted(() => {
  document.body.classList.add('report-landscape')
  pageStyle = document.createElement('style')
  pageStyle.textContent = '@page { size: A4 landscape; margin: 10mm 12mm; }'
  document.head.appendChild(pageStyle)
})
onBeforeUnmount(() => { document.body.classList.remove('report-landscape'); pageStyle?.remove() })

const HEIGHT = 135                                // Höhe der Fläche in mm (passt mit Kopf und Legende auf A4 quer)
const FONT   = { 2: 8.5, 3: 8.5, 4: 8.5, 5: 7.5, 6: 6.5 }[gens]   // Schriftgröße in pt
const LINE   = FONT * 0.3528 * (gens === 6 ? 1.15 : 1.25)          // Zeilenhöhe in mm
const PAD    = gens === 6 ? 0.8 : 1.4                              // Innenabstand + Rahmen in mm
const GAP = 2.5                                   // Abstand der Spalten in %
const colW = (100 - (gens - 1) * GAP) / gens
const genOf = n => Math.floor(Math.log2(n)) + 1
const left  = n => (genOf(n) - 1) * (colW + GAP)
const slotH = n => 100 / 2 ** (genOf(n) - 1)
const top   = n => (n - 2 ** (genOf(n) - 1) + 0.5) * slotH(n)

/** Kästchen: bekannte Ahnen und leere Plätze für die Eltern bekannter Personen */
const boxes = computed(() => {
  const byN = new Map((data.value?.entries ?? []).map(e => [e.n, e]))
  const out = []
  const visit = n => {
    const e = byN.get(n)
    out.push({ n, entry: e ?? null })
    if (e && !e.same_as && genOf(n) < gens) { visit(2 * n); visit(2 * n + 1) }
  }
  if (data.value) visit(1)
  return out
})

/** Verbindungslinien Kind → Eltern (im Koordinatensystem 0–100 der Fläche) */
const lines = computed(() => {
  const shown = new Set(boxes.value.map(b => b.n))
  const out = []
  for (const b of boxes.value) {
    const f = 2 * b.n
    if (!shown.has(f)) continue
    const x1 = left(b.n) + colW, x2 = left(f), xm = (x1 + x2) / 2
    const yf = top(f), ym = top(f + 1)
    out.push(`M${x1},${top(b.n)} H${xm} M${xm},${yf} V${ym} M${xm},${yf} H${x2} M${xm},${ym} H${x2}`)
  }
  return out
})

/** Ganze Zeilen, die in ein Kästchen dieser Generation passen (mit Name) */
const fits = n => Math.max(1, Math.floor((HEIGHT * slotH(n) / 100 * 0.92 - PAD) / LINE))

function factsOf(b) {
  const p = person(b.entry.person_id)
  // Heirat der Eltern steht beim Vater (gerade Nummer)
  return factLines(p, { marriage: b.n % 2 === 0 ? data.value.marriages[b.n] : null, shortPlaces: true }).slice(0, fits(b.n) - 1)
}
const boxStyle = b => ({
  left: left(b.n) + '%', width: colW + '%', top: top(b.n) + '%', maxHeight: slotH(b.n) * 0.92 + '%',
})
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <template v-else>
    <div :class="['pedigree', 'pedigree-g' + gens]" :style="{ height: HEIGHT + 'mm', fontSize: FONT + 'pt' }">
      <svg class="pedigree-lines" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
        <path v-for="(d, i) in lines" :key="i" :d="d" vector-effect="non-scaling-stroke" />
      </svg>
      <div v-for="b in boxes" :key="b.n" :class="['pedigree-box', { 'pedigree-empty': !b.entry }]" :style="boxStyle(b)">
        <span class="pedigree-num">{{ b.n }}</span>
        <template v-if="b.entry">
          <!-- nur eine Zeile Platz: Name mit Lebensjahren -->
          <div v-if="fits(b.n) === 1" class="pedigree-name">
            {{ nameWithSpan(person(b.entry.person_id)) }}<span v-if="b.entry.same_as" class="muted"> = {{ b.entry.same_as }}</span>
          </div>
          <div v-else class="pedigree-name">{{ nameLabel(person(b.entry.person_id)) }}</div>
          <template v-if="fits(b.n) === 1" />
          <div v-else-if="b.entry.same_as" class="pedigree-line muted">{{ t('reports.seeNumber', { n: b.entry.same_as }) }}</div>
          <div v-else-if="person(b.entry.person_id)?.private" class="pedigree-line muted">{{ t('reports.privateHint') }}</div>
          <template v-else>
            <div v-for="l in factsOf(b)" :key="l.key" class="pedigree-line" :title="l.label">
              {{ l.symbol || l.label + ':' }} {{ l.text }}
            </div>
          </template>
        </template>
      </div>
    </div>
    <SymbolLegend />
  </template>
</template>
