<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import Dialog from 'openvue/dialog'
import InputText from 'openvue/inputtext'
import Button from 'openvue/button'
import { searchWikidata } from '../api/places.js'
import { locale, t } from '../i18n/index.js'

/**
 * Wikidata-Objekt zu einem Ort suchen und wählen.
 * Treffer, deren Beschreibung einen übergeordneten Ort nennt („Ortsteil von Waldkirchen“), stehen oben –
 * je näher der genannte Oberort, desto weiter oben; die besten werden markiert.
 */
const props = defineProps({
  visible: Boolean,
  query:   { type: String, default: '' },          // Startsuche, i. d. R. der Ortsname
  context: { type: Array,  default: () => [] },    // Namen der übergeordneten Orte
})
const emit = defineEmits(['update:visible', 'select'])

const q        = ref('')
const results  = ref([])
const loading  = ref(false)
const error    = ref('')
const searched = ref(false)
const inputEl  = ref(null)
let   seq      = 0

async function run() {
  const query = q.value.trim()
  if (!query) return
  const my = ++seq
  loading.value = true
  error.value   = ''
  try {
    const res = await searchWikidata(query, locale.value)
    if (my === seq) { results.value = res; searched.value = true }
  } catch {
    if (my === seq) error.value = t('place.wikidata.error')
  } finally {
    if (my === seq) loading.value = false
  }
}

// Beim Öffnen gleich nach dem Ortsnamen suchen
watch(() => props.visible, async v => {
  if (!v) return
  q.value = props.query
  results.value = []
  searched.value = false
  run()
  await nextTick()
  inputEl.value?.$el?.focus()
})

// context: nächster Oberort zuerst – ein Treffer beim nächsten zählt mehr als einer bei „Bayern“
const ctx   = computed(() => props.context.map(c => c.toLowerCase()).filter(c => c.length > 2))
const score = r => {
  const d = r.description.toLowerCase()
  const i = ctx.value.findIndex(c => d.includes(c))
  return i < 0 ? 0 : ctx.value.length - i
}
const best   = computed(() => Math.max(0, ...results.value.map(score)))
const fits   = r => best.value > 0 && score(r) === best.value
const sorted = computed(() => results.value.map((r, i) => ({ r, i, s: score(r) }))
  .sort((a, b) => b.s - a.s || a.i - b.i).map(x => x.r))

function choose(r) {
  emit('select', r.id)
  emit('update:visible', false)
}
</script>

<template>
  <Dialog :visible="visible" modal :header="t('place.wikidata.searchTitle')" :style="{ width: '40rem' }"
    :breakpoints="{ '640px': '95vw' }" @update:visible="v => emit('update:visible', v)">
    <form class="wd-search" @submit.prevent="run">
      <InputText ref="inputEl" v-model="q" :placeholder="t('place.wikidata.searchPlaceholder')" fluid />
      <Button type="submit" icon="pi pi-search" :loading="loading" :aria-label="t('place.searchWikidata')" />
    </form>

    <div v-if="error" class="alert alert-error">{{ error }}</div>
    <p v-else-if="searched && !results.length" class="muted">{{ t('place.wikidata.noResults') }}</p>
    <ul v-else class="wd-results">
      <li v-for="r in sorted" :key="r.id">
        <button type="button" :class="['wd-result', { fits: fits(r) }]" @click="choose(r)">
          <span class="wd-result-main">
            <span class="wd-result-label">{{ r.label }}</span>
            <span class="wd-result-desc">{{ r.description || '–' }}</span>
          </span>
          <i v-if="fits(r)" class="pi pi-map-marker wd-result-fit" :title="t('place.wikidata.fits')" />
          <a :href="`https://www.wikidata.org/wiki/${r.id}`" target="_blank" rel="noopener" class="wd-result-id"
            :title="t('place.openWikidata')" @click.stop>{{ r.id }}</a>
        </button>
      </li>
    </ul>
    <p class="field-hint">{{ t('place.wikidata.searchHint') }}</p>
  </Dialog>
</template>

<style scoped>
.wd-search { display: flex; gap: .4rem; margin-bottom: .75rem; }
.wd-results { list-style: none; display: flex; flex-direction: column; gap: .3rem; max-height: 55vh; overflow-y: auto; margin-bottom: .5rem; }
.wd-result {
  display: flex; align-items: center; gap: .6rem; width: 100%; text-align: left; font: inherit; cursor: pointer;
  border: 1px solid var(--color-border); background: var(--color-surface); border-radius: 6px; padding: .45rem .65rem;
}
.wd-result:hover { border-color: var(--color-primary); background: var(--color-primary-soft); }
.wd-result.fits { border-color: var(--color-primary); }
.wd-result-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.wd-result-label { font-weight: 600; }
.wd-result-desc { font-size: .8rem; color: var(--color-muted); }
.wd-result-fit { color: var(--color-primary); font-size: .85rem; }
.wd-result-id { font-size: .75rem; color: var(--color-faint); font-variant-numeric: tabular-nums; }
.wd-result-id:hover { color: var(--color-primary); }
</style>
