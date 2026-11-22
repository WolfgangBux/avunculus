<script setup>
import { ref, computed, watch } from 'vue'
import SelectButton from 'openvue/selectbutton'
import ToggleSwitch from 'openvue/toggleswitch'
import Slider from 'openvue/slider'
import Button from 'openvue/button'
import { fetchSurnameCloud } from '../api/surnames.js'
import { currentTreeId } from '../trees.js'
import { t } from '../i18n/index.js'
import { useNotify } from '../composables/feedback.js'
import { FONT, SCHEMES, ORDERS, ASPECT, layoutCloud, wordColor, frame, cloudSvg, cloudPng, saveBlob } from '../surnameCloud.js'

/**
 * Große Namenswolke für die Auswertung: Anzahl, Farben und Titel wählbar, als PNG oder SVG zu speichern.
 * Ein Klick auf einen Namen öffnet die Personen mit diesem Namen (open-surname).
 */
const props = defineProps({ treeName: { type: String, default: '' } })
const emit  = defineEmits(['open-surname'])
const notify = useNotify()

const COUNTS = [50, 100, 200, 300]
const count     = ref(100)
const scheme    = ref('orange')
const showTitle = ref(true)
const order     = ref('cloud')
const aspect    = ref(Math.round(ASPECT.default * 10) / 10)   // Breite : Höhe

const words   = ref([])
const loading = ref(false)
const error   = ref(false)
let seq = 0

async function load() {
  if (!currentTreeId.value) return
  const my = ++seq
  loading.value = true
  try {
    const res = await fetchSurnameCloud(count.value)
    if (my === seq) { words.value = res.cloud; error.value = false }
  } catch {
    if (my === seq) error.value = true
  } finally {
    if (my === seq) loading.value = false
  }
}
watch([count, currentTreeId], load, { immediate: true })

const layout = computed(() => layoutCloud(words.value, order.value, aspect.value))
const aspectLabel = computed(() => aspect.value.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' : 1')
const title  = computed(() => (showTitle.value ? props.treeName : '') || '')
const view   = computed(() => frame(layout.value, title.value))
const color  = (it, i) => wordColor(it, i, scheme.value)

const countOptions  = COUNTS.map(n => ({ value: n, label: String(n) }))
const orderOptions  = computed(() => ORDERS.map(o => ({ value: o, label: t('stats.cloud.order.' + o) })))
const schemeOptions = computed(() => SCHEMES.map(s => ({ value: s, label: t('stats.cloud.scheme.' + s) })))

// Dateiname aus dem Baumnamen, ohne Zeichen, die Dateisysteme nicht mögen
const fileBase = computed(() => [t('stats.cloud.file'), props.treeName].filter(Boolean).join(' ')
  .replace(/[\\/:*?"<>|]+/g, '').trim().replace(/\s+/g, '-'))

async function savePng() {
  try   { saveBlob(await cloudPng(layout.value, scheme.value, title.value), fileBase.value + '.png') }
  catch { notify.error(t('stats.cloud.saveError')) }
}
function saveSvg() {
  saveBlob(new Blob([cloudSvg(layout.value, scheme.value, title.value)], { type: 'image/svg+xml' }), fileBase.value + '.svg')
}
</script>

<template>
<div class="surname-cloud-card">
  <div class="cloud-controls">
    <SelectButton v-model="count" :options="countOptions" option-label="label" option-value="value" :allow-empty="false"
      size="small" :aria-label="t('stats.cloud.count')" />
    <SelectButton v-model="order" :options="orderOptions" option-label="label" option-value="value" :allow-empty="false"
      size="small" :aria-label="t('stats.cloud.arrange')" />
    <SelectButton v-model="scheme" :options="schemeOptions" option-label="label" option-value="value" :allow-empty="false"
      size="small" :aria-label="t('stats.cloud.colors')" />
    <span class="cloud-width">
      <span class="cloud-width-label">{{ t('stats.cloud.width') }}</span>
      <Slider v-model="aspect" :min="ASPECT.min" :max="ASPECT.max" :step="0.1" class="cloud-slider" :aria-label="t('stats.cloud.width')" />
      <span class="cloud-width-value">{{ aspectLabel }}</span>
    </span>
    <label class="cloud-toggle"><ToggleSwitch v-model="showTitle" /> {{ t('stats.cloud.showTitle') }}</label>
    <span class="cloud-save">
      <Button :label="t('stats.cloud.png')" icon="pi pi-image" size="small" outlined :disabled="!layout.items.length" @click="savePng" />
      <Button :label="t('stats.cloud.svg')" icon="pi pi-download" size="small" outlined :disabled="!layout.items.length" @click="saveSvg" />
    </span>
  </div>

  <div v-if="error" class="alert alert-error">{{ t('surnames.loadError') }}</div>
  <p v-else-if="!words.length" class="muted">{{ loading ? t('common.loading') : t('stats.cloud.empty') }}</p>
  <svg v-else :class="['cloud-svg', { 'cloud-loading': loading }]" :viewBox="`${view.x} ${view.y} ${view.w} ${view.h}`"
    :font-family="FONT" role="img" :aria-label="t('stats.cloud.title')">
    <text v-if="title" :x="view.titleX" :y="view.titleY" font-size="30" font-weight="700" fill="#1f2630" text-anchor="middle">{{ title }}</text>
    <text v-for="(it, i) in layout.items" :key="it.text" class="cloud-word" :x="it.x" :y="it.y"
      :font-size="it.size" :font-weight="it.fontWeight" :fill="color(it, i)"
      tabindex="0" role="link" @click="emit('open-surname', it.text)" @keydown.enter="emit('open-surname', it.text)">
      <title>{{ it.text }}: {{ t(it.count === 1 ? 'person.list.totalOne' : 'person.list.total', { n: it.count.toLocaleString() }) }}</title>{{ it.text }}
    </text>
  </svg>
</div>
</template>
