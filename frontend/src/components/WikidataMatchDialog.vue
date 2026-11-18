<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import ProgressBar from 'openvue/progressbar'
import { fetchPlaces, applyWikidataMatches } from '../api/places.js'
import { matchPlaces, WEAK } from '../wikidataMatch.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

/**
 * Alle Orte ohne Wikidata-Kennung automatisch abgleichen (siehe wikidataMatch.js),
 * Ergebnis prüfen und übernehmen. Gespeichert werden nur Kennungen und leere Felder.
 */
const props = defineProps({ visible: Boolean })
const emit  = defineEmits(['update:visible', 'applied'])
const notify = useNotify()

const phase    = ref('start')     // start | running | review
const places   = ref([])
const progress = ref(null)
const error    = ref('')
const saving   = ref(false)
const fillData = ref(true)
const showNone = ref(false)
let   abort    = null

const sure      = ref([])   // [{ place, cand, checked }]
const ambiguous = ref([])   // [{ place, candidates, choice }]
const none      = ref([])   // [place]

const byId = computed(() => new Map(places.value.map(p => [p.id, p])))
const path = place => {
  const parts = []
  for (let p = place; p; p = byId.value.get(p.parent_id)) parts.push(p.name)
  return parts.join(', ')
}
const linkedCount   = computed(() => places.value.filter(p => p.wikidata_id).length)
const unlinkedCount = computed(() => places.value.length - linkedCount.value)

watch(() => props.visible, async v => {
  if (!v) { abort?.abort(); return }
  if (phase.value === 'running') return
  phase.value = 'start'
  error.value = ''
  try { places.value = await fetchPlaces() } catch { error.value = t('place.loadError') }
})

async function start() {
  phase.value    = 'running'
  error.value    = ''
  progress.value = { checked: 0, total: unlinkedCount.value, sure: 0, ambiguous: 0, none: 0, level: 0 }
  abort = new AbortController()
  const results = new Map()   // bleibt bei Abbruch/Fehler mit den bisherigen Ergebnissen gefüllt
  try {
    await matchPlaces(places.value, { signal: abort.signal, results, onProgress: s => { progress.value = s } })
  } catch (e) {
    if (e.name !== 'AbortError') error.value = e.message || t('place.wikidata.error')
  }
  showResults(results)
}

function stop() { abort?.abort() }

function showResults(results) {
  const list = [...results.entries()].map(([id, r]) => ({ place: byId.value.get(id), ...r })).filter(r => r.place)
  const order = (a, b) => path(a.place).localeCompare(path(b.place), 'de')
  sure.value      = list.filter(r => r.status === 'sure').sort(order).map(r => ({ place: r.place, cand: r.candidates[0], checked: true }))
  ambiguous.value = list.filter(r => r.status === 'ambiguous').sort(order).map(r => ({ place: r.place, candidates: r.candidates, choice: null }))
  none.value      = list.filter(r => r.status === 'none').sort(order).map(r => r.place)
  phase.value     = 'review'
}

const chosen = computed(() => [
  ...sure.value.filter(r => r.checked).map(r => ({ place: r.place, cand: r.cand })),
  ...ambiguous.value.filter(r => r.choice).map(r => ({ place: r.place, cand: r.candidates.find(c => c.qid === r.choice) })),
])
const allChecked = computed({
  get: () => sure.value.length > 0 && sure.value.every(r => r.checked),
  set: v => sure.value.forEach(r => { r.checked = v }),
})

// „Stadt in Deutschland“ → „Stadt“ (wie bei der Einzelübernahme)
const shortType = type => type?.replace(/\s+(in|im|von|der|des)\s.*$/i, '').slice(0, 60) ?? null

async function apply() {
  if (!chosen.value.length) return
  saving.value = true
  try {
    const items = chosen.value.map(({ place, cand }) => ({
      id: place.id, wikidata_id: cand.qid,
      ...(fillData.value ? { latitude: cand.lat, longitude: cand.lon, gov_id: cand.gov, place_type: shortType(cand.type) } : {}),
    }))
    let updated = 0
    for (let i = 0; i < items.length; i += 500) updated += (await applyWikidataMatches(items.slice(i, i + 500))).updated
    notify.success(t('place.match.applied', { n: updated.toLocaleString() }))
    emit('applied')
    // für den nächsten Durchgang (Unterorte der jetzt verknüpften Orte) neu laden
    places.value = await fetchPlaces()
    phase.value  = 'start'
  } catch {
    notify.error(t('action.saveError'))
  } finally {
    saving.value = false
  }
}

const pct = computed(() => progress.value?.total ? Math.round(100 * progress.value.checked / progress.value.total) : 0)
const fmt = n => (n ?? 0).toLocaleString()
const wdUrl = q => `https://www.wikidata.org/wiki/${q}`
</script>

<template>
  <Dialog :visible="visible" modal :header="t('place.match.title')" :style="{ width: '52rem' }"
    :breakpoints="{ '900px': '95vw' }" :closable="phase !== 'running'" @update:visible="v => emit('update:visible', v)">

    <div v-if="error" class="alert alert-error">{{ error }}</div>

    <!-- Start -->
    <template v-if="phase === 'start'">
      <p>{{ t('place.match.intro') }}</p>
      <ul class="wm-facts">
        <li>{{ t('place.match.linked', { n: fmt(linkedCount), total: fmt(places.length) }) }}</li>
        <li>{{ t('place.match.unlinked', { n: fmt(unlinkedCount) }) }}</li>
      </ul>
      <p class="field-hint">{{ t('place.match.how') }}</p>
      <div class="wm-actions">
        <Button :label="t('place.match.start')" icon="pi pi-play" :disabled="!unlinkedCount" @click="start" />
      </div>
    </template>

    <!-- Läuft -->
    <template v-else-if="phase === 'running'">
      <ProgressBar :value="pct" />
      <p class="wm-progress">
        {{ t('place.match.progress', { checked: fmt(progress.checked), total: fmt(progress.total), level: progress.level }) }}<br>
        <span class="wm-sure">{{ t('place.match.sure', { n: fmt(progress.sure) }) }}</span> ·
        <span class="wm-amb">{{ t('place.match.ambiguous', { n: fmt(progress.ambiguous) }) }}</span> ·
        <span class="muted">{{ t('place.match.none', { n: fmt(progress.none) }) }}</span>
      </p>
      <div class="wm-actions">
        <Button :label="t('place.match.stop')" severity="secondary" outlined icon="pi pi-stop" @click="stop" />
      </div>
    </template>

    <!-- Ergebnis prüfen -->
    <template v-else>
      <p v-if="progress?.skipped" class="field-hint">{{ t('place.match.skipped', { n: fmt(progress.skipped) }) }}</p>

      <section class="wm-section">
        <h3 class="wm-head">
          <label><input v-model="allChecked" type="checkbox"> {{ t('place.match.sureTitle', { n: fmt(sure.length) }) }}</label>
        </h3>
        <ul class="wm-list">
          <li v-for="r in sure" :key="r.place.id" class="wm-row">
            <label class="wm-check">
              <input v-model="r.checked" type="checkbox">
              <span class="wm-place">{{ path(r.place) }}</span>
            </label>
            <span class="wm-cand">
              <i v-if="r.cand.score < WEAK" class="pi pi-exclamation-circle wm-weak" v-tooltip.top="t('place.match.weak')" />
              <a :href="wdUrl(r.cand.qid)" target="_blank" rel="noopener">{{ r.cand.label }}</a>
              <span class="wm-desc">{{ r.cand.desc }}</span>
            </span>
          </li>
        </ul>
      </section>

      <section v-if="ambiguous.length" class="wm-section">
        <h3 class="wm-head">{{ t('place.match.ambiguousTitle', { n: fmt(ambiguous.length) }) }}</h3>
        <p class="field-hint">{{ t('place.match.ambiguousHint') }}</p>
        <ul class="wm-list">
          <li v-for="r in ambiguous" :key="r.place.id" class="wm-amb-row">
            <div class="wm-place">{{ path(r.place) }}</div>
            <label v-for="c in r.candidates" :key="c.qid" class="wm-choice">
              <input v-model="r.choice" type="radio" :name="'amb' + r.place.id" :value="c.qid">
              <a :href="wdUrl(c.qid)" target="_blank" rel="noopener">{{ c.label }}</a>
              <span class="wm-desc">{{ c.desc || c.type }}</span>
            </label>
            <label class="wm-choice">
              <input v-model="r.choice" type="radio" :name="'amb' + r.place.id" :value="null">
              <span class="muted">{{ t('place.match.noneOfThem') }}</span>
            </label>
          </li>
        </ul>
      </section>

      <section v-if="none.length" class="wm-section">
        <h3 class="wm-head">
          <button type="button" class="wm-toggle" @click="showNone = !showNone">
            <i :class="['pi', showNone ? 'pi-angle-down' : 'pi-angle-right']" />
            {{ t('place.match.noneTitle', { n: fmt(none.length) }) }}
          </button>
        </h3>
        <ul v-if="showNone" class="wm-list wm-none">
          <li v-for="p in none" :key="p.id">{{ path(p) }}</li>
        </ul>
      </section>

      <label class="wm-fill"><input v-model="fillData" type="checkbox"> {{ t('place.match.fill') }}</label>
      <div class="wm-actions">
        <Button :label="t('action.cancel')" severity="secondary" outlined @click="emit('update:visible', false)" />
        <Button :label="t('place.match.apply', { n: fmt(chosen.length) })" icon="pi pi-check" :loading="saving"
          :disabled="!chosen.length" @click="apply" />
      </div>
    </template>
  </Dialog>
</template>

<style scoped>
.wm-facts { margin: .5rem 0 .75rem 1.2rem; }
.wm-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
.wm-progress { margin-top: .75rem; font-size: .9rem; }
.wm-sure { color: var(--color-success); }
.wm-amb  { color: var(--color-primary-dark); }
.wm-section { margin-top: 1rem; }
.wm-head { font-size: .85rem; font-weight: 600; margin-bottom: .35rem; }
.wm-head label { display: inline-flex; gap: .4rem; align-items: center; cursor: pointer; }
.wm-list { list-style: none; max-height: 18rem; overflow-y: auto; border: 1px solid var(--color-border); border-radius: 6px; }
.wm-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .75rem; padding: .3rem .6rem; font-size: .82rem; }
.wm-row + .wm-row, .wm-amb-row + .wm-amb-row { border-top: 1px solid var(--color-border); }
.wm-check { display: flex; gap: .4rem; align-items: baseline; cursor: pointer; min-width: 0; }
.wm-place { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wm-cand { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wm-cand a, .wm-choice a { color: var(--color-primary); font-weight: 500; }
.wm-desc { color: var(--color-muted); margin-left: .4rem; }
.wm-amb-row { padding: .4rem .6rem; font-size: .82rem; }
.wm-amb-row .wm-place { font-weight: 600; margin-bottom: .2rem; }
.wm-choice { display: flex; gap: .4rem; align-items: baseline; padding-left: .5rem; cursor: pointer; }
.wm-none li { padding: .15rem .6rem; font-size: .8rem; color: var(--color-muted); }
.wm-toggle { background: none; border: none; font: inherit; cursor: pointer; padding: 0; display: inline-flex; gap: .3rem; align-items: center; }
.wm-weak { color: var(--color-primary); font-size: .8rem; margin-right: .25rem; }
.wm-fill { display: flex; gap: .4rem; align-items: center; margin-top: 1rem; font-size: .85rem; cursor: pointer; }
</style>
