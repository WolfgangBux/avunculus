<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { locale, t } from '../i18n/index.js'

/**
 * Säulendiagramm für eine Datenreihe (z. B. Geburten je Jahrzehnt) als SVG.
 * Dünne Säulen mit 2 px Abstand, zurückhaltende Hilfslinien, Hover-Tooltip je Säule,
 * Beschriftung der x-Achse nur alle labelEvery Einheiten; darunter eine Tabellenansicht.
 * data: [{ x, y, label }]
 */
const props = defineProps({
  data:       { type: Array, default: () => [] },
  labelEvery: { type: Number, default: 50 },
  valueLabel: { type: String, default: '' },
})

// Breite = tatsächliche Breite des Diagramms (Schrift bleibt so in Originalgröße)
const box   = ref(null)
const W     = ref(760)
const H = 230, PAD_L = 44, PAD_R = 8, PAD_T = 10, PAD_B = 24
const plotW = computed(() => W.value - PAD_L - PAD_R)
const plotH = H - PAD_T - PAD_B
let ro = null
onMounted(() => {
  ro = new ResizeObserver(([e]) => { W.value = Math.max(320, Math.round(e.contentRect.width)) })
  ro.observe(box.value)
})
onBeforeUnmount(() => ro?.disconnect())

/** „schöne“ Obergrenze und Teilstriche */
const scale = computed(() => {
  const max  = Math.max(1, ...props.data.map(d => d.y))
  const raw  = max / 4
  const mag  = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map(f => f * mag).find(s => s >= raw)
  const top  = Math.ceil(max / step) * step
  const ticks = []
  for (let v = 0; v <= top + 1e-9; v += step) ticks.push(v)
  return { top, ticks }
})

const slot = computed(() => plotW.value / Math.max(1, props.data.length))
const bars = computed(() => props.data.map((d, i) => {
  const h = plotH * d.y / scale.value.top
  return { ...d, i, x: PAD_L + i * slot.value + 1, w: Math.max(1, slot.value - 2), y0: PAD_T + plotH - h, h }
}))
const yOf = v => PAD_T + plotH - plotH * v / scale.value.top
const fmt = n => n.toLocaleString(locale.value)

const hover = ref(null)
const tipStyle = computed(() => {
  const b = hover.value
  if (!b) return {}
  const left = 100 * (b.x + b.w / 2) / W.value
  return { left: `clamp(4rem, ${left}%, calc(100% - 4rem))`, top: `${100 * Math.max(b.y0, PAD_T + 10) / H}%` }
})
</script>

<template>
  <div ref="box" class="column-chart">
    <svg :viewBox="`0 0 ${W} ${H}`" :width="W" :height="H" role="img" :aria-label="valueLabel" @mouseleave="hover = null">
      <g class="cc-grid">
        <g v-for="v in scale.ticks" :key="v">
          <line :x1="PAD_L" :x2="W - PAD_R" :y1="yOf(v)" :y2="yOf(v)" />
          <text :x="PAD_L - 6" :y="yOf(v) + 3.5" text-anchor="end">{{ fmt(v) }}</text>
        </g>
      </g>
      <g class="cc-bars">
        <rect v-for="b in bars" :key="b.x" :x="b.x" :y="b.y0" :width="b.w" :height="b.h" rx="2"
          :class="{ active: hover?.i === b.i }" />
      </g>
      <g class="cc-axis">
        <text v-for="b in bars.filter(b => props.data[b.i].x % labelEvery === 0)" :key="'l' + b.i"
          :x="b.x" :y="H - 6" text-anchor="start">{{ props.data[b.i].x }}</text>
      </g>
      <!-- Trefferflächen: volle Höhe je Säule, größer als die Säule selbst -->
      <rect v-for="b in bars" :key="'h' + b.i" class="cc-hit" :x="PAD_L + b.i * slot" :y="PAD_T" :width="slot" :height="plotH"
        @mouseenter="hover = b" />
    </svg>
    <div v-if="hover" class="cc-tip" :style="tipStyle">
      <strong>{{ hover.label }}</strong><br>{{ fmt(hover.y) }} {{ valueLabel }}
    </div>
    <details class="cc-table">
      <summary>{{ t('stats.asTable') }}</summary>
      <div class="stats-table-wrap">
        <table class="stats-table">
          <tbody>
            <tr v-for="d in data" :key="d.x"><td>{{ d.label }}</td><td class="num">{{ fmt(d.y) }}</td></tr>
          </tbody>
        </table>
      </div>
    </details>
  </div>
</template>
