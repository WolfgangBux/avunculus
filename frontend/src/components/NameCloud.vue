<script setup>
import { ref, computed, watch } from 'vue'
import SelectButton from 'openvue/selectbutton'
import ToggleSwitch from 'openvue/toggleswitch'
import Slider from 'openvue/slider'
import Button from 'openvue/button'
import { fetchSurnameCloud } from '../api/surnames.js'
import { fetchStatisticsCloud } from '../api/statistics.js'
import { currentTreeId } from '../trees.js'
import { t } from '../i18n/index.js'
import { useNotify } from '../composables/feedback.js'
import { FONT, SCHEMES, ORDERS, ASPECT, layoutCloud, wordColor, frame, cloudSvg, cloudPng, saveBlob } from '../wordCloud.js'

/**
 * Große Wolke für die Auswertung – Nachnamen, Vornamen oder Orte: Anzahl, Anordnung, Farben, Breite und
 * Titel wählbar, als PNG oder SVG zu speichern. Ein Klick öffnet die Personen mit diesem Nachnamen
 * (open-surname), sucht nach dem Vornamen (open-given) oder öffnet den Ort (open-place).
 */
const props = defineProps({ treeName: { type: String, default: '' } })
const emit  = defineEmits(['open-surname', 'open-given', 'open-place'])
const notify = useNotify()

const KINDS = ['surname', 'given', 'place']
const kind = ref('surname')
const sex  = ref('')            // nur Vornamen: '' alle, 'M', 'F'

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
    const res = kind.value === 'surname'
      ? { cloud: (await fetchSurnameCloud(count.value)).cloud.map(s => ({ label: s.surname, count: s.count })) }
      : await fetchStatisticsCloud(kind.value, count.value, kind.value === 'given' ? sex.value : null)
    if (my === seq) { words.value = res.cloud; error.value = false }
  } catch {
    if (my === seq) error.value = true
  } finally {
    if (my === seq) loading.value = false
  }
}
watch([kind, sex, count, currentTreeId], load, { immediate: true })

const layout = computed(() => layoutCloud(words.value, order.value, aspect.value))
const aspectLabel = computed(() => aspect.value.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' : 1')
const kindLabel = computed(() => t('stats.cloud.kind.' + kind.value) + (kind.value === 'given' && sex.value ? ' (' + t('sex.' + sex.value) + ')' : ''))
const title  = computed(() => showTitle.value ? [props.treeName, kindLabel.value].filter(Boolean).join(' – ') : '')
const view   = computed(() => frame(layout.value, title.value))
const color  = (it, i) => wordColor(it, i, scheme.value)

const kindOptions   = computed(() => KINDS.map(k => ({ value: k, label: t('stats.cloud.kind.' + k) })))
const sexOptions    = computed(() => [{ value: '', label: t('stats.cloud.allSexes') }, { value: 'M', label: t('sex.M') }, { value: 'F', label: t('sex.F') }])
const countOptions  = COUNTS.map(n => ({ value: n, label: String(n) }))
const orderOptions  = computed(() => ORDERS.map(o => ({ value: o, label: t('stats.cloud.order.' + o) })))
const schemeOptions = computed(() => SCHEMES.map(s => ({ value: s, label: t('stats.cloud.scheme.' + s) })))

// Dateiname aus dem Baumnamen, ohne Zeichen, die Dateisysteme nicht mögen
const fileBase = computed(() => [t('stats.cloud.file.' + kind.value), props.treeName].filter(Boolean).join(' ')
  .replace(/[\\/:*?"<>|]+/g, '').trim().replace(/\s+/g, '-'))

function open(it) {
  if (kind.value === 'surname')    emit('open-surname', it.text)
  else if (kind.value === 'given') emit('open-given', it.text)
  else if (it.id)                  emit('open-place', it.id)
}
const tip = it => (it.title || it.text) + ': ' + t(it.count === 1 ? 'person.list.totalOne' : 'person.list.total', { n: it.count.toLocaleString() })

async function savePng() {
  try   { saveBlob(await cloudPng(layout.value, scheme.value, title.value), fileBase.value + '.png') }
  catch { notify.error(t('stats.cloud.saveError')) }
}
function saveSvg() {
  saveBlob(new Blob([cloudSvg(layout.value, scheme.value, title.value)], { type: 'image/svg+xml' }), fileBase.value + '.svg')
}
</script>

<template>
<div class="name-cloud">
  <div class="cloud-controls">
    <SelectButton v-model="kind" :options="kindOptions" option-label="label" option-value="value" :allow-empty="false"
      :aria-label="t('stats.cloud.kindLabel')" />
    <SelectButton v-if="kind === 'given'" v-model="sex" :options="sexOptions" option-label="label" option-value="value"
      :allow-empty="false" size="small" :aria-label="t('stats.cloud.sex')" />
  </div>
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

  <div v-if="error" class="alert alert-error">{{ t('stats.cloud.loadError') }}</div>
  <p v-else-if="!words.length" class="muted">{{ loading ? t('common.loading') : t('stats.cloud.empty') }}</p>
  <svg v-else :class="['cloud-svg', { 'cloud-loading': loading }]" :viewBox="`${view.x} ${view.y} ${view.w} ${view.h}`"
    :font-family="FONT" role="img" :aria-label="title || kindLabel">
    <text v-if="title" :x="view.titleX" :y="view.titleY" font-size="30" font-weight="700" fill="#1f2630" text-anchor="middle">{{ title }}</text>
    <text v-for="(it, i) in layout.items" :key="it.id ?? it.text" class="cloud-word" :x="it.x" :y="it.y"
      :font-size="it.size" :font-weight="it.fontWeight" :fill="color(it, i)"
      tabindex="0" role="link" @click="open(it)" @keydown.enter="open(it)">
      <title>{{ tip(it) }}</title>{{ it.text }}
    </text>
  </svg>
</div>
</template>
