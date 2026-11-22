<script setup>
import { computed, watch } from 'vue'
import { fetchAncestorReport } from '../../api/reports.js'
import { useReportData, nameLabel, roman } from './reportFormat.js'
import FactList from './FactList.vue'
import SymbolLegend from './SymbolLegend.vue'
import { t } from '../../i18n/index.js'

/**
 * Ahnentafel nach Kekule: 1 = Ausgangsperson, Vater = 2n, Mutter = 2n + 1, je Generation ein Abschnitt.
 * Wer mehrfach vorkommt (Ahnenschwund), steht nur einmal mit Daten, sonst „siehe Nr. …“.
 */
const props = defineProps({ params: { type: Object, required: true } })
const emit  = defineEmits(['title'])

const { data, error } = useReportData(() => fetchAncestorReport(props.params.person, props.params.gen))
const person = id => data.value?.persons?.[id]

watch(data, d => d && emit('title', t('reports.titles.ancestors', { name: nameLabel(person(d.root)) })))

const generations = computed(() => {
  const groups = new Map()
  for (const e of data.value?.entries ?? []) {
    if (!groups.has(e.gen)) groups.set(e.gen, [])
    groups.get(e.gen).push(e)
  }
  return [...groups].map(([gen, entries]) => ({ gen, entries, possible: 2 ** (gen - 1) }))
})

/** Ahnenschwund: Anzahl verschiedener Personen gegenüber Einträgen */
const implex = computed(() => (data.value?.entries ?? []).filter(e => e.same_as).length)

/** „III. Generation – Großeltern“ (römisch wie in gedruckten Ahnenlisten) */
function genTitle(gen) {
  const key  = 'reports.generationNames.' + gen
  const name = t(key)
  const head = t('reports.generation', { n: roman(gen) })
  return name !== key ? head + ' – ' + name : head
}
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <template v-else>
    <p class="report-summary">
      {{ t('reports.ancestorSummary', { n: data.entries.length - 1, gens: generations.length }) }}
      <template v-if="implex"> · {{ t('reports.implex', { n: implex }) }}</template>
    </p>
    <section v-for="g in generations" :key="g.gen" class="report-section">
      <h2>{{ genTitle(g.gen) }} <small v-if="g.gen > 1">{{ t('reports.found', { n: g.entries.length, of: g.possible }) }}</small></h2>
      <div class="report-entries">
        <article v-for="e in g.entries" :key="e.n" class="report-entry">
          <div class="report-num">{{ e.n }}</div>
          <div>
            <div class="report-name">{{ nameLabel(person(e.person_id)) }}</div>
            <p v-if="e.same_as" class="report-facts muted">{{ t('reports.seeNumber', { n: e.same_as }) }}</p>
            <FactList v-else :person="person(e.person_id)" :marriage="data.marriages[e.n]" />
          </div>
        </article>
      </div>
    </section>
    <SymbolLegend />
  </template>
</template>
